<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ReservationSpecialRequestController
{
    private const TAGS = [
        'late_arrival','quiet_room','extra_bedding','accessibility',
        'connecting_rooms','other',
    ];

    public function index(Request $request, Hotel $hotel, string $booking): JsonResponse
    {
        abort_unless(DB::table('reservation_records')->where('hotel_id',$hotel->id)
            ->where('id',$booking)->exists(),404);
        $page=DB::table('reservation_special_requests')
            ->where('hotel_id',$hotel->id)->where('reservation_record_id',$booking)
            ->orderByDesc('id')->paginate(30);
        return response()->json([
            'data'=>$page->getCollection()->map(fn ($row): array => $this->present($row))->values(),
            'meta'=>['hotelId'=>(string) $hotel->id,'bookingId'=>(string) $booking,
                'page'=>$page->currentPage(),'total'=>$page->total(),
                'totalPages'=>$page->lastPage()],
        ]);
    }

    public function store(Request $request, Hotel $hotel, string $booking): JsonResponse
    {
        $input=$request->validate([
            'idempotencyKey'=>['required','string','min:8','max:100'],
            'tag'=>['required','in:'.implode(',',self::TAGS)],
            'note'=>['required','string','min:5','max:1000'],
        ]);
        $note=trim($input['note']);
        if (mb_strlen($note)<5) {
            throw new HttpException(422,'Please provide a meaningful special request note.');
        }
        $fingerprint=hash('sha256',json_encode([
            'booking'=>(string)$booking,'tag'=>$input['tag'],'note'=>$note,
        ],JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request,$hotel,$booking,$input,$note,$fingerprint): JsonResponse {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            $existing=DB::table('reservation_special_requests')
                ->where('hotel_id',$hotel->id)->where('request_key',$input['idempotencyKey'])
                ->first();
            if ($existing !== null) {
                if ($existing->payload_sha256 !== $fingerprint) {
                    throw new HttpException(409,'This idempotency key was used for another special request.');
                }
                return response()->json(['data'=>$this->present($existing)],200);
            }
            $record=DB::table('reservation_records')->where('hotel_id',$hotel->id)
                ->where('id',$booking)->lockForUpdate()->first();
            abort_unless($record !== null,404);
            if (in_array($record->lifecycle_state,['cancelled','checked_out','no_show'],true)) {
                throw new HttpException(409,'Special requests are only accepted for active reservations.');
            }
            $id=DB::table('reservation_special_requests')->insertGetId([
                'hotel_id'=>$hotel->id,'reservation_record_id'=>$record->id,
                'request_key'=>$input['idempotencyKey'],'payload_sha256'=>$fingerprint,
                'tag'=>$input['tag'],'note'=>$note,'created_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $row=DB::table('reservation_special_requests')->where('hotel_id',$hotel->id)
                ->where('id',$id)->first();
            return response()->json(['data'=>$this->present($row)],201);
        },3);
    }

    private function present(object $row): array
    {
        return [
            'id'=>(string) $row->id,'bookingId'=>(string) $row->reservation_record_id,
            'tag'=>$row->tag,'note'=>$row->note,'createdBy'=>(string) $row->created_by,
            'createdAt'=>(string) $row->created_at,'isGuaranteed'=>false,
            'chargesAutomaticallyPosted'=>false,
        ];
    }
}
