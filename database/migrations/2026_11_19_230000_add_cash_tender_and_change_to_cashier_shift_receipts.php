<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_shift_receipts', function (Blueprint $table): void {
            // Nullable solely for receipts recorded before tender/change capture.
            // Do not fabricate a cash tender for historical receipts.
            $table->unsignedBigInteger('cash_tendered_minor')->nullable();
            $table->unsignedBigInteger('change_given_minor')->nullable();
            $table->unsignedBigInteger('net_receipt_minor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cashier_shift_receipts', function (Blueprint $table): void {
            $table->dropColumn(['cash_tendered_minor', 'change_given_minor', 'net_receipt_minor']);
        });
    }
};
