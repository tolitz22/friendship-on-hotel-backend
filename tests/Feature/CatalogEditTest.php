<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function editCatalogStaff(Hotel $hotel, string $role = 'admin', array $abilities = ['hotel:read','hotel:write']): void
{
    $staff = User::create([
        'name' => 'Editor',
        'email' => 'editor-'.uniqid().'@example.test',
        'password' => 'editor-test-password',
    ]);
    HotelMembership::create(['hotel_id' => $hotel->id, 'user_id' => $staff->id, 'role' => $role, 'is_active' => true]);
    Sanctum::actingAs($staff, $abilities);
}

function editCatalogSeed(Hotel $hotel): array
{
    $type = RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Standard','max_occupancy'=>2]);
    $room = Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'101','floor_number'=>1]);
    return [$type, $room];
}

function editReservation(Hotel $hotel, RoomType $type, ?Room $room, string $reference): void
{
    DB::table('reservation_records')->insert([
        'hotel_id'=>$hotel->id,'room_type_id'=>$type->id,
        'assigned_room_id'=>$room?->id,'reference'=>$reference,
        'check_in'=>'2026-11-17','check_out'=>'2026-11-20',
    ]);
}

it('edits physical room identifiers and records exact actor-linked before and after history', function (): void {
    $hotel = Hotel::create(['name'=>'Audit Hotel']);
    [$type, $room] = editCatalogSeed($hotel);
    editCatalogStaff($hotel);

    $url='/api/v1/hotels/'.$hotel->id;
    $this->patchJson($url.'/rooms/'.$room->id, ['number'=>'102','floor'=>null])
        ->assertOk()->assertJsonPath('data.number','102')->assertJsonPath('data.floor',null);
    $this->getJson($url.'/catalog-audit?subjectType=room&subjectId='.$room->id)
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.before.number','101')
        ->assertJsonPath('data.0.after.number','102')
        ->assertJsonPath('data.0.before.floor',1)
        ->assertJsonPath('data.0.after.floor',null);

    // Repeating a no-op update does not produce a misleading audit event.
    $this->patchJson($url.'/rooms/'.$room->id, ['number'=>'102'])->assertOk();
    expect(DB::table('catalog_audit_logs')->where('hotel_id',$hotel->id)->count())->toBe(1);
    expect($room->fresh()->room_type_id)->toBe($type->id);
});

it('edits room type metadata and blocks capacity changes referenced by reservations', function (): void {
    $hotel = Hotel::create(['name'=>'Type Audit Hotel']);
    [$type,$room]=editCatalogSeed($hotel);
    editCatalogStaff($hotel);
    $base='/api/v1/hotels/'.$hotel->id.'/room-types/'.$type->id;
    $this->patchJson($base,['name'=>'Classic','maxOccupancy'=>3])
        ->assertOk()->assertJsonPath('data.name','Classic')->assertJsonPath('data.maxOccupancy',3);
    editReservation($hotel,$type,null,'REF-1');

    $this->patchJson($base,['maxOccupancy'=>4])
        ->assertUnprocessable()->assertJsonValidationErrors(['maxOccupancy']);
    $this->patchJson($base,['name'=>'Classic Plus'])->assertOk()
        ->assertJsonPath('data.name','Classic Plus');

    expect((int) $type->fresh()->max_occupancy)->toBe(3);
    $this->getJson('/api/v1/hotels/'.$hotel->id.'/catalog-audit?subjectType=room_type&subjectId='.$type->id)
        ->assertOk()->assertJsonCount(2, 'data');
});

it('prevents room type reassignment if room or its type has reservations', function (): void {
    $hotel=Hotel::create(['name'=>'Assignment Hotel']);
    [$type,$room]=editCatalogSeed($hotel);
    $suite=RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Suite','max_occupancy'=>4]);
    editReservation($hotel,$type,null,'UNASSIGNED');
    editCatalogStaff($hotel);

    $this->patchJson('/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id,[
        'roomTypeId'=>(string) $suite->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['roomTypeId']);
    expect($room->fresh()->room_type_id)->toBe($type->id);
    expect(DB::table('catalog_audit_logs')->count())->toBe(0);
});

it('allows safe room type reassignment when there are no references', function (): void {
    $hotel=Hotel::create(['name'=>'Safe Assignment']);
    [$type,$room]=editCatalogSeed($hotel);
    $suite=RoomType::create(['hotel_id'=>$hotel->id,'name'=>'Suite','max_occupancy'=>4]);
    editCatalogStaff($hotel);

    $this->patchJson('/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id, [
        'roomTypeId'=>(string) $suite->id,
    ])->assertOk()->assertJsonPath('data.roomTypeId',(string)$suite->id);
    expect((int)$room->fresh()->room_type_id)->toBe((int)$suite->id);
});

it('blocks cross-property catalogue updates and prevents audit-history leaks', function (): void {
    $a=Hotel::create(['name'=>'Hotel A']);
    $b=Hotel::create(['name'=>'Hotel B']);
    [$foreignType,$foreignRoom]=editCatalogSeed($b);
    [$ownType,$ownRoom]=editCatalogSeed($a);
    editCatalogStaff($a);
    $url='/api/v1/hotels/'.$a->id;
    $this->patchJson($url.'/rooms/'.$foreignRoom->id,['number'=>'888'])->assertNotFound();
    $this->patchJson($url.'/room-types/'.$foreignType->id,['name'=>'Changed'])->assertNotFound();
    $this->patchJson($url.'/rooms/'.$ownRoom->id,['roomTypeId'=>(string)$foreignType->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['roomTypeId']);
    $this->getJson($url.'/catalog-audit?subjectType=room&subjectId='.$foreignRoom->id)->assertNotFound();
    expect($foreignRoom->fresh()->number)->toBe('101');
    expect($foreignType->fresh()->name)->toBe('Standard');
    expect($ownType->hotel_id)->toBe($a->id);
});

it('enforces admin membership, token abilities and catalogue uniqueness', function (): void {
    $hotel=Hotel::create(['name'=>'Permission Hotel']);
    [$type,$room]=editCatalogSeed($hotel);
    Room::create(['hotel_id'=>$hotel->id,'room_type_id'=>$type->id,'number'=>'102','floor_number'=>1]);
    $url='/api/v1/hotels/'.$hotel->id.'/rooms/'.$room->id;
    $this->patchJson($url,['number'=>'201'])->assertUnauthorized();
    editCatalogStaff($hotel,'front_desk',['hotel:read','hotel:write']);
    $this->patchJson($url,['number'=>'201'])->assertForbidden();
    editCatalogStaff($hotel,'admin',['hotel:read']);
    $this->patchJson($url,['number'=>'201'])->assertForbidden();
    editCatalogStaff($hotel);
    $this->patchJson($url,['number'=>'102'])->assertUnprocessable()->assertJsonValidationErrors(['number']);
    $this->patchJson($url,['floor'=>-1])->assertUnprocessable();
    expect(DB::table('catalog_audit_logs')->count())->toBe(0);
});
