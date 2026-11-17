<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_special_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_record_id')->constrained('reservation_records')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->char('payload_sha256',64);
            $table->string('tag',40);
            $table->text('note');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['hotel_id','request_key'],'special_req_idempotency');
            $table->index(['hotel_id','reservation_record_id','id'],'special_req_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_special_requests');
    }
};
