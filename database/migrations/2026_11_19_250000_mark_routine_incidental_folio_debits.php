<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folio_adjustments', function (Blueprint $table): void {
            // Only the dedicated authorized incidental endpoint may set this.
            // Existing adjustments default to requiring independent review.
            $table->boolean('is_routine_incidental')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('folio_adjustments', function (Blueprint $table): void {
            $table->dropColumn('is_routine_incidental');
        });
    }
};
