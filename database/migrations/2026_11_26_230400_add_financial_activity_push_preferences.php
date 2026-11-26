<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('push_preferences', function (Blueprint $table): void {
            $table->boolean('payment_recorded')->default(true);
            $table->boolean('payment_pending_review')->default(true);
            $table->boolean('payment_verified')->default(true);
            $table->boolean('payment_rejected')->default(true);
            $table->boolean('payment_voided')->default(true);
            $table->boolean('refund_requested')->default(true);
            $table->boolean('refund_verified')->default(true);
            $table->boolean('expense_recorded')->default(true);
            $table->boolean('expense_correction_requested')->default(true);
            $table->boolean('cashier_discrepancy')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('push_preferences', function (Blueprint $table): void {
            $table->dropColumn(['payment_recorded', 'payment_pending_review', 'payment_verified', 'payment_rejected', 'payment_voided', 'refund_requested', 'refund_verified', 'expense_recorded', 'expense_correction_requested', 'cashier_discrepancy']);
        });
    }
};
