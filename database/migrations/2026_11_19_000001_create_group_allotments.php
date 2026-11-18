<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('group_allotments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->string('reference', 48);
            $table->string('name', 120);
            $table->foreignId('room_type_id');
            $table->unsignedSmallInteger('quantity');
            $table->date('check_in');
            $table->date('check_out');
            $table->timestamp('expires_at');
            $table->string('status', 16)->default('held');
            $table->string('idempotency_key', 100);
            $table->char('payload_sha256', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('converted_group_id')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'reference']);
            $table->unique(['hotel_id', 'idempotency_key'], 'group_allotment_request_unique');
            $table->index(['hotel_id', 'room_type_id', 'status', 'expires_at'], 'group_allotment_inventory_idx');
            $table->foreign(['hotel_id', 'room_type_id'], 'group_allotment_type_fk')
                ->references(['hotel_id', 'id'])->on('room_types')->restrictOnDelete();
            $table->foreign(['hotel_id', 'converted_group_id'], 'group_allotment_conversion_fk')
                ->references(['hotel_id', 'id'])->on('group_reservations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_allotments');
    }
};
