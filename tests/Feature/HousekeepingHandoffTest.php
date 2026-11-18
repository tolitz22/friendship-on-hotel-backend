<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('prioritizes old unassigned checkout cleaning for managers and notifies only assigned staff', function (): void {
    $hotel = Hotel::create(['name' => 'Handoff Property', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room1 = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '601']);
    $room2 = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => '602']);
    $manager = User::create(['name' => 'Duty Manager', 'email' => 'handoff-manager@example.test',
        'password' => 'synthetic-password', 'is_active' => true]);
    $cleaner = User::create(['name' => 'Room Attendant', 'email' => 'handoff-cleaner@example.test',
        'password' => 'synthetic-password', 'is_active' => true]);
    foreach ([[$manager, 'admin'], [$cleaner, 'housekeeping']] as [$user, $role]) {
        HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $user->id,
            'role' => $role, 'is_active' => true]);
    }

    $aged = DB::table('housekeeping_tasks')->insertGetId([
        'hotel_id' => $hotel->id, 'room_id' => $room1->id,
        'type' => 'checkout_clean', 'status' => 'todo',
        'assigned_to' => null, 'assigned_user_id' => null,
        'revision' => 1, 'created_by' => $manager->id,
        'created_at' => now()->subMinutes(180), 'updated_at' => now()->subMinutes(180),
    ]);
    $assigned = DB::table('housekeeping_tasks')->insertGetId([
        'hotel_id' => $hotel->id, 'room_id' => $room2->id,
        'type' => 'checkout_clean', 'status' => 'todo',
        'assigned_to' => null, 'assigned_user_id' => $cleaner->id,
        'revision' => 1, 'created_by' => $manager->id,
        'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10),
    ]);

    $base = '/api/v1/hotels/'.$hotel->id.'/housekeeping';
    Sanctum::actingAs($manager, ['hotel:read', 'hotel:write']);
    $this->getJson($base.'/handoff')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.taskId', (string) $aged)
        ->assertJsonPath('data.0.roomNumber', '601')
        ->assertJsonPath('data.0.priority', 'escalate')
        ->assertJsonPath('meta.changesReadiness', false);

    Sanctum::actingAs($cleaner, ['hotel:read']);
    $this->getJson($base.'/handoff')->assertForbidden();
    $this->getJson($base.'/my-assignments')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.taskId', (string) $assigned)
        ->assertJsonPath('meta.externalNotificationsConfigured', false);
});
