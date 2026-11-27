<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_subscription_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('platform_subscription_invoices')->restrictOnDelete();
            $table->string('method', 24);
            $table->string('reference', 120);
            $table->char('reference_key', 64)->unique('platform_sub_payment_ref_uq');
            $table->string('evidence_reference', 200);
            $table->unsignedBigInteger('amount_minor');
            $table->date('received_on');
            $table->string('status', 16)->default('pending');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->boolean('self_reviewed')->default(false);
            $table->timestamps();
            $table->index(['invoice_id', 'status'], 'platform_sub_payment_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_subscription_payments');
    }
};
