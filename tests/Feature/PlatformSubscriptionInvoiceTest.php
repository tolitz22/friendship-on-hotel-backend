<?php

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\PlatformSubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function invoiceFixture(int $price = 499900): array
{
    $operator = User::create(['name' => 'Operator', 'email' => 'invoice-operator@example.test', 'password' => 'synthetic-password']);
    config(['platform.admin_ids' => [(string) $operator->id]]);
    Sanctum::actingAs($operator);
    $hotel = Hotel::create(['name' => 'Synthetic invoice hotel']);
    $plan = PlatformSubscriptionPlan::create(['name' => 'Synthetic', 'code' => 'invoice-test', 'currency' => 'PHP',
        'monthly_minor' => $price, 'annual_minor' => $price * 10, 'is_active' => true, 'revision' => 1]);
    return [$hotel, $plan, $operator];
}

it('issues one immutable internal billing record per agreement revision and bills only the renewal period', function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-01-31 12:00:00', 'Asia/Manila'));
    [$hotel, $plan, $operator] = invoiceFixture();
    $agreement = '/api/v1/platform/hotel-subscriptions/'.$hotel->id;
    $invoice = '/api/v1/platform/hotels/'.$hotel->id.'/subscription-invoices';
    $list = '/api/v1/platform/subscription-invoices';
    $this->putJson($agreement, ['action' => 'assign', 'revision' => 0, 'planId' => $plan->id, 'planRevision' => 1, 'cycle' => 'monthly'])->assertOk();
    $payload = ['revision' => 1, 'dueOn' => '2026-02-07', 'confirmed' => true];
    $first = $this->postJson($invoice, $payload)->assertCreated()->assertJsonPath('data.amountMinor', 499900)
        ->assertJsonPath('data.periodStart', '2026-01-31')->assertJsonPath('data.periodEnd', '2026-02-28')->json('data.reference');
    $this->postJson($invoice, $payload)->assertOk()->assertJsonPath('data.reference', $first)->assertJsonPath('replayed', true);
    $this->postJson($invoice, array_replace($payload, ['dueOn' => '2026-02-08']))->assertStatus(409);
    $this->getJson('/api/v1/platform/hotel-subscriptions')->assertOk()->assertJsonPath('data.0.currentRevisionBilled', true);
    $plan->update(['monthly_minor' => 599900, 'revision' => 2]);
    $this->putJson($agreement, ['action' => 'renew', 'revision' => 1])->assertOk();
    $this->postJson($invoice, array_replace($payload, ['revision' => 2]))->assertCreated()
        ->assertJsonPath('data.periodStart', '2026-02-28')->assertJsonPath('data.periodEnd', '2026-03-28')->assertJsonPath('data.amountMinor', 499900);
    $this->getJson($list)->assertOk()->assertJsonPath('summary.outstandingMinor', 999800)->assertJsonPath('meta.total', 2);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-02-08 00:00:00', 'Asia/Manila'));
    $this->getJson($list.'?status=overdue')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.status', 'overdue');
    expect(DB::table('reservation_folios')->count())->toBe(0);
    expect(DB::table('cashier_shifts')->count())->toBe(0);
    expect(DB::table('platform_subscription_invoices')->count())->toBe(2);
});

it('rejects billing of trials and invalid due dates and protects platform routes', function (): void {
    [$hotel, $plan, $operator] = invoiceFixture();
    $agreement = '/api/v1/platform/hotel-subscriptions/'.$hotel->id;
    $invoice = '/api/v1/platform/hotels/'.$hotel->id.'/subscription-invoices';
    $today = \Carbon\CarbonImmutable::today('Asia/Manila');
    $terms = ['planId' => $plan->id, 'planRevision' => 1, 'cycle' => 'monthly'];
    $this->putJson($agreement, [...$terms, 'action' => 'trial', 'revision' => 0, 'trialDays' => 14])->assertOk();
    $payload = ['revision' => 1, 'dueOn' => $today->toDateString(), 'confirmed' => true];
    $this->postJson($invoice, $payload)->assertUnprocessable();
    $this->putJson($agreement, [...$terms, 'action' => 'assign', 'revision' => 1])->assertOk();
    $this->postJson($invoice, array_replace($payload, ['revision' => 2, 'dueOn' => $today->subDay()->toDateString()]))->assertUnprocessable();
    $this->postJson($invoice, array_replace($payload, ['revision' => 2, 'confirmed' => false]))->assertUnprocessable();
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $operator->id, 'role' => 'admin', 'is_active' => true]);
    $this->getJson('/api/v1/platform/subscription-invoices')->assertForbidden();
    $this->postJson($invoice, array_replace($payload, ['revision' => 2]))->assertForbidden();
    expect(DB::table('platform_subscription_invoices')->count())->toBe(0);
});
