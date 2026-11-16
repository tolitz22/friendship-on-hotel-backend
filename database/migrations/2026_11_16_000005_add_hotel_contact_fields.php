<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table): void {
            $table->string('address', 300)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table): void {
            $table->dropColumn(['address', 'contact_email', 'contact_phone']);
        });
    }
};
