<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('no_show_financial_clearances', function (Blueprint $table): void {
            $table->index('review_id', 'no_show_clearance_review_idx');
        });
        Schema::table('no_show_financial_clearances', function (Blueprint $table): void {
            $table->dropUnique('no_show_financial_clearances_review_id_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('no_show_financial_clearances')->select('review_id')->groupBy('review_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Replacement no-show clearance history must be retained.');
        }
        Schema::table('no_show_financial_clearances', function (Blueprint $table): void {
            $table->unique('review_id');
        });
        Schema::table('no_show_financial_clearances', function (Blueprint $table): void {
            $table->dropIndex('no_show_clearance_review_idx');
        });
    }
};
