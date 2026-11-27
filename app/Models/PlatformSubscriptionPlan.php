<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PlatformSubscriptionPlan extends Model
{
    protected $fillable = ['name', 'code', 'description', 'currency', 'monthly_minor', 'annual_minor', 'room_limit', 'staff_limit', 'is_active', 'revision'];

    protected function casts(): array
    {
        return ['monthly_minor' => 'integer', 'annual_minor' => 'integer', 'room_limit' => 'integer', 'staff_limit' => 'integer', 'is_active' => 'boolean', 'revision' => 'integer'];
    }
}
