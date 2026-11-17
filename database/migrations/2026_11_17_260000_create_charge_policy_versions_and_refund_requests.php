<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hotel_charge_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('tax_basis_points')->default(0);
            $table->unsignedInteger('service_basis_points')->default(0);
            $table->string('tax_label', 100);
            $table->string('service_label', 100);
            $table->string('status', 24)->default('draft');
            $table->string('reason', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['hotel_id', 'version'], 'charge_policy_hotel_version_unique');
        });

        Schema::create('folio_refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('folio_id')->constrained('reservation_folios')->restrictOnDelete();
            $table->foreignId('payment_record_id')->constrained('folio_payment_records')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->char('payload_sha256', 64);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason', 500);
            $table->string('status', 24)->default('requested');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'request_key'], 'refund_hotel_request_unique');
            $table->index(['hotel_id', 'payment_record_id', 'status'], 'refund_payment_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_refund_requests');
        Schema::dropIfExists('hotel_charge_policy_versions');
    }
};
