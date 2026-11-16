<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use Illuminate\Console\Command;

class CreateHotel extends Command
{
    protected $signature = 'hotel:create {name : Property name}';
    protected $description = 'Create an initial property from the operator console';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '') {
            $this->error('A property name is required.');

            return self::FAILURE;
        }

        $hotel = Hotel::create(['name' => $name, 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
        $this->info('Created property ID '.$hotel->id);

        return self::SUCCESS;
    }
}
