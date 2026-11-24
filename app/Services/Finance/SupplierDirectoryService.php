<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SupplierDirectoryService
{
    public function present(object $row): array
    {
        return [
            'id' => (string) $row->id, 'hotelId' => (string) $row->hotel_id,
            'name' => $row->name, 'contactName' => $row->contact_name,
            'email' => $row->email, 'phone' => $row->phone,
            'paymentTermsDays' => (int) $row->payment_terms_days,
            'isActive' => (bool) $row->is_active,
            'version' => $this->version($row),
        ];
    }

    private function version(object $row): string
    {
        return hash('sha256', json_encode([$row->name, $row->contact_name, $row->email,
            $row->phone, (int) $row->payment_terms_days, (bool) $row->is_active, (string) $row->updated_at], JSON_THROW_ON_ERROR));
    }

    public function save(Hotel $hotel, int $actor, ?int $supplierId, array $input): array
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:160', 'regex:/\\S/u'],
            'contactName' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:64'],
            'paymentTermsDays' => ['required', 'integer', 'min:0', 'max:365'],
            'isActive' => ['required', 'boolean'],
            'version' => [$supplierId === null ? 'nullable' : 'required', 'string', 'size:64'],
        ])->validate();
        $name = trim(preg_replace('/\\s+/u', ' ', $data['name']));
        $normalized = mb_strtolower($name, 'UTF-8');
        $optional = static fn (string $key): ?string => isset($data[$key]) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        $facts = [
            'name' => $name, 'normalized_name' => $normalized,
            'contact_name' => $optional('contactName'), 'email' => $optional('email'),
            'phone' => $optional('phone'), 'payment_terms_days' => (int) $data['paymentTermsDays'],
            'is_active' => (bool) $data['isActive'],
        ];
        return DB::transaction(function () use ($hotel, $actor, $supplierId, $data, $facts, $normalized): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotel->id)
                ->where('user_id', $actor)->where('role', 'admin')->where('is_active', true)->exists()
                && DB::table('users')->where('id', $actor)->where('is_active', true)->exists(), 403);
            $query = DB::table('hotel_suppliers')->where('hotel_id', $hotel->id);
            $row = $supplierId === null ? null : (clone $query)->where('id', $supplierId)->lockForUpdate()->first();
            if ($supplierId !== null) {
                abort_unless($row !== null, 404);
                abort_unless(hash_equals($this->version($row), $data['version']), 409, 'Supplier changed. Reload before editing.');
            }
            $duplicate = (clone $query)->where('normalized_name', $normalized)
                ->when($supplierId !== null, fn ($q) => $q->where('id', '<>', $supplierId))->exists();
            abort_if($duplicate, 409, 'A supplier with this name already exists. Search active and inactive suppliers.');
            if ($supplierId === null) {
                $supplierId = DB::table('hotel_suppliers')->insertGetId($facts + [
                    'hotel_id' => $hotel->id, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                (clone $query)->where('id', $supplierId)->update($facts + ['updated_at' => now()]);
            }
            return $this->present((clone $query)->where('id', $supplierId)->first());
        }, 3);
    }
}
