<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('group_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->string('reference', 48);
            $table->string('name', 120);
            $table->string('contact_name', 120)->nullable();
            $table->string('idempotency_key', 100);
            $table->char('payload_sha256', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['hotel_id', 'id']);
            $table->unique(['hotel_id', 'reference']);
            $table->unique(['hotel_id', 'idempotency_key'], 'group_booking_idempotency');
        });

        Schema::create('group_reservation_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id');
            $table->foreignId('group_reservation_id');
            $table->foreignId('reservation_record_id');
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->foreign(['hotel_id', 'group_reservation_id'], 'group_member_group_fk')
                ->references(['hotel_id', 'id'])->on('group_reservations')->restrictOnDelete();
            $table->foreign(['reservation_record_id'])->references(['id'])
                ->on('reservation_records')->restrictOnDelete();
            $table->unique(['hotel_id', 'reservation_record_id'], 'group_member_booking_unique');
            $table->unique(['group_reservation_id', 'position'], 'group_member_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_reservation_members');
        Schema::dropIfExists('group_reservations');
    }
};
