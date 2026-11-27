<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BootstrapHotelAdmin extends Command
{
    protected $signature = 'hotel:bootstrap-admin {hotel : Existing hotel ID} {email : Admin email}';
    protected $description = 'Create or assign an initial property admin (console only)';

    public function handle(): int
    {
        $hotel = Hotel::findOrFail($this->argument('hotel'));
        $email = strtolower((string) $this->argument('email'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Provide a valid email.');

            return self::FAILURE;
        }

        $password = $this->secret('Password for a new account (leave blank if user exists)');

        $existingUser = User::where('email', $email)->first();
        if (! $existingUser && (! is_string($password) || strlen($password) < 12)) {
            $this->error('A new account requires a password of at least 12 characters.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($hotel, $email, $password): void {
                DB::table('hotels')->where('id', $hotel->id)->lockForUpdate()->first();
                $user = User::where('email', $email)->first();
                if (! $user || ! HotelMembership::where('hotel_id', $hotel->id)->where('user_id', $user->id)->where('is_active', true)->exists()) {
                    app(\App\Services\PlatformSubscriptionCapacity::class)->requireSpace((int) $hotel->id, 'staff');
                }
    
                if (! $user) {
                    $user = User::create([
                        'name' => $this->ask('Staff name'),
                        'email' => $email,
                        'password' => Hash::make($password),
                        'is_active' => true,
                    ]);
                }
    
                HotelMembership::updateOrCreate(
                    ['hotel_id' => $hotel->id, 'user_id' => $user->id],
                    ['role' => 'admin', 'is_active' => true],
                );
            });
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->error($error->errors()['subscriptionLimit'][0] ?? 'Capacity check failed.');
            return self::FAILURE;
        }

        $this->info('Admin membership configured.');

        return self::SUCCESS;
    }
}
