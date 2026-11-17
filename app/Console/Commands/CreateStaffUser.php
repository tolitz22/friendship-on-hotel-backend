<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

final class CreateStaffUser extends Command
{
    protected $signature = 'hotel:staff-user {email : Staff login email} {name : Staff display name}';
    protected $description = 'Provision an existing-user login for later assignment to a hotel (does not grant hotel access).';

    public function handle(): int
    {
        $email=strtolower(trim((string)$this->argument('email')));
        $name=trim((string)$this->argument('name'));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($name)<2 || mb_strlen($name)>120) {
            $this->error('Provide a valid email address and a name of 2–120 characters.');
            return self::FAILURE;
        }
        if (User::where('email',$email)->exists()) {
            $this->error('A user with that email already exists. No changes made.');
            return self::FAILURE;
        }
        $password=(string)$this->secret('Set a temporary password (at least 12 characters)');
        if (mb_strlen($password)<12) {
            $this->error('Password must be at least 12 characters. No account created.');
            return self::FAILURE;
        }
        $confirmation=(string)$this->secret('Confirm temporary password');
        if (!hash_equals($password,$confirmation)) {
            $this->error('Passwords do not match. No account created.');
            return self::FAILURE;
        }
        User::create([
            'name'=>$name,'email'=>$email,
            'password'=>Hash::make($password),'is_active'=>true,
        ]);
        $this->info('Staff login created. This account has no hotel access until an administrator adds its membership.');
        $this->warn('Have the staff member change their temporary password through a secure, approved process before production use.');
        return self::SUCCESS;
    }
}
