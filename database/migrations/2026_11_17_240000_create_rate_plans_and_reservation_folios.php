<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('room_rate_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_type_id');
            $table->string('name', 100);
            $table->unsignedBigInteger('nightly_minor');
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['hotel_id', 'room_type_id', 'name'], 'rate_plan_unique_name');
            $table->foreign(['hotel_id', 'room_type_id'], 'rate_plan_hotel_type_fk')
                ->references(['hotel_id', 'id'])->on('room_types')->restrictOnDelete();
        });

        Schema::create('reservation_folios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_record_id')->unique()
                ->constrained('reservation_records')->restrictOnDelete();
            $table->char('currency', 3);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('folio_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('folio_id')->constrained('reservation_folios')->restrictOnDelete();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->string('entry_type', 24);
            $table->string('description', 200);
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('rate_plan_id')->nullable()->constrained('room_rate_plans')->restrictOnDelete();
            $table->date('stay_date')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['folio_id', 'stay_date', 'entry_type'], 'folio_night_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_entries');
        Schema::dropIfExists('reservation_folios');
        Schema::dropIfExists('room_rate_plans');
    }
};
