<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->unique(['hotel_id', 'room_type_id', 'id'], 'rooms_hotel_type_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropUnique('rooms_hotel_type_id_unique');
        });
    }
};
