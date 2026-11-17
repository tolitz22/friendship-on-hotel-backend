<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opt-in Phase 43 fixtures. NEVER invoked by DatabaseSeeder or normal deploy.
 * Targets an existing hotel and existing admin; does not create credentials.
 *
 * An already-checked-in, due-today stay is a synthetic test fixture, not
 * evidence that real identity/room/readiness verification occurred.
 */
final class Phase43DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (config('app.env') !== 'staging'
            || env('PMS_DEMO_SEED_ACK') !== 'isolated-synthetic-staging-only'
            || env('PMS_STAGING_ISOLATED_DB_ACK') !== 'isolated-test-db') {
            throw new RuntimeException('Refusing Phase 43 seed: staging + both explicit isolated DB acknowledgements are required.');
        }

        $hotelId = filter_var(env('PMS_DEMO_HOTEL_ID'), FILTER_VALIDATE_INT);
        if (! $hotelId || $hotelId < 1) {
            throw new RuntimeException('Set PMS_DEMO_HOTEL_ID to an existing dedicated synthetic hotel ID.');
        }

        $hotel = Hotel::findOrFail($hotelId);
        $actorId = DB::table('hotel_memberships as hm')
            ->join('users as u', 'u.id', '=', 'hm.user_id')
            ->where('hm.hotel_id', $hotel->id)
            ->where('hm.role', 'admin')
            ->where('hm.is_active', true)
            ->where('u.is_active', true)
            ->orderBy('u.id')
            ->value('u.id');
        if (! $actorId) {
            throw new RuntimeException('Create an active hotel administrator with hotel:bootstrap-admin before seeding.');
        }

        $timezone = $hotel->timezone ?: 'Asia/Manila';
        $today = now($timezone)->startOfDay();
        $yesterday = $today->copy()->subDay()->toDateString();
        $departure = $today->toDateString();
        $todayString = $today->toDateString();

        $ids = DB::transaction(function () use ($hotel, $actorId, $yesterday, $departure, $todayString): array {
            // Do not silently reconfigure an existing inventory item. This
            // fixture uses distinct Phase43-prefixed room/type identifiers.
            $type = RoomType::firstOrCreate(
                ['hotel_id' => $hotel->id, 'name' => 'Phase43 Standard Demo'],
                ['max_occupancy' => 2]
            );
            $extraType = RoomType::firstOrCreate(
                ['hotel_id' => $hotel->id, 'name' => 'Phase43 Family Demo'],
                ['max_occupancy' => 4]
            );
            $bookable = Room::firstOrCreate(
                ['hotel_id' => $hotel->id, 'number' => 'P43-101'],
                ['room_type_id' => $type->id, 'floor_number' => 1]
            );
            $checkout = Room::firstOrCreate(
                ['hotel_id' => $hotel->id, 'number' => 'P43-199'],
                ['room_type_id' => $type->id, 'floor_number' => 1]
            );
            Room::firstOrCreate(
                ['hotel_id' => $hotel->id, 'number' => 'P43-201'],
                ['room_type_id' => $extraType->id, 'floor_number' => 2]
            );

            foreach ([$bookable, $checkout] as $room) {
                if ((int) $room->room_type_id !== (int) $type->id) {
                    throw new RuntimeException('A Phase43 room number already belongs to another room type: '.$room->number);
                }
            }

            $currency = strtoupper($hotel->currency ?: 'PHP');
            if ($currency !== 'PHP') {
                throw new RuntimeException('Phase43 demo rates require a PHP-currency staging hotel.');
            }
            $rate = DB::table('room_rate_plans')->where('hotel_id', $hotel->id)
                ->where('room_type_id', $type->id)->where('name', 'Phase43 Standard Night')->first();
            if (! $rate) {
                $rateId = DB::table('room_rate_plans')->insertGetId([
                    'hotel_id' => $hotel->id, 'room_type_id' => $type->id,
                    'name' => 'Phase43 Standard Night', 'nightly_minor' => 250000,
                    'currency' => 'PHP', 'is_active' => true, 'created_by' => $actorId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                if (! $rate->is_active || $rate->currency !== 'PHP') {
                    throw new RuntimeException('Existing Phase43 rate was modified; restore it explicitly before seeding.');
                }
                $rateId = (int) $rate->id;
            }

            $familyRate = DB::table('room_rate_plans')->where('hotel_id', $hotel->id)
                ->where('room_type_id', $extraType->id)->where('name', 'Phase43 Family Night')->first();
            if (! $familyRate) {
                DB::table('room_rate_plans')->insert([
                    'hotel_id' => $hotel->id, 'room_type_id' => $extraType->id,
                    'name' => 'Phase43 Family Night', 'nightly_minor' => 420000,
                    'currency' => 'PHP', 'is_active' => true, 'created_by' => $actorId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            // No real person, email or phone. This named fixture is stable
            // across re-runs; Playwright creates a separate synthetic guest.
            $guest = Guest::firstOrCreate(
                ['hotel_id' => $hotel->id, 'email' => 'phase43-checkout@example.test'],
                ['first_name' => 'Synthetic', 'last_name' => 'Checkout', 'nationality' => null]
            );

            $reference = 'PHASE43-CHECKOUT-'.$todayString;
            $record = DB::table('reservation_records')->where('hotel_id', $hotel->id)
                ->where('reference', $reference)->first();
            if ($record && ($record->lifecycle_state !== 'checked_in'
                || (string) $record->check_out !== $departure
                || (int) $record->assigned_room_id !== (int) $checkout->id)) {
                throw new RuntimeException('Existing Phase43 checkout fixture has been used or changed. Do not reset a checked-out booking; run against a fresh isolated database/day.');
            }

            if (! $record) {
                $conflict = DB::table('reservation_records')
                    ->where('hotel_id', $hotel->id)
                    ->where('assigned_room_id', $checkout->id)
                    ->where('check_in', '<', $departure)
                    ->where('check_out', '>', $yesterday)
                    ->where(function ($q): void {
                        $q->whereNull('lifecycle_state')
                            ->orWhereNotIn('lifecycle_state', ['cancelled', 'checked_out', 'no_show']);
                    })->exists();
                if ($conflict) {
                    throw new RuntimeException('Synthetic checkout room has an active overlapping stay. Refusing fixture creation.');
                }
                $bookingId = DB::table('reservation_records')->insertGetId([
                    'hotel_id' => $hotel->id, 'room_type_id' => $type->id,
                    'guest_id' => $guest->id, 'assigned_room_id' => $checkout->id,
                    'reference' => $reference, 'check_in' => $yesterday,
                    'check_out' => $departure, 'lifecycle_state' => 'checked_in',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('reservation_booking_events')->insert([
                    'hotel_id' => $hotel->id, 'reservation_record_id' => $bookingId,
                    'actor_id' => $actorId, 'event_type' => 'check_in',
                    'old_state' => 'confirmed', 'new_state' => 'checked_in',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $bookingId = (int) $record->id;
            }

            return [
                'hotel_id' => $hotel->id,
                'hotel_name' => $hotel->name,
                'room_type_id' => $type->id,
                'room_id' => $bookable->id,
                'rate_plan_id' => $rateId,
                'checkout_booking_id' => $bookingId,
            ];
        }, 3);

        $this->command?->info('Phase 43 synthetic fixture ready:');
        foreach ($ids as $key => $value) {
            $this->command?->line($key.'='.$value);
        }
        $this->command?->warn('The checkout booking is deliberately synthetic and due today (Asia/Manila hotel time).');
        $this->command?->warn('Never run Phase 43 browser writes unless PostgreSQL is isolated and contains no operational hotel data.');
    }
}
