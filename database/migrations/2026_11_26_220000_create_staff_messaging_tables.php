<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('title', 120)->nullable();
            $table->string('direct_key', 100)->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'direct_key']);
        });
        Schema::create('staff_conversation_members', function (Blueprint $table): void {
            $table->foreignId('conversation_id')->constrained('staff_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();
            $table->primary(['conversation_id', 'user_id']);
            $table->index(['user_id', 'conversation_id']);
        });
        Schema::create('staff_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('staff_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_id');
            $table->text('body');
            $table->timestamps();
            $table->unique(['conversation_id', 'sender_id', 'client_id']);
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_messages');
        Schema::dropIfExists('staff_conversation_members');
        Schema::dropIfExists('staff_conversations');
    }
};
