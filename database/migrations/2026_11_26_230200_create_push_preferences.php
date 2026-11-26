<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('push_preferences', function (Blueprint $table): void {
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->boolean('new_booking')->default(true);
            $table->boolean('checkout_due')->default(true);
            $table->boolean('checkout_overdue')->default(true);
            $table->boolean('room_assignment')->default(true);
            $table->timestamps();
            $table->primary(['hotel_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_preferences');
    }
};
