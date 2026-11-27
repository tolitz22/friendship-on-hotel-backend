<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('push_preferences',fn (Blueprint $table)=>$table->boolean('hk_assigned')->default(true));
    }
    public function down(): void
    {
        Schema::table('push_preferences',fn (Blueprint $table)=>$table->dropColumn('hk_assigned'));
    }
};
