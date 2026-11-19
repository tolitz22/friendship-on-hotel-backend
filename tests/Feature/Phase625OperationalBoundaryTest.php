<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function p625Staff(Hotel $hotel, string $role, string $name): User
{
    $user = User::create([
        'name' => $name,
        'email' => 'p625-'.uniqid('', true).'@example.test',
        'password' => 'test-password-not-for-real-use',
    ]);
    HotelMembership::create([
        'hotel_id' => $hotel->id, 'user_id' => $user->id,
        'role' => $role, 'is_active' => true,
    ]);
    return $user;
}

it('keeps room readiness approval and independent occupancy evidence separate from registered stays', function (): void {
    $hotel = Hotel::create(['name' => 'Acceptance Boundary Hotel', 'currency' => 'PHP', 'timezone' => 'Asia/Manila']);
    $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Standard', 'max_occupancy' => 2]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'number' => 'A625-101']);
    $inspector = p625Staff($hotel, 'admin', 'Inspector Manager');
    $reviewer = p625Staff($hotel, 'admin', 'Independent Reviewer');
    $housekeeper = p625Staff($hotel, 'housekeeping', 'Independent Housekeeper');
    $base = '/api/v1/hotels/'.$hotel->id;
    $readiness = $base.'/rooms/'.$room->id.'/readiness';
    $occupancy = $base.'/occupancy-verifications';

    Sanctum::actingAs($inspector, ['hotel:read', 'hotel:write']);
    $this->getJson($readiness)->assertOk()->assertJsonPath('data.advisoryReady', false);
    $this->postJson($readiness.'/inspections', [
        'cleaningVerified' => true, 'safetyVerified' => true, 'amenitiesVerified' => true,
        'note' => 'Physical room checks completed independently of occupancy.',
    ])->assertCreated()->assertJsonPath('data.inspection.status', 'approved')
      ->assertJsonPath('data.advisoryReady', true);

    // A ready room can still have a physical occupancy anomaly. Neither evidence
    // source must silently manufacture or modify a registered guest stay.
    $checkId = $this->postJson($occupancy, [
        'roomId' => $room->id, 'assignedUserId' => $housekeeper->id,
        'inspectionDate' => now('Asia/Manila')->toDateString(),
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($housekeeper, ['hotel:read']);
    $this->postJson($occupancy.'/'.$checkId.'/observe', [
        'observedOccupancy' => 'occupied',
        'note' => 'Observed signs of occupancy from an authorized public area.',
    ])->assertOk()->assertJsonPath('data.discrepancy', 'unexpected_occupied')
      ->assertJsonPath('data.status', 'submitted');
    $this->postJson($occupancy.'/'.$checkId.'/review', [
        'decision' => 'verified', 'note' => 'Housekeeping cannot approve findings.',
    ])->assertForbidden();

    Sanctum::actingAs($reviewer, ['hotel:read', 'hotel:write']);
    $this->postJson($occupancy.'/'.$checkId.'/review', [
        'decision' => 'verified', 'note' => 'A physical mismatch cannot be verified as matched.',
    ])->assertStatus(409);
    $this->postJson($occupancy.'/'.$checkId.'/review', [
        'decision' => 'flagged',
        'note' => 'Follow up physical evidence and reception registration separately.',
    ])->assertOk()->assertJsonPath('data.status', 'flagged');

    $this->getJson($readiness)->assertOk()->assertJsonPath('data.inspection.status', 'approved');
    $this->getJson($occupancy.'/'.$checkId.'/history')->assertOk()
       ->assertJsonPath('data.1.actorName', $housekeeper->name);
    expect(DB::table('reservation_records')->where('hotel_id', $hotel->id)->count())->toBe(0);
    expect(DB::table('folio_payment_records')->where('hotel_id', $hotel->id)->count())->toBe(0);
});
