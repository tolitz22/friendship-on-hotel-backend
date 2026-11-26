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
    expect($result['reconciliationValid'])->toBeTrue()
        ->and($result['netVerifiedFundsMinor'])->toBe($deposit)
        ->and($result['unallocatedVerifiedFundsMinor'])->toBe($deposit)
        ->and($result['unallocatedFeeMinor'])->toBe($penalty)
        ->and($result['pendingRefundsMinor'])->toBe(0)
        ->and($result['verifiedRefundsMinor'])->toBe(0)
        ->and($result['remainingCollectionMinor'])->toBe($due)
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
    $afterRefund = app(NoShowRefundEligibility::class)->review($hotel, $booking);
    expect($afterRefund['maximumRefundRequestMinor'])->toBe(0)
        ->and($afterRefund['verifiedRefundsMinor'])->toBe(100000)
        ->and($afterRefund['pendingRefundsMinor'])->toBe(0)
        ->and($afterRefund['netVerifiedFundsMinor'])->toBe(200000)
        ->and($afterRefund['unallocatedVerifiedFundsMinor'])->toBe(200000);
    $replayedReview = app(NoShowRefundEligibility::class)->review($hotel, $booking, (int) $refund);
    expect($replayedReview['maximumRefundRequestMinor'])->toBe(0)
        ->and($replayedReview['reservedRefundsMinor'])->toBe(100000)
        ->and($replayedReview['settled'])->toBeFalse();

    $entry = DB::table('folio_entries')->where('folio_id', $folio)->value('id');
    $allocation = $path.'/payment-records/'.$payment.'/allocations';
    $input = ['requestKey' => 'no-show-allocation-first', 'payerKind' => 'guest', 'payerReference' => 'Demo guest', 'amountMinor' => 80000];
    $this->postJson($allocation, $input + ['folioEntryId' => $entry])->assertUnprocessable();
    $this->postJson($allocation, $input)->assertSuccessful();
    $this->postJson($path.'/payment-records/'.$otherPayment.'/allocations', array_replace($input, [
        'requestKey' => 'no-show-allocation-second', 'amountMinor' => 120000,
    ]))->assertSuccessful();
    $settled = app(NoShowRefundEligibility::class)->review($hotel, $booking);
    expect($settled['settled'])->toBeTrue()
        ->and($settled['reconciliationValid'])->toBeTrue()
        ->and($settled['unallocatedFeeMinor'])->toBe(0)
        ->and($settled['unallocatedVerifiedFundsMinor'])->toBe(0);
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

it('posts a verified bank deposit to liability exactly once', function (): void {
    [$hotel, $author, $reviewer, $booking, $folio, , $path] = noShowFixture($this);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    $payment = noShowPayment($hotel, $folio, $author, $reviewer, 300000);
    config()->set('financial_accounting.enable_guest_deposit_posting', true);
    $service = app(\App\Services\Finance\GuestDepositJournalService::class);
    $journal = $service->recognize($hotel, $booking, $payment, $reviewer->id);
    expect($service->recognize($hotel, $booking, $payment, $reviewer->id))->toBe($journal);
    $header = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->where('id', $journal)->first();
    expect($header->source_type)->toBe('guest_deposit_receipt')
        ->and($header->source_reference)->toBe('folio-payment-'.$payment)
        ->and(DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
            ->where('source_type', 'guest_deposit_receipt')->count())->toBe(1);
    $lines = DB::table('financial_posting_lines')->where('journal_id', $journal)->orderBy('line_number')->get();
    expect($lines->count())->toBe(2)
        ->and($lines[0]->account_code)->toBe('hotel_bank')
        ->and((int) $lines[0]->debit_minor)->toBe(300000)
        ->and((int) $lines[0]->credit_minor)->toBe(0)
        ->and($lines[1]->account_code)->toBe('guest_deposit_liability')
        ->and((int) $lines[1]->credit_minor)->toBe(300000)
        ->and((int) $lines[1]->debit_minor)->toBe(0);
});

it('rejects unsafe staged deposit postings without creating a journal', function (string $scenario, int $status): void {
    [$hotel, $author, $reviewer, $booking, $folio, , $path] = noShowFixture($this);
    if ($scenario !== 'historical') {
        $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    }
    $payment = noShowPayment($hotel, $folio, $author, $reviewer, 300000);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    config()->set('financial_accounting.enable_guest_deposit_posting', true);
    $actor = $reviewer->id;
    switch ($scenario) {
        case 'disabled':
            config()->set('financial_accounting.enable_guest_deposit_posting', false);
            break;
        case 'settlement disabled':
            config()->set('financial_accounting.enable_verified_settlement', false);
            break;
        case 'outside allowlist':
            config()->set('booking.no_show_adjustments_hotel_ids', '');
            break;
        case 'unverified':
            DB::table('folio_settlement_verifications')->where('payment_record_id', $payment)
                ->update(['status' => 'pending']);
            break;
        case 'self verified':
            DB::table('folio_settlement_verifications')->where('payment_record_id', $payment)
                ->update(['reviewed_by' => $author->id]);
            break;
        case 'unauthorized':
            $actor = noShowActor($hotel, 'front_desk')->id;
            break;
        case 'cross hotel actor':
            $other = Hotel::create(['name' => 'Other deposit property']);
            $actor = noShowActor($other, 'admin')->id;
            break;
        case 'closed date':
            DB::table('financial_day_closes')->insert([
                'hotel_id' => $hotel->id, 'business_date' => '2026-11-22', 'status' => 'completed',
                'closed_by' => $author->id, 'closed_at' => now(), 'reconciliation_snapshot' => '{}',
            ]);
            break;
    }

    try {
        app(\App\Services\Finance\GuestDepositJournalService::class)->recognize($hotel, $booking, $payment, $actor);
        $this->fail('Unsafe deposit posting was accepted.');
    } catch (\Illuminate\Validation\ValidationException $exception) {
        expect($status)->toBe(422);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
        expect($exception->getStatusCode())->toBe($status);
    }
    expect(DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
        ->where('source_type', 'guest_deposit_receipt')->count())->toBe(0);
})->with([
    'disabled' => ['disabled', 409],
    'settlement disabled' => ['settlement disabled', 409],
    'outside allowlist' => ['outside allowlist', 409],
    'unverified' => ['unverified', 409],
    'self verified' => ['self verified', 409],
    'unauthorized' => ['unauthorized', 403],
    'cross hotel actor' => ['cross hotel actor', 403],
    'historical' => ['historical', 409],
    'closed date' => ['closed date', 422],
]);

