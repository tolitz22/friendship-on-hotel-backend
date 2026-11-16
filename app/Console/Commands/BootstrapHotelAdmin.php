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

        DB::transaction(function () use ($hotel, $email, $password): void {
            $user = User::where('email', $email)->first();

            if (! $user) {
                if (! is_string($password) || strlen($password) < 12) {
                    throw new \InvalidArgumentException('A new account requires a password of at least 12 characters.');
                }

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

        $this->info('Admin membership configured.');

        return self::SUCCESS;
    }
}
