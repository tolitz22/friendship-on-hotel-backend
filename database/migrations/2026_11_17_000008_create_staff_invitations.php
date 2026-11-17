<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->string('email',255);
            $table->string('name',120);
            $table->string('role',32);
            $table->string('token_hash',64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['hotel_id','email','created_at'],'staff_invites_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