it('documents a historical deposit with independent approval and no journal posting', function (): void {
    [$hotel, $author, $reviewer, $booking, $folio, , $path] = noShowFixture($this);
    $payment = noShowPayment($hotel, $folio, $author, $reviewer, 300000);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    config()->set('financial_accounting.enable_guest_deposit_posting', true);
    $preview = app(\App\Services\Finance\HistoricalGuestDepositReviewService::class)->review($hotel, $booking, $author->id);
    expect($preview['canPost'])->toBeFalse()
        ->and($preview['openingBalanceMinor'])->toBeNull()
        ->and($preview['historicalPayments'][0]['reviewStatus'])->toBe('opening_balance_review_required');
    $service = app(\App\Services\Finance\HistoricalGuestDepositDecisionService::class);
    $before = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->count();
    $id = $service->propose($hotel, $booking, $payment, $author->id, 'OPENING-QA-001', 'Matched original bank receipt to opening balance evidence.');
    $approval = $service->approve($hotel, $id, $reviewer->id, 'Independently checked the opening balance evidence.');
    $decision = DB::table('historical_guest_deposit_decisions')->find($id);
    expect((int) $decision->amount_minor)->toBe(300000)
        ->and($decision->snapshot_sha256)->toBe(hash('sha256', $decision->snapshot))
        ->and((int) DB::table('historical_guest_deposit_approvals')->where('id', $approval)->value('reviewed_by'))->toBe((int) $reviewer->id)
        ->and(DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->count())->toBe($before);
});

