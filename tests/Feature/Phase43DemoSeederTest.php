<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\Phase43DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Test-only runtime variables. Seeder never accepts a default hotel.
    config()->set('app.env', 'staging');
    putenv('PMS_DEMO_SEED_ACK=isolated-synthetic-staging-only');
    putenv('PMS_STAGING_ISOLATED_DB_ACK=isolated-test-db');
    $_ENV['PMS_DEMO_SEED_ACK'] = 'isolated-synthetic-staging-only';
    $_ENV['PMS_STAGING_ISOLATED_DB_ACK'] = 'isolated-test-db';
});

afterEach(function (): void {
    foreach (['PMS_DEMO_HOTEL_ID', 'PMS_DEMO_SEED_ACK', 'PMS_STAGING_ISOLATED_DB_ACK'] as $key) {
        putenv($key);
        unset($_ENV[$key]);
    }
});

it('refuses to create fixtures if runtime is production', function (): void {
    config()->set('app.env', 'production');
    $hotel = Hotel::create(['name' => 'Synthetic Only', 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
    putenv('PMS_DEMO_HOTEL_ID='.$hotel->id);
    $_ENV['PMS_DEMO_HOTEL_ID'] = (string) $hotel->id;
    expect(fn () => app(Phase43DemoSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(RoomType::count())->toBe(0);
});

it('prepares idempotent synthetic catalog and a separate due checkout fixture', function (): void {
    $hotel = Hotel::create(['name' => 'Synthetic Only', 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
    $admin = User::create([
        'name' => 'Fixture Admin', 'email' => 'fixture-admin@example.test',
        'password' => 'not-a-real-secret', 'is_active' => true,
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $admin->id,
        'role' => 'admin', 'is_active' => true,
    ]);
    putenv('PMS_DEMO_HOTEL_ID='.$hotel->id);
    $_ENV['PMS_DEMO_HOTEL_ID'] = (string) $hotel->id;

    app(Phase43DemoSeeder::class)->run();
    app(Phase43DemoSeeder::class)->run();

    expect(DB::table('room_types')->where('hotel_id', $hotel->id)->count())->toBe(2)
        ->and(DB::table('rooms')->where('hotel_id', $hotel->id)->count())->toBe(3)
        ->and(DB::table('room_rate_plans')->where('hotel_id', $hotel->id)->count())->toBe(2)
        ->and(DB::table('reservation_records')->where('hotel_id', $hotel->id)->count())->toBe(1);

    $bookable = DB::table('rooms')->where('hotel_id', $hotel->id)->where('number', 'P43-101')->first();
    $checkout = DB::table('rooms')->where('hotel_id', $hotel->id)->where('number', 'P43-199')->first();
    $record = DB::table('reservation_records')->where('hotel_id', $hotel->id)->first();
    expect((int) $bookable->id)->not->toBe((int) $checkout->id)
        ->and((int) $record->assigned_room_id)->toBe((int) $checkout->id)
        ->and($record->lifecycle_state)->toBe('checked_in')
        ->and($record->check_out)->toBe(now('Asia/Manila')->toDateString());
});

it('rejects a hotel that already has ordinary guest data', function (): void {
    $hotel = Hotel::create(['name' => 'Ordinary Property', 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
    $hotel->guests()->create(['first_name' => 'Real', 'last_name' => 'Guest']);
    putenv('PMS_DEMO_HOTEL_ID='.$hotel->id);
    $_ENV['PMS_DEMO_HOTEL_ID'] = (string) $hotel->id;
    expect(fn () => app(Phase43DemoSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(DB::table('rooms')->where('hotel_id', $hotel->id)->count())->toBe(0);
});
