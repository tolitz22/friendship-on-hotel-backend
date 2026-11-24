<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hotel_expenses', function (Blueprint $table): void {
            $table->string('payment_reference', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_expenses', function (Blueprint $table): void {
            $table->dropColumn('payment_reference');
        });
    }
};
