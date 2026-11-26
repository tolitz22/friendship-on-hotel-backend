<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('push_deliveries', function (Blueprint $table): void {
            $table->string('failure_code', 40)->nullable();
            $table->timestamp('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('push_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['failure_code', 'sent_at']);
        });
    }
};
