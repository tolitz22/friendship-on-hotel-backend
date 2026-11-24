<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table): void {
            $table->bigInteger('expected_cash_minor')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('cashier_shifts')->where('expected_cash_minor', '<', 0)->exists()) {
            throw new \RuntimeException('Negative drawer balances must be preserved; rollback would lose financial evidence.');
        }
        Schema::table('cashier_shifts', function (Blueprint $table): void {
            $table->unsignedBigInteger('expected_cash_minor')->nullable()->change();
        });
    }
};
