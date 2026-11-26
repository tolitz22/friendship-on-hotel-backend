<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained('staff_messages')->cascadeOnDelete();
            $table->string('filename', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            $table->longText('encoded_content');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_message_attachments');
    }
};
