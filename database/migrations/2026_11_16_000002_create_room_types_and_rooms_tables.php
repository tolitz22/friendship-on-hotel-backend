<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('max_occupancy');
            $table->timestamps();
            $table->unique(['hotel_id', 'name']);
            $table->unique(['hotel_id', 'id']);
        });

        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_type_id');
            $table->string('number', 32);
            $table->unsignedSmallInteger('floor_number')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'number']);
            $table->foreign(['hotel_id', 'room_type_id'])->references(['hotel_id', 'id'])->on('room_types')->restrictOnDelete();
            $table->index(['hotel_id', 'room_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('room_types');
    }
};
