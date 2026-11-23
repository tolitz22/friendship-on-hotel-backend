<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hotel_memberships', function (Blueprint $table): void {
            // NULL inherits the role template; [] explicitly grants no delegated actions.
            $table->json('permission_overrides')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_memberships', function (Blueprint $table): void {
            $table->dropColumn('permission_overrides');
        });
    }
};
