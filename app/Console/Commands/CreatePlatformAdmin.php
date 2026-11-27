<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

final class CreatePlatformAdmin extends Command
{
    protected $signature = 'pms:platform-admin-create';
    protected $description = 'Create a dedicated operator account without hotel memberships or automatic privileges';

    public function handle(): int
    {
        $name = $this->ask('Operator name');
        $email = $this->ask('Operator email');
        $password = $this->secret('New password (at least 16 characters)');
        $confirmation = $this->secret('Confirm password');
        $validator = Validator::make(['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation], [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'min:16', 'max:72', 'confirmed'],
        ]);
        if ($validator->fails()) {
            $this->error('Invalid details, existing email, or password mismatch. No account created.');

            return self::FAILURE;
        }
        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password, 'is_active' => true, 'must_change_password' => false]);
        $this->info('Dedicated account created. User ID: '.$user->id);
        $this->line('Explicitly add this ID to PMS_PLATFORM_ADMIN_IDS and redeploy. No hotel memberships were granted.');

        return self::SUCCESS;
    }
}