it('rejects unsafe historical deposit decisions without posting money', function (string $scenario, int $status): void {
    [$hotel, $author, $reviewer, $booking, $folio, , $path] = noShowFixture($this);
    $payment = noShowPayment($hotel, $folio, $author, $reviewer, 300000);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    config()->set('financial_accounting.enable_guest_deposit_posting', true);
    $service = app(\App\Services\Finance\HistoricalGuestDepositDecisionService::class);
    $propose = fn (int $actor) => $service->propose($hotel, $booking, $payment, $actor,
        'OPENING-QA-002', 'Matched original bank receipt to opening balance evidence.');
    $reason = 'Independently checked the opening balance evidence.';
    $before = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->count();
    $approvedCount = 0;
    try {
        switch ($scenario) {
            case 'disabled':
                config()->set('financial_accounting.enable_guest_deposit_posting', false);
                $propose($author->id);
                break;
            case 'unauthorized':
                $propose(noShowActor($hotel, 'front_desk')->id);
                break;
            case 'cross hotel':
                $other = Hotel::create(['name' => 'Other historical deposit property']);
                $propose(noShowActor($other, 'admin')->id);
                break;
            default:
                $id = $propose($author->id);
                switch ($scenario) {
                    case 'duplicate proposal':
                        $propose($author->id);
                        break;
                    case 'self approval':
                        $service->approve($hotel, $id, $author->id, $reason);
                        break;
                    case 'duplicate approval':
                        $service->approve($hotel, $id, $reviewer->id, $reason);
                        $approvedCount = 1;
                        $service->approve($hotel, $id, $reviewer->id, $reason);
                        break;
                    case 'changed evidence':
                        DB::table('folio_settlement_verifications')->where('payment_record_id', $payment)
                            ->update(['evidence_reference' => 'BANK-QA-CHANGED']);
                        $service->approve($hotel, $id, $reviewer->id, $reason);
                        break;
                    case 'changed amount':
                        DB::table('folio_payment_records')->where('id', $payment)->update(['amount_minor' => 200000]);
                        $service->approve($hotel, $id, $reviewer->id, $reason);
                        break;
                    case 'closed date':
                        DB::table('financial_day_closes')->insert([
                            'hotel_id' => $hotel->id, 'business_date' => '2026-11-22', 'status' => 'completed',
                            'closed_by' => $author->id, 'closed_at' => now(), 'reconciliation_snapshot' => '{}',
                        ]);
                        $service->approve($hotel, $id, $reviewer->id, $reason);
                        break;
                }
        }
        $this->fail('Unsafe historical deposit decision was accepted.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
        expect($exception->getStatusCode())->toBe($status);
    }
    expect(DB::table('historical_guest_deposit_approvals')->count())->toBe($approvedCount)
        ->and(DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->count())->toBe($before);
})->with([
    'disabled' => ['disabled', 409],
    'unauthorized' => ['unauthorized', 403],
    'cross hotel' => ['cross hotel', 403],
    'duplicate proposal' => ['duplicate proposal', 409],
    'self approval' => ['self approval', 403],
    'duplicate approval' => ['duplicate approval', 409],
    'changed evidence' => ['changed evidence', 409],
    'changed amount' => ['changed amount', 409],
    'closed date' => ['closed date', 409],
]);

it('reclassifies approved historical deposits without recognizing cash twice', function (string $scenario): void {
    [$hotel, $author, $reviewer, $booking, $folio, , $path] = noShowFixture($this);
    $payment = noShowPayment($hotel, $folio, $author, $reviewer, 300000);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    config()->set([
        'financial_accounting.enable_guest_deposit_posting' => true,
        'financial_accounting.enable_historical_guest_deposit_posting' => true,
    ]);
    // Synthetic imported opening trial balance; not a new cash receipt.
    $amount = $scenario === 'mismatched amount' ? 200000 : 300000;
    $source = app(FinancialJournalPostingService::class)->post(
        $hotel->id, 'qa-opening-'.$payment, 'guest_deposit_opening_import',
        'opening-payment-'.$payment, 'Synthetic opening balance', [
            ['account_code' => 'opening_balance_equity', 'debit_minor' => $amount, 'credit_minor' => 0],
            ['account_code' => 'guest_deposit_opening_clearing', 'debit_minor' => 0, 'credit_minor' => $amount],
        ], $author->id
    );
    $service = app(\App\Services\Finance\HistoricalGuestDepositDecisionService::class);
    $reference = 'opening-journal-'.($scenario === 'wrong reference' ? $source + 1 : $source);
    $id = $service->propose($hotel, $booking, $payment, $author->id, $reference, 'Reconciled synthetic opening balance evidence.');
    if ($scenario !== 'unapproved') {
        $service->approve($hotel, $id, $reviewer->id, 'Independently checked synthetic opening balance.');
    }
    if ($scenario === 'disabled') {
        config()->set('financial_accounting.enable_historical_guest_deposit_posting', false);
    }
    if ($scenario === 'stale evidence') {
        DB::table('folio_settlement_verifications')->where('payment_record_id', $payment)
            ->update(['evidence_reference' => 'BANK-QA-STALE']);
    }
    if ($scenario === 'reversed source') {
        app(FinancialJournalPostingService::class)->post(
            $hotel->id, 'qa-opening-reversal-'.$payment, 'reversal', 'qa-reversal-'.$payment,
            'Reverse synthetic opening balance', [
                ['account_code' => 'opening_balance_equity', 'debit_minor' => 0, 'credit_minor' => $amount],
                ['account_code' => 'guest_deposit_opening_clearing', 'debit_minor' => $amount, 'credit_minor' => 0],
            ], $reviewer->id, $source
        );
    }
    $actor = $scenario === 'wrong actor' ? $author->id : $reviewer->id;
    $journals = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
        ->where('source_type', 'guest_deposit_opening_reclassification');
    if ($scenario !== 'success') {
        try {
            $service->postOpeningBalance($hotel, $id, $source, $actor);
            $this->fail('Unsafe historical opening posting accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            expect($exception->getStatusCode())->toBe(in_array($scenario, ['unapproved', 'wrong actor'], true) ? 403 : 409);
        }
        expect($journals->count())->toBe(0);
        return;
    }
    $journal = $service->postOpeningBalance($hotel, $id, $source, $actor);
    expect($service->postOpeningBalance($hotel, $id, $source, $actor))->toBe($journal)
        ->and($journals->count())->toBe(1);
    $lines = DB::table('financial_posting_lines')->where('journal_id', $journal)->orderBy('line_number')->get();
    expect($lines->count())->toBe(2)
        ->and($lines[0]->account_code)->toBe('guest_deposit_opening_clearing')
        ->and((int) $lines[0]->debit_minor)->toBe(300000)
        ->and((int) $lines[0]->credit_minor)->toBe(0)
        ->and($lines[1]->account_code)->toBe('guest_deposit_liability')
        ->and((int) $lines[1]->credit_minor)->toBe(300000)
        ->and((int) $lines[1]->debit_minor)->toBe(0);
})->with(['success', 'disabled', 'unapproved', 'wrong actor', 'wrong reference', 'mismatched amount', 'stale evidence', 'reversed source']);

it('recognizes only independently reconciled cash deposits without changing drawer records', function (string $scenario): void {
    [$hotel, $author, $reviewer, $booking, $folio, , $path] = noShowFixture($this);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    $payment = noShowPayment($hotel, $folio, $author, $reviewer, 300000);
    DB::table('folio_payment_records')->where('id', $payment)->update(['method' => 'cash']);
    $shift = DB::table('cashier_shifts')->insertGetId([
        'hotel_id' => $hotel->id, 'cashier_id' => $author->id, 'currency' => 'PHP',
        'status' => $scenario === 'unreconciled' ? 'submitted' : 'reconciled',
        'opening_float_minor' => 50000, 'expected_cash_minor' => 350000,
        'counted_cash_minor' => $scenario === 'variance' ? 349000 : 350000,
        'variance_minor' => $scenario === 'variance' ? -1000 : 0,
        'reviewed_by' => $scenario === 'self reconciled' ? $author->id : $reviewer->id,
        'opened_at' => now(), 'submitted_at' => now(), 'reconciled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    if ($scenario !== 'missing receipt') {
        DB::table('cashier_shift_receipts')->insert([
            'hotel_id' => $hotel->id, 'shift_id' => $shift, 'payment_id' => $payment,
            'reservation_record_id' => $booking,
            'amount_minor' => $scenario === 'wrong amount' ? 200000 : 300000,
            'cash_tendered_minor' => 350000, 'change_given_minor' => 50000,
            'net_receipt_minor' => 300000, 'created_at' => now(),
        ]);
    }
    DB::table('folio_settlement_verifications')->where('payment_record_id', $payment)->update([
        'evidence_kind' => 'cash_shift',
        'evidence_reference' => 'shift-'.($scenario === 'wrong reference' ? $shift + 1 : $shift),
    ]);
    config()->set([
        'financial_accounting.enable_guest_deposit_posting' => true,
        'financial_accounting.enable_cash_guest_deposit_posting' => $scenario !== 'disabled',
    ]);
    if ($scenario === 'existing journal') {
        app(FinancialJournalPostingService::class)->post($hotel->id, 'qa-existing-cash-'.$payment,
            'legacy_receipt', 'folio-payment-'.$payment, 'Synthetic existing cash receipt', [
                ['account_code' => 'cashier_cash', 'debit_minor' => 300000, 'credit_minor' => 0],
                ['account_code' => 'guest_deposit_liability', 'debit_minor' => 0, 'credit_minor' => 300000],
            ], $reviewer->id);
    }
    $beforeShift = DB::table('cashier_shifts')->find($shift);
    $beforeReceipts = DB::table('cashier_shift_receipts')->where('shift_id', $shift)->get()->all();
    $beforeEvents = DB::table('cashier_shift_events')->where('shift_id', $shift)->count();
    $service = app(\App\Services\Finance\GuestDepositJournalService::class);
    $actor = $scenario === 'unauthorized' ? noShowActor($hotel, 'front_desk')->id : $reviewer->id;
    $journals = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
        ->where('source_type', 'guest_deposit_receipt');
    if ($scenario === 'success') {
        $journal = $service->recognize($hotel, $booking, $payment, $actor);
        expect($service->recognize($hotel, $booking, $payment, $actor))->toBe($journal)
            ->and($journals->count())->toBe(1);
        $lines = DB::table('financial_posting_lines')->where('journal_id', $journal)->orderBy('line_number')->get();
        expect($lines->count())->toBe(2)
            ->and($lines[0]->account_code)->toBe('cashier_cash')
            ->and((int) $lines[0]->debit_minor)->toBe(300000)
            ->and((int) $lines[0]->credit_minor)->toBe(0)
            ->and($lines[1]->account_code)->toBe('guest_deposit_liability')
            ->and((int) $lines[1]->credit_minor)->toBe(300000)
            ->and((int) $lines[1]->debit_minor)->toBe(0);
    } else {
        try {
            $service->recognize($hotel, $booking, $payment, $actor);
            $this->fail('Unsafe cash deposit posting accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            expect($exception->getStatusCode())->toBe($scenario === 'unauthorized' ? 403 : 409);
        }
        expect($journals->count())->toBe(0);
    }
    expect(DB::table('cashier_shifts')->find($shift))->toEqual($beforeShift)
        ->and(DB::table('cashier_shift_receipts')->where('shift_id', $shift)->get()->all())->toEqual($beforeReceipts)
        ->and(DB::table('cashier_shift_events')->where('shift_id', $shift)->count())->toBe($beforeEvents);
})->with(['success', 'disabled', 'unreconciled', 'variance', 'self reconciled', 'missing receipt',
    'wrong amount', 'wrong reference', 'existing journal', 'unauthorized']);

it('posts no-show allocations atomically and rejects unsafe ledger states', function (string $scenario): void {
    [$hotel, $author, $reviewer, $booking, $folio, , $path] = noShowFixture($this);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    $payment = noShowPayment($hotel, $folio, $author, $reviewer, 200000);
    $review = noShowApply($this, $path);
    noShowSignIn($reviewer);
    noShowApprove($this, $path, $review)->assertCreated();
    config()->set('financial_accounting.enable_guest_deposit_posting', true);
    if ($scenario !== 'missing receipt') {
        $receipt = app(\App\Services\Finance\GuestDepositJournalService::class)
            ->recognize($hotel, $booking, $payment, $reviewer->id);
        if ($scenario === 'reversed receipt') {
            app(FinancialJournalPostingService::class)->post($hotel->id, 'qa-deposit-reverse-'.$payment,
                'reversal', 'qa-reverse-'.$payment, 'Reverse synthetic deposit', [
                    ['account_code' => 'hotel_bank', 'debit_minor' => 0, 'credit_minor' => 200000],
                    ['account_code' => 'guest_deposit_liability', 'debit_minor' => 200000, 'credit_minor' => 0],
                ], $reviewer->id, $receipt);
        }
    }
    $service = app(\App\Domain\Reservations\FolioSettlementService::class);
    $allocate = fn (string $key, int $amount) => $service->allocate($hotel, $booking, $payment,
        $reviewer->id, $key, 'guest', 'Demo guest', $amount, null);
    $allocations = DB::table('folio_settlement_allocations')->where('hotel_id', $hotel->id)->where('folio_id', $folio);
    $journals = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->where('source_type', 'no_show_deposit_allocation');
    config()->set('financial_accounting.enable_no_show_allocation_posting', $scenario !== 'legacy replay');
    if ($scenario === 'legacy replay') {
        $allocate('qa-allocation-first', 80000);
        config()->set('financial_accounting.enable_no_show_allocation_posting', true);
    }
    if ($scenario === 'closed date') {
        DB::table('financial_day_closes')->insert([
            'hotel_id' => $hotel->id, 'business_date' => '2026-11-22', 'status' => 'completed',
            'closed_by' => $author->id, 'closed_at' => now(), 'reconciliation_snapshot' => '{}',
        ]);
    }
    if ($scenario !== 'success') {
        $before = $allocations->count();
        try {
            $allocate('qa-allocation-first', 80000);
            $this->fail('Unsafe allocation accepted.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            expect(in_array($scenario, ['legacy replay', 'closed date'], true))->toBeTrue();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            expect($exception->getStatusCode())->toBe(409);
        }
        expect($allocations->count())->toBe($before)->and($journals->count())->toBe(0);
        return;
    }
    $first = $allocate('qa-allocation-first', 80000);
    $replay = $allocate('qa-allocation-first', 80000);
    expect($replay['replayed'])->toBeTrue()->and($replay['journalId'])->toBe($first['journalId'])
        ->and($replay['id'])->toBe($first['id'])->and($journals->count())->toBe(1);
    try {
        $allocate('qa-allocation-over', 120001);
        $this->fail('Over-allocation accepted.');
    } catch (\Illuminate\Validation\ValidationException $exception) {
        expect($allocations->count())->toBe(1);
    }
    $second = $allocate('qa-allocation-second', 120000);
    expect($allocations->count())->toBe(2)->and($journals->count())->toBe(2);
    foreach ([[$first, 80000], [$second, 120000]] as [$result, $amount]) {
        $lines = DB::table('financial_posting_lines')->where('journal_id', $result['journalId'])->orderBy('line_number')->get();
        expect($lines->count())->toBe(2)
            ->and($lines[0]->account_code)->toBe('guest_deposit_liability')
            ->and((int) $lines[0]->debit_minor)->toBe($amount)
            ->and((int) $lines[0]->credit_minor)->toBe(0)
            ->and($lines[1]->account_code)->toBe('AR')
            ->and((int) $lines[1]->credit_minor)->toBe($amount)
            ->and((int) $lines[1]->debit_minor)->toBe(0);
    }
    expect(app(NoShowRefundEligibility::class)->review($hotel, $booking)['settled'])->toBeTrue();
})->with(['success', 'missing receipt', 'reversed receipt', 'legacy replay', 'closed date']);

it('reconciles external no-show refunds atomically without sending funds', function (string $scenario): void {
    [$hotel, $author, $reviewer, $booking, $folio, , $path] = noShowFixture($this);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    $payment = noShowPayment($hotel, $folio, $author, $reviewer, 300000);
    $review = noShowApply($this, $path);
    noShowSignIn($reviewer);
    noShowApprove($this, $path, $review)->assertCreated();
    config()->set([
        'financial_accounting.enable_guest_deposit_posting' => true,
        'financial_accounting.enable_no_show_allocation_posting' => true,
        'financial_accounting.enable_no_show_refund_posting' => $scenario !== 'disabled',
    ]);
    if ($scenario !== 'missing deposit') {
        $receipt = app(\App\Services\Finance\GuestDepositJournalService::class)
            ->recognize($hotel, $booking, $payment, $reviewer->id);
        if ($scenario === 'reversed deposit') {
            app(FinancialJournalPostingService::class)->post($hotel->id, 'qa-refund-reverse-'.$payment,
                'reversal', 'qa-refund-reverse-'.$payment, 'Reverse synthetic refund deposit', [
                    ['account_code' => 'hotel_bank', 'debit_minor' => 0, 'credit_minor' => 300000],
                    ['account_code' => 'guest_deposit_liability', 'debit_minor' => 300000, 'credit_minor' => 0],
                ], $reviewer->id, $receipt);
        }
    }
    noShowSignIn($author);
    $refunds = $path.'/refund-requests';
    $input = ['requestKey' => 'qa-ledger-refund-first', 'paymentRecordId' => $payment,
        'amountMinor' => 40000, 'reason' => 'Return part of the verified excess deposit.'];
    $this->postJson($refunds, array_replace($input, ['amountMinor' => 100001]))->assertConflict();
    $refund = $this->postJson($refunds, $input)->assertCreated()->json('data.id');
    noShowSignIn($reviewer);
    $this->postJson($refunds.'/'.$refund.'/review', [
        'decision' => 'approve', 'note' => 'Reviewed the original excess deposit independently.',
    ])->assertOk();
    $id = $this->postJson($refunds.'/'.$refund.'/disbursement-evidence', [
        'externalReference' => 'QA-LEDGER-REFUND-001',
    ])->assertCreated()->assertJsonPath('data.fundsSentByPms', false)->json('data.id');
    $verify = ['decision' => 'verify', 'note' => 'Independently reviewed external bank evidence.'];
    $endpoint = $path.'/refund-disbursements/'.$id.'/review';
    $this->postJson($endpoint, $verify)->assertUnprocessable();
    noShowSignIn($author);
    if ($scenario === 'closed date') {
        DB::table('financial_day_closes')->insert([
            'hotel_id' => $hotel->id, 'business_date' => '2026-11-22', 'status' => 'completed',
            'closed_by' => $author->id, 'closed_at' => now(), 'reconciliation_snapshot' => '{}',
        ]);
    }
    $journals = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)
        ->where('source_type', 'no_show_deposit_refund');
    if (in_array($scenario, ['missing deposit', 'reversed deposit', 'closed date'], true)) {
        $this->postJson($endpoint, $verify)->assertStatus($scenario === 'closed date' ? 422 : 409);
        expect(DB::table('folio_refund_disbursement_evidence')->where('id', $id)->value('status'))->toBe('pending')
            ->and($journals->count())->toBe(0);
        return;
    }
    $this->postJson($endpoint, $verify)->assertOk()->assertJsonPath('data.fundsSentByPms', false);
    $this->postJson($endpoint, $verify)->assertUnprocessable();
    if ($scenario === 'disabled') {
        expect($journals->count())->toBe(0);
        return;
    }
    $first = $journals->first();
    expect($journals->count())->toBe(1);
    $second = $this->postJson($refunds, array_replace($input, [
        'requestKey' => 'qa-ledger-refund-second', 'amountMinor' => 60000,
    ]))->assertCreated()->json('data.id');
    noShowSignIn($reviewer);
    $this->postJson($refunds.'/'.$second.'/review', [
        'decision' => 'approve', 'note' => 'Reviewed the remaining excess deposit independently.',
    ])->assertOk();
    $secondId = $this->postJson($refunds.'/'.$second.'/disbursement-evidence', [
        'externalReference' => 'QA-LEDGER-REFUND-002',
    ])->assertCreated()->json('data.id');
    noShowSignIn($author);
    $this->postJson($path.'/refund-disbursements/'.$secondId.'/review', $verify)->assertOk();
    expect($journals->count())->toBe(2);
    foreach ($journals->orderBy('id')->get() as $journal) {
        $amount = (int) $journal->id === (int) $first->id ? 40000 : 60000;
        $lines = DB::table('financial_posting_lines')->where('journal_id', $journal->id)->orderBy('line_number')->get();
        expect($lines->count())->toBe(2)
            ->and($lines[0]->account_code)->toBe('guest_deposit_liability')
            ->and((int) $lines[0]->debit_minor)->toBe($amount)
            ->and((int) $lines[0]->credit_minor)->toBe(0)
            ->and($lines[1]->account_code)->toBe('hotel_bank')
            ->and((int) $lines[1]->credit_minor)->toBe($amount)
            ->and((int) $lines[1]->debit_minor)->toBe(0);
    }
    $remaining = app(NoShowRefundEligibility::class)->review($hotel, $booking);
    expect($remaining['verifiedRefundsMinor'])->toBe(100000)
        ->and($remaining['maximumRefundRequestMinor'])->toBe(0);
})->with(['success', 'disabled', 'missing deposit', 'reversed deposit', 'closed date']);

it('reports no-show matched ledger balances and transaction discrepancies without writes', function (string $scenario): void {
    [$hotel, $author, $reviewer, $booking, $folio, , $path] = noShowFixture($this);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    $payment = noShowPayment($hotel, $folio, $author, $reviewer, 200000);
    $review = noShowApply($this, $path);
    noShowSignIn($reviewer);
    noShowApprove($this, $path, $review)->assertCreated();
    config()->set([
        'financial_accounting.enable_guest_deposit_posting' => true,
        'financial_accounting.enable_no_show_allocation_posting' => true,
    ]);
    $reporter = app(\App\Services\Finance\NoShowLedgerReconciliationService::class);
    $journals = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id);
    if ($scenario === 'unauthorized' || $scenario === 'cross hotel') {
        $actor = $scenario === 'unauthorized'
            ? noShowActor($hotel, 'front_desk')
            : noShowActor(Hotel::create(['name' => 'Foreign reconciliation property']), 'admin');
        try {
            $reporter->report($hotel, $booking, $actor->id);
            $this->fail('Unauthorized ledger report accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            expect($exception->getStatusCode())->toBe(403);
        }
        return;
    }
    $receipt = null;
    if ($scenario !== 'missing deposit') {
        $receipt = app(\App\Services\Finance\GuestDepositJournalService::class)
            ->recognize($hotel, $booking, $payment, $reviewer->id);
    }
    if ($scenario === 'duplicate deposit') {
        app(FinancialJournalPostingService::class)->post($hotel->id, 'qa-duplicate-'.$payment,
            'guest_deposit_receipt', 'folio-payment-'.$payment, 'Synthetic duplicate receipt', [
                ['account_code' => 'hotel_bank', 'debit_minor' => 200000, 'credit_minor' => 0],
                ['account_code' => 'guest_deposit_liability', 'debit_minor' => 0, 'credit_minor' => 200000],
            ], $reviewer->id);
    }
    if ($scenario === 'reversed deposit') {
        app(FinancialJournalPostingService::class)->post($hotel->id, 'qa-reconcile-reversal-'.$payment,
            'reversal', 'qa-reconcile-reversal-'.$payment, 'Reverse synthetic deposit receipt', [
                ['account_code' => 'hotel_bank', 'debit_minor' => 0, 'credit_minor' => 200000],
                ['account_code' => 'guest_deposit_liability', 'debit_minor' => 200000, 'credit_minor' => 0],
            ], $reviewer->id, $receipt);
    }
    if ($scenario === 'mismatched deposit') {
        // Deliberately corrupt synthetic ledger data to exercise detection.
        DB::table('financial_posting_lines')->where('journal_id', $receipt)
            ->where('account_code', 'guest_deposit_liability')->update(['credit_minor' => 199999]);
    }
    if (in_array($scenario, ['partial', 'settled', 'missing allocation'], true)) {
        config()->set('financial_accounting.enable_no_show_allocation_posting', $scenario !== 'missing allocation');
        app(\App\Domain\Reservations\FolioSettlementService::class)->allocate(
            $hotel, $booking, $payment, $reviewer->id, 'qa-reconcile-allocation', 'guest', 'Demo guest',
            $scenario === 'settled' ? 200000 : 80000, null
        );
    }
    $before = $journals->orderBy('id')->get()->all();
    $result = $reporter->report($hotel, $booking, $reviewer->id);
    expect($result['readOnly'])->toBeTrue()->and($result['closingCertified'])->toBeFalse()
        ->and($result['expectedMinor']['deposits'])->toBe(200000)
        ->and($result['expectedMinor']['fees'])->toBe(200000)
        ->and($journals->get()->all())->toEqual($before);
    if (in_array($scenario, ['unallocated', 'partial', 'settled'], true)) {
        $balance = match ($scenario) {
            'partial' => 120000,
            'settled' => 0,
            default => 200000,
        };
        expect($result['reconciled'])->toBeTrue()
            ->and($result['settled'])->toBe($scenario === 'settled')
            ->and($result['issues'])->toBe([])
            ->and($result['expectedMinor'])->toBe($result['matchedJournalMinor'])
            ->and($result['expectedBalances'])->toBe([
                'depositLiabilityMinor' => $balance, 'receivableMinor' => $balance,
            ])->and($result['matchedJournalBalances'])->toBe($result['expectedBalances']);
        return;
    }
    $reason = match ($scenario) {
        'duplicate deposit' => 'duplicate_journal',
        'reversed deposit' => 'reversed_journal',
        'mismatched deposit' => 'journal_mismatch',
        default => 'missing_journal',
    };
    expect($result['reconciled'])->toBeFalse()->and($result['settled'])->toBeFalse()
        ->and(array_column($result['issues'], 'reason'))->toContain($reason)
        ->and(array_column($result['issues'], 'kind'))->toContain($scenario === 'missing allocation' ? 'allocation' : 'deposit');
})->with(['unallocated', 'partial', 'settled', 'missing deposit', 'duplicate deposit',
    'reversed deposit', 'mismatched deposit', 'missing allocation', 'unauthorized', 'cross hotel']);

it('exposes no-show ledger reconciliation only to authorized hotel administrators', function (): void {
    [$hotel, $author, $reviewer, $booking, $folio, , $path] = noShowFixture($this);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    noShowPayment($hotel, $folio, $author, $reviewer, 200000);
    $review = noShowApply($this, $path);
    noShowSignIn($reviewer);
    noShowApprove($this, $path, $review)->assertCreated();
    $endpoint = $path.'/no-show-ledger-reconciliation';
    $before = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->count();
    $response = $this->getJson($endpoint)->assertOk()
        ->assertJsonPath('data.bookingId', (string) $booking)
        ->assertJsonPath('data.currency', 'PHP')
        ->assertJsonPath('data.readOnly', true)
        ->assertJsonPath('data.closingCertified', false)
        ->assertJsonPath('data.reconciled', false)
        ->assertJsonPath('data.expectedMinor.deposits', 200000)
        ->assertJsonPath('data.matchedJournalMinor.deposits', 0)
        ->assertJsonPath('data.issues.0.reason', 'missing_journal');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    noShowSignIn(noShowActor($hotel, 'front_desk'));
    $this->getJson($endpoint)->assertForbidden();
    $other = Hotel::create(['name' => 'Foreign ledger API property']);
    noShowSignIn(noShowActor($other, 'admin'));
    $this->getJson($endpoint)->assertNotFound();
    $this->getJson('/api/v1/hotels/'.$other->id.'/bookings/'.$booking.'/no-show-ledger-reconciliation')->assertNotFound();
    expect(DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->count())->toBe($before);
});


it('requires an independently approved complete room journal reversal before no-show fee accounting', function (string $scenario): void {
    [$hotel, $author, $reviewer, $booking, $folio, , $path] = noShowFixture($this);
    config()->set([
        'financial_accounting.enable_live_folio_tax' => true,
        'financial_accounting.enable_no_show_room_correction_review' => true,
        'financial_accounting.enable_no_show_room_correction_posting' => true,
    ]);
    DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->update(['business_date' => '2026-11-20']);
    $policy = DB::table('financial_tax_policies')->insertGetId([
        'hotel_id' => $hotel->id, 'classification' => 'vat_standard', 'price_mode' => 'inclusive',
        'rate_bps' => 1200, 'effective_from' => '2026-11-20', 'status' => 'approved',
        'approved_by' => $reviewer->id, 'approved_at' => now(),
    ]);
    $entry = (int) DB::table('folio_entries')->where('folio_id', $folio)->where('entry_type', 'room_night')->orderBy('stay_date')->value('id');
    $assessment = app(\App\Services\Finance\FolioNightAssessmentService::class);
    $draft = $assessment->prepare($hotel->id, $entry, $policy, $author->id);
    $assessment->approve($hotel->id, (int) $draft['id'], $reviewer->id);
    DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->update(['business_date' => '2026-11-22']);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    $this->getJson($path.'/no-show-financial-review')->assertOk()->assertJsonPath('data.canApply', false);
    $service = app(\App\Services\Finance\NoShowRoomNightCorrectionDecisionService::class);
    $completion = app(\App\Services\Finance\NoShowRoomCorrectionCompletion::class);
    $reason = 'Independent review of unused room accounting.';
    $proposal = $service->propose($hotel, $booking, $author->id, $reason);
    expect($completion->valid($hotel->id, $folio))->toBeFalse();
    if ($scenario === 'self approval') {
        expect(fn () => $service->approve($hotel, $proposal, $author->id, $reason))
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        expect(DB::table('no_show_room_correction_reviews')->where('proposal_id', $proposal)->count())->toBe(0);
        return;
    }
    if ($scenario === 'stale snapshot') {
        noShowPayment($hotel, $folio, $author, $reviewer, 10000);
        expect(fn () => $service->approve($hotel, $proposal, $reviewer->id, $reason))
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        return;
    }
    $service->approve($hotel, $proposal, $reviewer->id, $reason);
    $journals = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->where('source_type', 'no_show_room_correction');
    expect((clone $journals)->count())->toBe(0);
    if ($scenario === 'disabled posting' || $scenario === 'changed date') {
        if ($scenario === 'disabled posting') config()->set('financial_accounting.enable_no_show_room_correction_posting', false);
        else DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->update(['business_date' => '2026-11-23']);
        expect(fn () => $service->post($hotel, $proposal, $reviewer->id))
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        expect((clone $journals)->count())->toBe(0);
        return;
    }
    $posted = $service->post($hotel, $proposal, $reviewer->id);
    expect($posted['replayed'])->toBeFalse()
        ->and(count($posted['journalIds']))->toBe(2)
        ->and($completion->valid($hotel->id, $folio))->toBeTrue()
        ->and($service->post($hotel, $proposal, $reviewer->id)['journalIds'])->toBe($posted['journalIds'])
        ->and((clone $journals)->count())->toBe(2);
    if ($scenario === 'damaged reversal') {
        $journal = (clone $journals)->first();
        DB::table('financial_posting_lines')->where('journal_id', $journal->id)->where('line_number', 1)
            ->update(['account_code' => 'INVALID']);
        expect($completion->valid($hotel->id, $folio))->toBeFalse();
        $this->getJson($path.'/no-show-financial-review')->assertOk()->assertJsonPath('data.canApply', false);
        return;
    }
    $review = noShowApply($this, $path);
    noShowSignIn($reviewer);
    noShowApprove($this, $path, $review)->assertCreated()->assertJsonPath('data.grossMinor', 200000);
    expect(NoShowClosingGuard::unresolved($hotel->id, '2026-11-22'))->toBe(0);
    expect((int) DB::table('folio_adjustments')->where('folio_id', $folio)->where('kind', 'credit')->sum('amount_minor'))->toBe(600000);
})->with(['success', 'self approval', 'stale snapshot', 'disabled posting', 'changed date', 'damaged reversal']);


it('corrects cleared no-show fees atomically and preserves financial evidence', function (string $scenario, int $replacement, int $deposit): void {
    [$hotel, $author, $reviewer, $booking, $folio, , $path] = noShowFixture($this);
    config()->set([
        'financial_accounting.enable_no_show_fee_correction_review' => true,
        'financial_accounting.enable_no_show_fee_correction_posting' => true,
    ]);
    if ($deposit > 0) noShowPayment($hotel, $folio, $author, $reviewer, $deposit);
    $this->travelTo(new DateTimeImmutable('2026-11-22 07:00 Asia/Manila'));
    $this->postJson($path.'/no-show')->assertOk();
    $review = noShowApply($this, $path);
    noShowSignIn($reviewer);
    $original = (int) noShowApprove($this, $path, $review, 'vat_standard')->assertCreated()->json('data.journalId');
    $service = app(\App\Services\Finance\NoShowFeeCorrectionDecisionService::class);
    $previewer = app(\App\Services\Finance\NoShowFeeReversalPreview::class);
    $reason = 'Documented exception independently reviewed for synthetic testing.';
    $preview = $previewer->preview($hotel, $booking, $author->id, $replacement);
    expect($preview['eligibleForReview'])->toBeTrue()
        ->and($preview['canPost'])->toBeFalse()
        ->and($preview['proposedFolioCreditMinor'])->toBe(200000 - $replacement);
    $proposal = $service->propose($hotel, $booking, $author->id, $replacement, $reason);
    $journals = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id);
    $adjustments = DB::table('folio_adjustments')->where('hotel_id', $hotel->id)->where('folio_id', $folio);
    $payments = DB::table('folio_payment_records')->where('hotel_id', $hotel->id)->where('folio_id', $folio);
    $originalPayments = (clone $payments)->orderBy('id')->get()->all();
    $originalJournal = (clone $journals)->where('id', $original)->first();
    $originalLines = DB::table('financial_posting_lines')->where('journal_id', $original)->orderBy('line_number')->get()->all();
    $originalCount = (clone $journals)->count();
    $originalAdjustments = (clone $adjustments)->count();
    $approve = fn (int $actor) => $service->approve($hotel, $proposal, $actor, 'vat_standard', $reason, $reason);
    $apply = fn (int $actor) => $service->applyApprovedCorrection($hotel, $proposal, $actor);

    if ($scenario === 'self approval') {
        expect(fn () => $approve($author->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    } elseif ($scenario === 'stale evidence') {
        noShowPayment($hotel, $folio, $author, $reviewer, 10000, 2);
        expect(fn () => $approve($reviewer->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    } else {
        $approve($reviewer->id);
        if ($scenario === 'wrong poster') {
            expect(fn () => $apply($author->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        } elseif ($scenario === 'disabled') {
            config()->set('financial_accounting.enable_no_show_fee_correction_posting', false);
            expect(fn () => $apply($reviewer->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        } elseif ($scenario === 'stale date') {
            DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->update(['business_date' => '2026-11-23']);
            expect(fn () => $apply($reviewer->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        } elseif ($scenario === 'rollback') {
            // Inject a failure immediately after the credit insert, without mocking
            // final service classes or relying on database-specific triggers.
            $armed = true;
            $injected = false;
            DB::listen(function (\Illuminate\Database\Events\QueryExecuted $event) use (&$armed, &$injected): void {
                if ($armed && preg_match('/^insert\s+into\s+["`]?folio_adjustments["`]?\s/i', ltrim($event->sql))) {
                    $injected = true;
                    throw new \RuntimeException('Synthetic correction rollback probe.');
                }
            });
            try {
                expect(fn () => $apply($reviewer->id))->toThrow(\RuntimeException::class, 'Synthetic correction rollback probe.');
            } finally {
                $armed = false;
            }
            expect($injected)->toBeTrue();
        } else {
            $result = $apply($reviewer->id);
            expect($result['replayed'])->toBeFalse()
                ->and($result['replacementFeeMinor'])->toBe($replacement)
                ->and($result['refundIssued'])->toBeFalse()
                ->and($result['replacementJournalId'] === null)->toBe($replacement === 0)
                ->and((clone $journals)->count())->toBe($originalCount + ($replacement === 0 ? 1 : 2))
                ->and((clone $adjustments)->count())->toBe($originalAdjustments + 1)
                ->and((clone $payments)->orderBy('id')->get()->all())->toEqual($originalPayments)
                ->and((clone $journals)->where('id', $original)->first())->toEqual($originalJournal)
                ->and(DB::table('financial_posting_lines')->where('journal_id', $original)->orderBy('line_number')->get()->all())->toEqual($originalLines);
            $effective = NoShowRefundEligibility::clearance($hotel->id, $booking, $folio);
            expect((int) $effective->gross_minor)->toBe($replacement)
                ->and((int) $effective->vat_minor)->toBe($replacement - intdiv($replacement * 10000 + 5600, 11200));
            $eligibility = app(NoShowRefundEligibility::class)->review($hotel, $booking);
            expect($eligibility['financialClearanceValid'])->toBeTrue()
                ->and($eligibility['remainingCollectionMinor'])->toBe(max(0, $replacement - $deposit))
                ->and($eligibility['maximumRefundRequestMinor'])->toBe(max(0, $deposit - $replacement))
                ->and(NoShowClosingGuard::unresolved($hotel->id, '2026-11-22'))->toBe(0);
            $report = app(\App\Services\Finance\NoShowFinancialReport::class)->report($hotel->id, '2026-11-22', false);
            expect($report['postedReceivableMovementMinor'])->toBe($replacement)
                ->and($report['postedRevenueMinor'] + $report['postedVatMinor'])->toBe($replacement);
            DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->update(['business_date' => '2026-11-23']);
            expect($apply($reviewer->id))->toBe(array_replace($result, ['replayed' => true]));
            if ($scenario === 'damaged journal') {
                DB::table('financial_posting_lines')->where('journal_id', $result['reversalJournalId'])
                    ->where('line_number', 1)->update(['account_code' => 'INVALID']);
                expect(fn () => $apply($reviewer->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
                expect(NoShowRefundEligibility::clearance($hotel->id, $booking, $folio))->toBeNull()
                    ->and(NoShowClosingGuard::unresolved($hotel->id, '2026-11-23'))->toBe(1);
            }
            expect((clone $journals)->count())->toBe($originalCount + ($replacement === 0 ? 1 : 2))
                ->and((clone $adjustments)->count())->toBe($originalAdjustments + 1);
            return;
        }
    }
    expect((clone $journals)->count())->toBe($originalCount)
        ->and((clone $adjustments)->count())->toBe($originalAdjustments)
        ->and(NoShowRefundEligibility::clearance($hotel->id, $booking, $folio))->not->toBeNull();
})->with([
    'partial reduction with excess deposit' => ['success', 100000, 150000],
    'partial reduction with outstanding balance' => ['success', 100000, 50000],
    'full waiver with deposit' => ['success', 0, 150000],
    'full waiver without deposit' => ['success', 0, 0],
    'self approval' => ['self approval', 100000, 0],
    'changed payment evidence' => ['stale evidence', 100000, 0],
    'unauthorized posting' => ['wrong poster', 100000, 0],
    'disabled posting' => ['disabled', 100000, 0],
    'changed business date' => ['stale date', 100000, 0],
    'atomic rollback' => ['rollback', 100000, 0],
    'damaged reversal' => ['damaged journal', 100000, 150000],
]);
