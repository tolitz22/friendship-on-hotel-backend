<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reservation_pricing_revision_nights', function (Blueprint $table): void {
            // Replacement nights can later be materialized as canonical folio
            // accounting sources. Preserve the original reversal reference.
            $table->foreignId('replacement_entry_id')->nullable()
                ->constrained('folio_entries')->restrictOnDelete();
            $table->unique('replacement_entry_id', 'revision_replacement_entry_unique');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_pricing_revision_nights', function (Blueprint $table): void {
            $table->dropUnique('revision_replacement_entry_unique');
            $table->dropForeign(['replacement_entry_id']);
            $table->dropColumn('replacement_entry_id');
        });
    }
};
