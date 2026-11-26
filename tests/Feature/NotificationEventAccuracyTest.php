<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\User;
use App\Services\HotelNotificationService;
use App\Services\OperationalActivityNotifications;
use App\Services\FinancialActivityNotifications;
use App\Services\PushPreferences;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->hotel = Hotel::create(['name' => 'Notification QA', 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
    $this->other = Hotel::create(['name' => 'Other notification QA', 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
    $this->admin = User::create(['name' => 'Notification admin', 'email' => 'event-accuracy@example.test', 'password' => 'test-password', 'is_active' => true, 'must_change_password' => false]);
    $this->hotel->memberships()->create(['user_id' => $this->admin->id, 'role' => 'admin', 'is_active' => true]);
    $this->type = $this->hotel->roomTypes()->create(['name' => 'Standard', 'max_occupancy' => 2]);
    $this->room = $this->hotel->rooms()->create(['room_type_id' => $this->type->id, 'number' => 'QA101', 'floor_number' => 1]);
});

it('executes every expanded discovery query on a migrated empty hotel without leaking data', function (): void {
    $service = app(HotelNotificationService::class);
    expect($service->feed($this->hotel, true, $this->admin->id)['notifications'])->toBe([]);
    expect(iterator_to_array($service->all($this->hotel), false))->toBe([]);
    expect(app(PushPreferences::class)->get($this->hotel->id, $this->admin->id))->toHaveCount(42);
    expect($service->feed($this->other, true, $this->admin->id)['notifications'])->toBe([]);
});

it('discovers a recent urgent escalation of an older work order once and suppresses it when resolved', function (): void {
    $order = DB::table('maintenance_work_orders')->insertGetId([
        'hotel_id' => $this->hotel->id, 'room_id' => $this->room->id,
        'title' => 'Synthetic maintenance', 'description' => 'QA', 'priority' => 'urgent',
        'status' => 'open', 'created_by' => $this->admin->id,
        'created_at' => now()->subDays(3), 'updated_at' => now(),
    ]);
    $event = function (string $before, string $after) use ($order): int {
        return DB::table('maintenance_work_order_events')->insertGetId([
            'hotel_id' => $this->hotel->id, 'work_order_id' => $order, 'actor_id' => $this->admin->id,
            'action' => 'updated', 'before_state' => json_encode(['priority' => $before]),
            'after_state' => json_encode(['priority' => $after]), 'created_at' => now(),
        ]);
    };
    $escalation = $event('high', 'urgent');
    $event('urgent', 'urgent');
    $service = app(OperationalActivityNotifications::class);
    $id = 'maintenance_urgent:'.$escalation;
    $alerts = collect(iterator_to_array($service->all($this->hotel), false))->where('kind', 'maintenance_urgent')->values();
    expect($alerts)->toHaveCount(1);
    expect($alerts[0]['id'])->toBe($id);
    expect($alerts[0]['orderId'])->toBe((string) $order);
    expect($service->current($this->other, $id))->toBeNull();
    DB::table('maintenance_work_orders')->where('id', $order)->update(['status' => 'resolved', 'resolved_at' => now()]);
    expect($service->current($this->hotel, $id))->toBeNull();
});

it('excludes rolled-back records and clears reconciled cashier action alerts', function (): void {
    $service = app(FinancialActivityNotifications::class);
    $insert = fn () => DB::table('cashier_shifts')->insertGetId([
        'hotel_id' => $this->hotel->id, 'cashier_id' => $this->admin->id,
        'currency' => 'PHP', 'status' => 'submitted', 'opening_float_minor' => 10000,
        'expected_cash_minor' => 10000, 'counted_cash_minor' => 9000, 'variance_minor' => -1000,
        'opened_at' => now()->subHour(), 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::beginTransaction();
    $rolledBack = $insert();
    DB::rollBack();
    expect($service->current($this->hotel, 'cashier_discrepancy:'.$rolledBack))->toBeNull();
    $id = $insert();
    expect($service->current($this->hotel, 'cashier_discrepancy:'.$id)['shiftId'])->toBe((string) $id);
    expect($service->current($this->other, 'cashier_discrepancy:'.$id))->toBeNull();
    DB::table('cashier_shifts')->where('id', $id)->update(['status' => 'reconciled', 'reconciled_at' => now()]);
    expect($service->current($this->hotel, 'cashier_discrepancy:'.$id))->toBeNull();
});
