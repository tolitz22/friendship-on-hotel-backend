<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->string('first_name', 120);
            $table->string('last_name', 120);
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('nationality', 100)->nullable();
            $table->timestamps();

            $table->index(['hotel_id', 'last_name', 'first_name'], 'guests_hotel_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
