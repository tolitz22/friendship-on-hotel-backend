<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hotel extends Model
{
    protected $fillable = ['name', 'timezone', 'currency', 'address', 'contact_email', 'contact_phone'];

    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function reservationRecords(): HasMany
    {
        return $this->hasMany(ReservationRecord::class);
    }

    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(HotelMembership::class);
    }
}
