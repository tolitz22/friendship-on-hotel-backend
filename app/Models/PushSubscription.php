<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PushSubscription extends Model
{
    protected $fillable = ['hotel_id', 'user_id', 'endpoint_hash', 'endpoint', 'p256dh', 'auth', 'device_name'];

    protected $hidden = ['endpoint', 'endpoint_hash', 'p256dh', 'auth'];

    protected function casts(): array
    {
        return ['endpoint' => 'encrypted', 'p256dh' => 'encrypted', 'auth' => 'encrypted'];
    }
}
