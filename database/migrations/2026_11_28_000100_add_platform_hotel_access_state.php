<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table): void {
            $table->string('platform_access_state', 16)->default('active');
            $table->unsignedInteger('platform_access_revision')->default(0);
        });
        Schema::create('platform_hotel_access_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision');
            $table->string('from_state', 16);
            $table->string('to_state', 16);
            $table->string('reason', 500);
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'revision'], 'platform_hotel_access_rev_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_hotel_access_changes');
        Schema::table('hotels', function (Blueprint $table): void {
            $table->dropColumn(['platform_access_state', 'platform_access_revision']);
        });
    }
};
