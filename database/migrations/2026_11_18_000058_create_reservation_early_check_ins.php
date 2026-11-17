<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_early_check_ins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_record_id')->constrained('reservation_records')->restrictOnDelete();
            $table->foreignId('approval_request_id')->unique()->constrained('manager_approval_requests')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('checked_in_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->date('scheduled_check_in');
            $table->date('scheduled_check_out');
            $table->date('actual_check_in_date');
            $table->timestamp('actual_checked_in_at');
            $table->text('reason');
            $table->text('manager_note');
            $table->timestamps();
            $table->unique(['hotel_id','reservation_record_id'], 'early_checkin_once_per_booking');
            $table->index(['hotel_id','actual_check_in_date'], 'early_checkin_hotel_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_early_check_ins');
    }
};
