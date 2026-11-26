<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff_messages', function (Blueprint $table): void {
            $table->string('record_type', 30)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staff_messages', function (Blueprint $table): void {
            $table->dropColumn(['record_type', 'record_id']);
        });
    }
};
