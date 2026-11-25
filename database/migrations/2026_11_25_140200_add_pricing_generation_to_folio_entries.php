<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('folio_entries', function (Blueprint $table): void {
            // Generation zero preserves the original uniqueness contract.
            // A reviewed replacement uses its immutable pricing revision ID.
            $table->unsignedBigInteger('pricing_generation')->default(0);
            $table->unique(['folio_id', 'stay_date', 'entry_type', 'pricing_generation'], 'folio_night_generation_unique');
        });
        Schema::table('folio_entries', function (Blueprint $table): void {
            $table->dropUnique('folio_night_unique');
        });
    }

    public function down(): void
    {
        // Restoring the old constraint must fail rather than destroy history
        // if overlapping generations have already been recorded.
        Schema::table('folio_entries', function (Blueprint $table): void {
            $table->unique(['folio_id', 'stay_date', 'entry_type'], 'folio_night_unique');
        });
        Schema::table('folio_entries', function (Blueprint $table): void {
            $table->dropUnique('folio_night_generation_unique');
            $table->dropColumn('pricing_generation');
        });
    }
};
