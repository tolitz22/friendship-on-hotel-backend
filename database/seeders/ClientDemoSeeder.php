<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ClientDemoSeeder extends Seeder
{
    private const PROPERTY = 'Friendship On Hotel — Client Demo';

    public function run(): void
    {
        $email = trim((string) getenv('PMS_DEMO_ADMIN_EMAIL'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Set PMS_DEMO_ADMIN_EMAIL to an existing hotel administrator’s login email.');
        }

        $hotelId = DB::transaction(function () use ($email): ?int {
            $admin = User::query()->where('email', $email)->where('is_active', true)
                ->whereHas('memberships', fn ($query) => $query->where('role', 'admin')->where('is_active', true))
                ->lockForUpdate()->first();
            if (! $admin) {
                throw new RuntimeException('The specified account must already be an active hotel administrator.');
            }

            if (Hotel::query()->where('name', self::PROPERTY)->exists()) {
                return null;
            }

            $today = now('Asia/Manila')->toImmutable()->startOfDay();
            $day = fn (int $offset): string => $today->addDays($offset)->toDateString();
            $stamps = ['created_at' => now(), 'updated_at' => now()];
            $hotel = Hotel::create(['name' => self::PROPERTY, 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
            $scope = ['hotel_id' => $hotel->id];
            $admin->memberships()->create([...$scope, 'role' => 'admin', 'is_active' => true]);

            // Inactive staff identities supply display names without granting login access.
            $recorder = User::firstOrCreate(['email' => 'client-demo-cashier@example.test'], [
                'name' => 'Ella Bautista (Demo)', 'password' => Str::random(64), 'is_active' => false,
            ]);
            $reviewer = User::firstOrCreate(['email' => 'client-demo-reviewer@example.test'], [
                'name' => 'Mika Flores (Demo)', 'password' => Str::random(64), 'is_active' => false,
            ]);
            if ($recorder->is_active || $reviewer->is_active) {
                throw new RuntimeException('A synthetic staff email is already attached to an active account.');
            }

            $rooms = [];
            $rates = [];
            foreach ([
                ['Standard', 2, 250000, ['201', '202', '203']],
                ['Deluxe', 3, 420000, ['301', '302', '303']],
                ['Family', 4, 560000, ['401', '402']],
            ] as [$name, $capacity, $nightly, $numbers]) {
                $type = $hotel->roomTypes()->create(['name' => $name, 'max_occupancy' => $capacity]);
                $rateId = DB::table('room_rate_plans')->insertGetId([
                    ...$scope, ...$stamps, 'room_type_id' => $type->id, 'name' => $name.' Demo Rate',
                    'nightly_minor' => $nightly, 'currency' => 'PHP', 'created_by' => $admin->id,
                ]);
                $rates[$name] = [$type->id, $rateId, $nightly];
                foreach ($numbers as $number) {
                    $rooms[$number] = $hotel->rooms()->create([
                        'room_type_id' => $type->id, 'number' => $number, 'floor_number' => (int) $number[0],
                    ])->id;
                }
            }

            foreach ([
                ['FOH-DEMO-1001', 'Maria', 'Santos', 'Deluxe', '301', 0, 2, 'confirmed', 420000, true],
                ['FOH-DEMO-1002', 'James', 'Reyes', 'Standard', null, 0, 3, 'confirmed', 250000, false],
                ['FOH-DEMO-1003', 'Anna', 'Cruz', 'Family', '401', -2, 0, 'checked_in', 1120000, true],
                ['FOH-DEMO-1004', 'Lia', 'Mendoza', 'Standard', '202', -1, 2, 'checked_in', 0, false],
                ['FOH-DEMO-1005', 'Nico', 'Villanueva', 'Deluxe', '302', 3, 5, 'confirmed', 0, false],
            ] as [$reference, $first, $last, $roomType, $room, $arrival, $departure, $state, $paid, $reviewed]) {
                [$typeId, $rateId, $nightly] = $rates[$roomType];
                $guest = $hotel->guests()->create([
                    'first_name' => $first, 'last_name' => $last,
                    'email' => strtolower($reference).'@example.test',
                ]);
                $bookingId = DB::table('reservation_records')->insertGetId([
                    ...$scope, ...$stamps, 'room_type_id' => $typeId, 'guest_id' => $guest->id,
                    'assigned_room_id' => $room === null ? null : $rooms[$room],
                    'reference' => $reference, 'check_in' => $day($arrival),
                    'check_out' => $day($departure), 'lifecycle_state' => $state,
                ]);
                $folioId = DB::table('reservation_folios')->insertGetId([
                    ...$scope, ...$stamps, 'reservation_record_id' => $bookingId,
                    'currency' => 'PHP', 'created_by' => $admin->id,
                ]);
                for ($offset = $arrival; $offset < $departure; $offset++) {
                    DB::table('folio_entries')->insert([
                        ...$scope, ...$stamps, 'folio_id' => $folioId, 'entry_type' => 'room_night',
                        'description' => $roomType.' demo room night', 'amount_minor' => $nightly,
                        'rate_plan_id' => $rateId, 'stay_date' => $day($offset), 'actor_id' => $admin->id,
                    ]);
                }
                if ($paid > 0) {
                    DB::table('folio_payment_records')->insert([
                        ...$scope, ...$stamps, 'folio_id' => $folioId,
                        'request_key' => $reference.'-PAY', 'payload_sha256' => hash('sha256', $reference.'-PAY'),
                        'method' => 'bank_transfer', 'reference' => $reference.'-TRANSFER', 'amount_minor' => $paid,
                        'status' => $reviewed ? 'staff_confirmed' : 'pending_review',
                        'recorded_by' => $recorder->id, 'reviewed_by' => $reviewed ? $reviewer->id : null,
                        'review_note' => $reviewed ? 'Fictional payment reviewed for client demonstration.' : null,
                    ]);
                }
            }

            DB::table('housekeeping_tasks')->insert([
                ...$scope, ...$stamps, 'room_id' => $rooms['203'], 'type' => 'checkout_clean',
                'status' => 'todo', 'due_at' => $today->setHour(14), 'created_by' => $admin->id,
            ]);
            DB::table('maintenance_work_orders')->insert([
                ...$scope, ...$stamps, 'room_id' => $rooms['303'], 'title' => 'Air-conditioning repair',
                'description' => 'Fictional out-of-order room for the client demonstration.',
                'priority' => 'high', 'status' => 'in_progress', 'affects_room' => true, 'created_by' => $admin->id,
            ]);
            DB::table('room_inventory_blocks')->insert([
                ...$scope, ...$stamps, 'room_type_id' => $rates['Deluxe'][0], 'room_id' => $rooms['303'],
                'check_in' => $day(0), 'check_out' => $day(3),
                'reason' => 'Demo air-conditioning repair', 'created_by' => $admin->id,
            ]);

            return (int) $hotel->id;
        }, 3);

        if ($hotelId === null) {
            $this->command?->info('Client Demo already exists. Existing records were kept.');
            return;
        }

        $this->command?->info(self::PROPERTY.' created (hotel ID '.$hotelId.').');
    }
}
