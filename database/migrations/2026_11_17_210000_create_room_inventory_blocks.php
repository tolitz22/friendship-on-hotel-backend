<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('room_inventory_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_type_id');
            $table->foreignId('room_id');
            $table->date('check_in');
            $table->date('check_out');
            $table->string('reason', 300);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->foreign(['hotel_id', 'room_type_id', 'room_id'], 'inventory_block_room_scope_fk')
                ->references(['hotel_id', 'room_type_id', 'id'])->on('rooms')->restrictOnDelete();
            $table->index(['hotel_id', 'room_type_id', 'check_in', 'check_out'], 'inv_block_nights_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_inventory_blocks');
    }
};
