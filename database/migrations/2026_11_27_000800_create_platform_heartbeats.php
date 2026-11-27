<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_heartbeats', function (Blueprint $table): void {
            $table->string('name', 32)->primary();
            $table->unsignedBigInteger('observed_at');
            $table->string('outcome', 24);
            $table->string('service_role', 24);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_heartbeats');
    }
};
