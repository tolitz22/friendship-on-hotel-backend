<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('room_rate_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('rate_plan_id')->constrained('room_rate_plans')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('kind', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedBigInteger('nightly_minor');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['hotel_id', 'rate_plan_id', 'kind', 'starts_on', 'ends_on'], 'rate_rule_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_rate_rules');
    }
};
