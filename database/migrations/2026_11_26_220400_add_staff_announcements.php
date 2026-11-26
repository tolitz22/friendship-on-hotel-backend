<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff_messages', function (Blueprint $table): void {
            $table->boolean('is_announcement')->default(false);
        });
        Schema::create('staff_announcement_recipients', function (Blueprint $table): void {
            $table->foreignId('message_id')->constrained('staff_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->primary(['message_id','user_id']);
            $table->index(['user_id','acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_announcement_recipients');
        Schema::table('staff_messages', function (Blueprint $table): void {
            $table->dropColumn('is_announcement');
        });
    }
};
