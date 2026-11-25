<?php

declare(strict_types=1);

use App\Domain\Reservations\NoShowClosingGuard;
use App\Domain\Reservations\NoShowRefundEligibility;
use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Finance\FinancialJournalPostingService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function noShowActor(Hotel $hotel, string $role): User
{
    $user = User::create([
        'name' => ucfirst($role).' QA',
        'email' => 'no-show-'.bin2hex(random_bytes(8)).'@example.test',
        'password' => 'SyntheticNoShowPassword123!',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true,
    ]);

    return $user;
}

function noShowSignIn(User $actor): void
{
    Sanctum::actingAs($actor, ['hotel:read', 'hotel:write', 'booking:write']);
}

function noShowFixture($test, ?string $kind = 'fixed', int $value = 200000, bool $priced = true): array
{
    $test->travelTo(new DateTimeImmutable('2026-11-20 10:00 Asia/Manila'));
    $hotel = Hotel::create(['name' => 'No-show integration', 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
    $author = noShowActor($hotel, 'admin');
    $reviewer = noShowActor($hotel, 'admin');
    noShowSignIn($author);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '101']);
    $guest = $hotel->guests()->create(['first_name' => 'Demo', 'last_name' => 'Guest']);
    $base = '/api/v1/hotels/'.$hotel->id;
    $payload = [
        'idempotencyKey' => 'no-show-guest-001', 'guestId' => $guest->id, 'roomTypeId' => $type->id,
        'checkIn' => '2026-11-21', 'checkOut' => '2026-11-23',
    ];
    if ($priced) {
        $payload['ratePlanId'] = DB::table('room_rate_plans')->insertGetId([
            'hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'name' => 'Base rate',
            'nightly_minor' => 300000, 'currency' => 'PHP', 'created_by' => $author->id,
        ]);
    }
    $booking = (int) $test->postJson($base.'/bookings', $payload)->assertCreated()->json('data.id');
    $test->patchJson($base.'/bookings/'.$booking.'/assign-room', ['roomId' => $room->id])->assertOk();
    if ($kind !== null && $priced) {
        DB::table('booking_pricing_snapshots')->insert([
            'hotel_id' => $hotel->id, 'reservation_record_id' => $booking, 'quote_token' => str_repeat('a', 64),
            'snapshot' => json_encode(['policyTerms' => ['version' => 1, 'noShowKind' => $kind, 'noShowValue' => $value]]),
            'created_by' => $author->id, 'created_at' => now(),
        ]);
    }

    $test->postJson($base.'/finance/business-day/initialize', ['businessDate' => '2026-11-20'])->assertCreated();
    DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->update(['business_date' => '2026-11-22']);
    DB::table('financial_day_closes')->insert([
        'hotel_id' => $hotel->id, 'business_date' => '2026-11-21', 'status' => 'completed',
        'closed_by' => $author->id, 'closed_at' => now(), 'reconciliation_snapshot' => '{}',
    ]);
    config()->set([
        'booking.no_show_adjustments_enabled' => true,
        'booking.no_show_adjustments_hotel_ids' => (string) $hotel->id,
        'financial_accounting.enable_verified_settlement' => true,
        'financial_accounting.enable_live_folio_tax' => false,
    ]);
    $folio = DB::table('reservation_folios')->where('reservation_record_id', $booking)->value('id');
    $path = $base.'/bookings/'.$booking;

    return [$hotel, $author, $reviewer, $booking, $folio, $base, $path, $payload];
}

