<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('financial_tax_policies', function (Blueprint $table): void {
            // Historic drafts cannot safely be attributed to an employee retroactively.
            $table->unsignedBigInteger('created_by')->nullable()->after('hotel_id');
        });
    }

    public function down(): void
    {
        Schema::table('financial_tax_policies', function (Blueprint $table): void {
            $table->dropColumn('created_by');
        });
    }
};
