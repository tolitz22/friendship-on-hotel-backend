<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reservation_policy_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->json('decisions');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('reservation_policy_draft_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('decisions');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'revision'], 'res_policy_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_policy_draft_revisions');
        Schema::dropIfExists('reservation_policy_drafts');
    }
};
