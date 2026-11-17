<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An independently attributable accounting review snapshot, never a day roll,
 * revenue recognition, VAT calculation, payment settlement, or ledger close.
 */
final class NightAuditReviewService
{
    public function show(Hotel $hotel, string $date): array
    {
        $row = DB::table('night_audit_reviews')
            ->where('hotel_id',$hotel->id)->where('business_date',$date)->first();
        return ['data' => $row === null ? null : $this->present($row)];
    }

    public function record(Hotel $hotel, int $actor, string $date, string $note, string $decision): array
    {
        return DB::transaction(function () use ($hotel,$actor,$date,$note,$decision): array {
            // Serializes with hotel-scoped booking/shift mutations that use this lock.
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $today = now($hotel->timezone ?: 'Asia/Manila')->toDateString();
            if ($date >= $today) {
                throw new HttpException(409, 'Only a completed prior hotel-local date may be reviewed.');
            }
            $previous = DB::table('night_audit_reviews')->where('hotel_id',$hotel->id)
                ->where('business_date',$date)->lockForUpdate()->first();
            if ($previous !== null) {
                throw new HttpException(409, 'This business date already has an immutable manager review.');
            }
            $snapshot = app(NightAuditPreviewService::class)->preview($hotel,$date)['data'];
            if ($decision === 'clear' && ! $snapshot['preflightClear']) {
                throw new HttpException(409, 'Resolve every listed preflight exception before marking reviewed.');
            }
            if ($decision === 'flag' && $snapshot['preflightClear']) {
                throw new HttpException(409, 'No listed preflight exceptions exist; use reviewed, not flagged.');
            }
            $canonical = json_encode($snapshot,JSON_THROW_ON_ERROR);
            $status = $decision === 'clear' ? 'reviewed' : 'flagged';
            $id = DB::table('night_audit_reviews')->insertGetId([
                'hotel_id'=>$hotel->id,
                'business_date'=>$date,
                'status'=>$status,
                'posted_room_charges_minor'=>$snapshot['postedRoomChargesMinor'],
                'scheduled_room_nights'=>$snapshot['scheduledRoomNights'],
                'priced_room_nights'=>$snapshot['pricedRoomNights'],
                'room_count'=>$snapshot['roomCount'],
                'currency'=>$snapshot['currency'],
                'snapshot_sha256'=>hash('sha256',$canonical),
                'snapshot_json'=>$canonical,
                'manager_note'=>$note,
                'reviewed_by'=>$actor,
                'reviewed_at'=>now(),
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);
            DB::table('night_audit_review_events')->insert([
                'hotel_id'=>$hotel->id,'night_audit_review_id'=>$id,
                'actor_id'=>$actor,'action'=>$status,'note'=>$note,'created_at'=>now(),
            ]);
            $row=DB::table('night_audit_reviews')->where('hotel_id',$hotel->id)->where('id',$id)->first();
            return ['data'=>$this->present($row)];
        },3);
    }

    private function present(object $row): array
    {
        $snapshot = json_decode($row->snapshot_json,true,512,JSON_THROW_ON_ERROR);
        if (! hash_equals($row->snapshot_sha256,hash('sha256',$row->snapshot_json))) {
            throw new HttpException(500, 'Night audit review snapshot checksum mismatch.');
        }
        return [
            'id'=>(string)$row->id,'hotelId'=>(string)$row->hotel_id,
            'businessDate'=>$row->business_date,'status'=>$row->status,
            'managerNote'=>$row->manager_note,'reviewedBy'=>(string)$row->reviewed_by,
            'reviewedAt'=>$row->reviewed_at,'snapshotSha256'=>$row->snapshot_sha256,
            'snapshot'=>$snapshot,
            'financialDayClosed'=>false,
            'ledgerLocked'=>false,
            'taxesCalculated'=>false,
            'gatewaySettlementVerified'=>false,
        ];
    }
}
