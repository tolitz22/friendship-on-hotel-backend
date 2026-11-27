<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\PlatformSubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function paymentQa(): array
{
    $user = User::create(['name' => 'Operator', 'email' => 'payment-qa@example.test', 'password' => 'synthetic-password']);
    config(['platform.admin_ids' => [(string) $user->id]]);
    Sanctum::actingAs($user);
    $hotel = Hotel::create(['name' => 'Payment QA']);
    $plan = PlatformSubscriptionPlan::create(['name' => 'QA', 'code' => 'payment-qa', 'currency' => 'PHP', 'monthly_minor' => 500000,
        'annual_minor' => 5000000, 'is_active' => true, 'revision' => 1]);
    $today = \Carbon\CarbonImmutable::today('Asia/Manila')->toDateString();
    test()->putJson('/api/v1/platform/hotel-subscriptions/'.$hotel->id, ['action' => 'assign', 'revision' => 0,
        'planId' => $plan->id, 'planRevision' => 1, 'cycle' => 'monthly'])->assertOk();
    $invoice = test()->postJson('/api/v1/platform/hotels/'.$hotel->id.'/subscription-invoices', [
        'revision' => 1, 'dueOn' => $today, 'confirmed' => true])->assertCreated()->json('data.id');
    return [$user, $hotel, $invoice, $today];
}

it('records pending evidence, verifies partial payments, rejects duplicates and prevents overpayment', function (): void {
    [$user, $hotel, $invoice, $today] = paymentQa();
    $url = '/api/v1/platform/subscription-invoices/'.$invoice.'/payments';
    $list = '/api/v1/platform/subscription-invoices';
    $input = ['method' => 'gcash', 'reference' => 'QA-001', 'evidenceReference' => 'Statement item QA1',
        'amountMinor' => 200000, 'receivedOn' => $today, 'confirmed' => true];
    $id = $this->postJson($url, $input)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
    $this->postJson($url, array_replace($input, ['reference' => 'qa-001']))->assertOk()->assertJsonPath('replayed', true);
    $this->postJson($url, array_replace($input, ['amountMinor' => 210000]))->assertStatus(409);
    $this->postJson($url, array_replace($input, ['reference' => 'QA-002', 'amountMinor' => 310000]))->assertUnprocessable();
    $this->getJson($list)->assertOk()->assertJsonPath('data.0.outstandingMinor', 500000)->assertJsonPath('data.0.pendingMinor', 200000);
    $review = ['decision' => 'verified', 'note' => 'Matched synthetic statement', 'confirmed' => true];
    $this->putJson($url.'/'.$id.'/review', $review)->assertUnprocessable()->assertJsonValidationErrors('selfReviewConfirmed');
    $review['selfReviewConfirmed'] = true;
    $this->putJson($url.'/'.$id.'/review', $review)->assertOk()->assertJsonPath('data.selfReviewed', true);
    $this->getJson($list.'?status=partially_paid')->assertOk()->assertJsonPath('data.0.status', 'partially_paid')->assertJsonPath('summary.outstandingMinor', 300000);
    $this->putJson($url.'/'.$id.'/review', $review)->assertOk();
    $next = $this->postJson($url, array_replace($input, ['reference' => 'QA-003', 'amountMinor' => 300000]))->assertCreated()->json('data.id');
    $this->putJson($url.'/'.$next.'/review', $review)->assertOk();
    $this->getJson($list.'?status=paid')->assertOk()->assertJsonPath('summary.outstandingMinor', 0)->assertJsonPath('data.0.status', 'paid');
    $this->getJson($url)->assertOk()->assertJsonPath('balance.verifiedMinor', 500000);
    expect(DB::table('platform_subscription_payments')->count())->toBe(2);
    expect(DB::table('reservation_folios')->count())->toBe(0);
    expect(DB::table('cashier_shifts')->count())->toBe(0);
});

it('rejection releases pending capacity and separate reviewers do not need self-review acknowledgement', function (): void {
    [$user, $hotel, $invoice, $today] = paymentQa();
    $url = '/api/v1/platform/subscription-invoices/'.$invoice.'/payments';
    $input = ['method' => 'cash', 'reference' => 'CASH-QA-1', 'evidenceReference' => 'Receipt QA',
        'amountMinor' => 500000, 'receivedOn' => $today, 'confirmed' => true];
    $id = $this->postJson($url, $input)->assertCreated()->json('data.id');
    $reviewer = User::create(['name' => 'Reviewer', 'email' => 'payment-reviewer@example.test', 'password' => 'synthetic-password']);
    config(['platform.admin_ids' => [(string) $user->id, (string) $reviewer->id]]);
    Sanctum::actingAs($reviewer);
    $review = ['decision' => 'rejected', 'note' => 'Evidence not matched', 'confirmed' => true];
    $this->putJson($url.'/'.$id.'/review', $review)->assertOk()->assertJsonPath('data.selfReviewed', false);
    $this->getJson($url)->assertOk()->assertJsonPath('balance.pendingMinor', 0)->assertJsonPath('balance.outstandingMinor', 500000);
    $this->putJson($url.'/'.$id.'/review', array_replace($review, ['decision' => 'verified']))->assertStatus(409);
    $this->postJson($url, array_replace($input, ['reference' => 'CASH-QA-2']))->assertCreated();
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $reviewer->id, 'role' => 'admin', 'is_active' => true]);
    $this->getJson($url)->assertForbidden();
    $this->postJson($url, $input)->assertForbidden();
    $this->putJson($url.'/'.$id.'/review', $review)->assertForbidden();
});
