<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('folio_refund_disbursement_evidence', function (Blueprint $table): void {
            $table->unique(['hotel_id', 'external_reference'], 'folio_refund_external_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('folio_refund_disbursement_evidence', function (Blueprint $table): void {
            $table->dropUnique('folio_refund_external_ref_unique');
        });
    }
};
