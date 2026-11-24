<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hotel_expense_policies', function (Blueprint $table): void {
            $table->foreignId('hotel_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('routine_limit_minor');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('hotel_expense_policy_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('previous_limit_minor')->nullable();
            $table->unsignedBigInteger('new_limit_minor');
            $table->string('reason', 500);
            $table->timestamp('created_at');
        });
        Schema::create('hotel_expense_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_id')->unique()->constrained('hotel_expenses')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 24);
            $table->string('note', 500);
            $table->timestamp('created_at');
        });
        Schema::table('hotel_expenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('policy_limit_minor')->nullable();
            $table->string('review_reason', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_expenses', function (Blueprint $table): void {
            $table->dropColumn(['policy_limit_minor', 'review_reason']);
        });
        Schema::dropIfExists('hotel_expense_reviews');
        Schema::dropIfExists('hotel_expense_policy_events');
        Schema::dropIfExists('hotel_expense_policies');
    }
};
