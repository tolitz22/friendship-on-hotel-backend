<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PlatformHotelSubscription extends Model
{
    protected $fillable = ['hotel_id', 'plan_id', 'plan_revision', 'plan_name', 'currency', 'cycle', 'price_minor',
        'room_limit', 'staff_limit', 'kind', 'starts_on', 'expires_on', 'trial_used', 'revision'];

    protected function casts(): array
    {
        return ['plan_revision' => 'integer', 'price_minor' => 'integer', 'room_limit' => 'integer',
            'staff_limit' => 'integer', 'trial_used' => 'boolean', 'revision' => 'integer',
            'starts_on' => 'immutable_date', 'expires_on' => 'immutable_date'];
    }
}