function noShowPayment(Hotel $hotel, int $folio, User $actor, User $reviewer, int $amount, int $sequence = 1): int
{
    $payment = DB::table('folio_payment_records')->insertGetId([
        'hotel_id' => $hotel->id, 'folio_id' => $folio, 'request_key' => 'deposit-'.$sequence,
        'payload_sha256' => str_repeat('b', 64), 'method' => 'bank_transfer', 'reference' => 'BANK-QA-'.$sequence,
        'amount_minor' => $amount, 'status' => 'staff_confirmed', 'recorded_by' => $actor->id,
        'reviewed_by' => $reviewer->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('folio_settlement_verifications')->insert([
        'hotel_id' => $hotel->id, 'folio_id' => $folio, 'payment_record_id' => $payment,
        'evidence_kind' => 'bank_statement', 'evidence_reference' => 'BANK-QA-'.$sequence,
        'status' => 'verified', 'prepared_by' => $actor->id, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(),
    ]);

    return $payment;
}

function noShowApply($test, string $path, array $extra = []): string
{
    $review = $path.'/no-show-financial-review';
    $fingerprint = $test->getJson($review)->assertOk()->json('data.reviewFingerprint');

    return $test->postJson($review, $extra + [
        'reviewFingerprint' => $fingerprint, 'reason' => 'Reviewed the original booking agreement.',
    ])->assertCreated()->json('data.reviewId');
}

function noShowApprove($test, string $path, string $review, string $tax = 'non_vat')
{
    return $test->postJson($path.'/no-show-financial-review/'.$review.'/approve', [
        'taxClassification' => $tax, 'taxBasis' => 'Classification documented for this test property.',
    ]);
}

it('enforces the local arrival cutoff, permits reception and releases a room once', function (): void {
    [$hotel, , , $booking, , $base, $path, $payload] = noShowFixture($this, priced: false);
    $desk = noShowActor($hotel, 'front_desk');
    noShowSignIn($desk);
    $this->travelTo(new DateTimeImmutable('2026-11-22 05:59 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertConflict();
    $this->travelTo(new DateTimeImmutable('2026-11-22 06:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk()->assertJsonPath('data.financialReviewRequired', false);
    $this->postJson($path.'/no-show')->assertConflict();
    $this->getJson($path.'/history')->assertOk()->assertJsonPath('data.0.eventType', 'no_show')
        ->assertJsonPath('data.0.actorName', $desk->name)->assertJsonStructure(['data' => [['createdAt']]]);
    $this->getJson($base.'/reservation-records?filter=no_show')->assertOk()
        ->assertJsonPath('data.0.id', (string) $booking)->assertJsonPath('data.0.lifecycleState', 'no_show');
    $this->postJson($base.'/bookings', array_replace($payload, [
        'idempotencyKey' => 'no-show-rebook-001', 'checkIn' => '2026-11-22',
    ]))->assertCreated();
    expect(NoShowClosingGuard::unresolved($hotel->id, '2026-11-22'))->toBe(0);
});

it('preserves snapshotted policy and original folio while reconciling deposits', function (
    string $kind, int $value, int $penalty, int $deposit, int $due, int $refundable
): void {
    [$hotel, $author, $reviewer, $booking, $folio, $base, $path] = noShowFixture($this, $kind, $value);
    if ($deposit > 0) {
        noShowPayment($hotel, $folio, $author, $reviewer, $deposit);
    }
    $before = DB::table('folio_entries')->where('folio_id', $folio)->orderBy('id')->get()->all();
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk()->assertJsonPath('data.financialReviewRequired', true);
    $this->getJson($base.'/finance/business-day')->assertOk()->assertJsonPath('data.unresolvedNoShowBookings.0.id', (string) $booking);
    $reviewPath = $path.'/no-show-financial-review';
    $this->getJson($reviewPath)->assertOk()->assertJsonPath('data.proposedRoomCreditMinor', 600000)
        ->assertJsonPath('data.proposedNoShowFeeMinor', $penalty);
    $id = noShowApply($this, $path);
    noShowApprove($this, $path, $id)->assertConflict();
    noShowSignIn($reviewer);
    noShowApprove($this, $path, $id, 'vat_standard')->assertCreated()->assertJsonPath('data.grossMinor', $penalty);
    noShowApprove($this, $path, $id)->assertConflict();
    $this->getJson($base.'/finance/business-day')->assertOk()->assertJsonPath('data.unresolvedNoShowCount', 0);
    $result = app(NoShowRefundEligibility::class)->review($hotel, $booking);
    expect($result['remainingCollectionMinor'])->toBe($due)
        ->and($result['maximumRefundRequestMinor'])->toBe($refundable)
        ->and(DB::table('folio_entries')->where('folio_id', $folio)->orderBy('id')->get()->all())->toEqual($before)
        ->and(DB::table('financial_day_closes')->where('hotel_id', $hotel->id)->count())->toBe(1);
})->with([
    'fixed with no deposit' => ['fixed', 200000, 200000, 0, 200000, 0],
    'fixed partially covered' => ['fixed', 200000, 200000, 100000, 100000, 0],
    'fixed fully covered' => ['fixed', 200000, 200000, 200000, 0, 0],
    'fixed with excess' => ['fixed', 200000, 200000, 300000, 0, 100000],
    'no penalty with excess' => ['none', 0, 0, 300000, 0, 300000],
    'first night' => ['first_night', 0, 300000, 0, 300000, 0],
    'full room charges' => ['full', 0, 600000, 0, 600000, 0],
    'percentage' => ['percent', 3333, 199980, 0, 199980, 0],
]);

it('requires a manual fee for a legacy booking and rejects stale previews and unauthorized writes', function (): void {
    [$hotel, $author, $reviewer, , $folio, , $path] = noShowFixture($this, null);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    $review = $path.'/no-show-financial-review';
    $preview = $this->getJson($review)->assertOk()->assertJsonPath('data.canApplyManual', true)->json('data');
    noShowSignIn(noShowActor($hotel, 'front_desk'));
    $this->getJson($review)->assertForbidden();
    noShowSignIn($author);
    noShowPayment($hotel, $folio, $author, $reviewer, 100000);
    $this->postJson($review, [
        'reviewFingerprint' => $preview['reviewFingerprint'], 'manualFeeMinor' => 0,
        'reason' => 'Prior deposit changed the terms of this review.',
    ])->assertConflict();
    expect(DB::table('folio_adjustments')->where('folio_id', $folio)->count())->toBe(0);
    config()->set('booking.no_show_adjustments_hotel_ids', '999999');
    $this->getJson($review)->assertOk()->assertJsonPath('data.canApplyManual', false);
    config()->set('booking.no_show_adjustments_hotel_ids', (string) $hotel->id);
    $id = noShowApply($this, $path, ['manualFeeMinor' => 0]);
    noShowSignIn($reviewer);
    noShowApprove($this, $path, $id)->assertCreated()->assertJsonPath('data.journalId', null);
});

it('reopens a blocker for reversed journals and records a replacement clearance', function (): void {
    [$hotel, , $reviewer, , , , $path] = noShowFixture($this);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    $review = noShowApply($this, $path);
    noShowSignIn($reviewer);
    $journal = (int) noShowApprove($this, $path, $review)->assertCreated()->json('data.journalId');
    app(FinancialJournalPostingService::class)->post($hotel->id, 'qa-no-show-reversal',
        'reversal', 'qa-reversal', 'Reverse test fee', [
            ['account_code' => 'AR', 'debit_minor' => 0, 'credit_minor' => 200000],
            ['account_code' => 'NO_SHOW_REV', 'debit_minor' => 200000, 'credit_minor' => 0],
        ], $reviewer->id, $journal);
    expect(NoShowClosingGuard::unresolved($hotel->id, '2026-11-22'))->toBe(1);
    $replacement = (int) noShowApprove($this, $path, $review)->assertCreated()->json('data.journalId');
    expect($replacement)->not->toBe($journal)
        ->and(NoShowClosingGuard::unresolved($hotel->id, '2026-11-22'))->toBe(0)
        ->and(DB::table('no_show_financial_clearances')->where('review_id', $review)->count())->toBe(2);
});

it('rejects no-show transitions from occupied or completed stays', function (string $state): void {
    [, , , $booking, , , $path] = noShowFixture($this);
    DB::table('reservation_records')->where('id', $booking)->update(['lifecycle_state' => $state]);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertConflict();
    expect(DB::table('reservation_records')->where('id', $booking)->value('lifecycle_state'))->toBe($state);
})->with(['checked_in', 'checked_out']);

it('caps multiple refunds and rechecks evidence through independent disbursement review', function (): void {
    [$hotel, $author, $reviewer, $booking, $folio, , $path] = noShowFixture($this);
    $payment = noShowPayment($hotel, $folio, $author, $reviewer, 180000);
    $otherPayment = noShowPayment($hotel, $folio, $author, $reviewer, 120000, 2);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    $review = noShowApply($this, $path);
    noShowSignIn($reviewer);
    noShowApprove($this, $path, $review)->assertCreated();

    noShowSignIn($author);
    $refunds = $path.'/refund-requests';
    $request = [
        'requestKey' => 'no-show-refund-first', 'paymentRecordId' => $payment,
        'amountMinor' => 100000, 'reason' => 'Return the excess verified deposit.',
    ];
    $refund = $this->postJson($refunds, $request)->assertCreated()->json('data.id');
    $this->postJson($refunds, $request)->assertOk()->assertJsonPath('data.id', $refund);
    $this->postJson($refunds, array_replace($request, [
        'requestKey' => 'no-show-refund-second', 'paymentRecordId' => $otherPayment, 'amountMinor' => 1,
    ]))->assertConflict();
    $reviewPayload = ['decision' => 'approve', 'note' => 'Checked independently against the original deposit.'];
    $this->postJson($refunds.'/'.$refund.'/review', $reviewPayload)->assertForbidden();
    noShowSignIn($reviewer);
    $this->postJson($refunds.'/'.$refund.'/review', $reviewPayload)->assertOk();

    $recordPath = $refunds.'/'.$refund.'/disbursement-evidence';
    $evidence = ['externalReference' => 'SYNTHETIC-REFUND-201'];
    $originalEvidence = DB::table('folio_settlement_verifications')->where('payment_record_id', $otherPayment);
    $originalEvidence->update(['status' => 'rejected']);
    $this->postJson($recordPath, $evidence)->assertUnprocessable();
    $originalEvidence->update(['status' => 'verified']);
    $evidenceId = $this->postJson($recordPath, $evidence)->assertCreated()
        ->assertJsonPath('data.fundsSentByPms', false)->json('data.id');

    $verifyPath = $path.'/refund-disbursements/'.$evidenceId.'/review';
    $verify = ['decision' => 'verify', 'note' => 'Checked external refund receipt separately.'];
    $this->postJson($verifyPath, $verify)->assertUnprocessable();
    noShowSignIn($author);
    $this->postJson($verifyPath, $verify)->assertOk()->assertJsonPath('data.fundsSentByPms', false);
    expect(app(NoShowRefundEligibility::class)->review($hotel, $booking)['maximumRefundRequestMinor'])->toBe(0);

    $entry = DB::table('folio_entries')->where('folio_id', $folio)->value('id');
    $allocation = $path.'/payment-records/'.$payment.'/allocations';
    $input = ['requestKey' => 'no-show-allocation-first', 'payerKind' => 'guest', 'payerReference' => 'Demo guest', 'amountMinor' => 80000];
    $this->postJson($allocation, $input + ['folioEntryId' => $entry])->assertUnprocessable();
    $this->postJson($allocation, $input)->assertSuccessful();
    $this->postJson($path.'/payment-records/'.$otherPayment.'/allocations', array_replace($input, [
        'requestKey' => 'no-show-allocation-second', 'amountMinor' => 120000,
    ]))->assertSuccessful();
    expect(app(NoShowRefundEligibility::class)->review($hotel, $booking)['settled'])->toBeTrue();
});

it('rolls back the entire review on a database conflict after the reversal insert', function (): void {
    [$hotel, $author, , $booking, $folio, , $path] = noShowFixture($this);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    $otherBooking = DB::table('reservation_records')->insertGetId([
        'hotel_id' => $hotel->id, 'room_type_id' => DB::table('room_types')->where('hotel_id', $hotel->id)->value('id'),
        'reference' => 'NS-QA-SECOND', 'check_in' => '2026-12-02', 'check_out' => '2026-12-03',
    ]);
    $otherFolio = DB::table('reservation_folios')->insertGetId([
        'hotel_id' => $hotel->id, 'reservation_record_id' => $otherBooking,
        'currency' => 'PHP', 'created_by' => $author->id,
    ]);
    DB::table('folio_adjustments')->insert([
        'hotel_id' => $hotel->id, 'folio_id' => $otherFolio, 'actor_id' => $author->id,
        'request_key' => 'no-show-'.$booking.'-debit', 'payload_sha256' => str_repeat('c', 64),
        'kind' => 'debit', 'amount_minor' => 100, 'reason' => 'Synthetic key collision',
    ]);
    $review = $path.'/no-show-financial-review';
    $fingerprint = $this->getJson($review)->assertOk()->json('data.reviewFingerprint');
    $this->postJson($review, [
        'reviewFingerprint' => $fingerprint, 'reason' => 'Reviewed the original booking agreement.',
    ])->assertServerError();
    expect(DB::table('folio_adjustments')->where('folio_id', $folio)->count())->toBe(0)
        ->and(DB::table('no_show_financial_reviews')->where('folio_id', $folio)->count())->toBe(0);
});

it('protects signed guest accounts and isolates no-show records by hotel', function (): void {
    [$hotel, $author, , $booking, $folio, , $path] = noShowFixture($this);
    DB::table('folio_settlement_signoffs')->insert([
        'hotel_id' => $hotel->id, 'folio_id' => $folio, 'reservation_record_id' => $booking,
        'approved_by' => $author->id, 'net_charges_minor' => 600000, 'verified_allocated_minor' => 600000,
        'snapshot_sha256' => hash('sha256', '{}'), 'statement_snapshot' => '{}', 'signed_at' => now(),
    ]);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertConflict();
    expect(DB::table('folio_settlement_signoffs')->where('folio_id', $folio)->value('statement_snapshot'))->toBe('{}')
        ->and(DB::table('reservation_records')->where('id', $booking)->value('lifecycle_state'))->toBe('confirmed');
    $other = Hotel::create(['name' => 'Independent QA property']);
    noShowSignIn(noShowActor($other, 'admin'));
    $this->postJson($path.'/no-show')->assertNotFound();
    $this->getJson('/api/v1/hotels/'.$other->id.'/bookings/'.$booking.'/no-show-financial-review')->assertNotFound();
});
