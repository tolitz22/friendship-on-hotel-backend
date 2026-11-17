<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->json('changes_before');
            $table->json('changes_after');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['hotel_id', 'subject_type', 'subject_id', 'id'], 'catalog_audit_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_audit_logs');
    }
};
