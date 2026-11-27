<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class PlatformAccess
{
    public static function allowed(?User $user): bool
    {
        if (! $user || ! $user->is_active || $user->must_change_password) {
            return false;
        }
        $ids = array_map(static fn ($id): string => trim((string) $id), (array) config('platform.admin_ids', []));

        // Dedicated accounts only: a hotel administrator must never be able
        // to manage a platform operator's password or membership permissions.
        return in_array((string) $user->id, $ids, true) && ! $user->memberships()->exists();
    }
}
