<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Notifications\StaffPasswordResetNotification;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_active', 'must_change_password'];

    // Match the database default on newly created, in-memory Eloquent models.
    // Sanctum::actingAs() uses that instance before it is refreshed from the DB.
    protected $attributes = ['is_active' => true];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_active' => 'boolean', 'must_change_password' => 'boolean'];
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new StaffPasswordResetNotification($token));
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(HotelMembership::class);
    }
}
