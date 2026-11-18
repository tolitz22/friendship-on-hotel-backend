<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('financial_room_revenue_recognitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('source_journal_id');
            $table->unsignedBigInteger('recognition_journal_id');
            $table->date('stay_date');
            $table->bigInteger('amount_minor');
            $table->timestamp('created_at');
            $table->unique(['hotel_id', 'source_journal_id', 'stay_date'], 'room_rev_once_per_source_night');
            $table->unique('recognition_journal_id', 'room_rev_recognition_journal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_room_revenue_recognitions');
    }
};
