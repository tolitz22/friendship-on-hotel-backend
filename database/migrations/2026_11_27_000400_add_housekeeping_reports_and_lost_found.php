<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->foreignId('housekeeping_task_id')->nullable()->constrained('housekeeping_tasks')->restrictOnDelete();
            $table->uuid('housekeeping_report_key')->nullable();
            $table->unique(['hotel_id','created_by','housekeeping_report_key'], 'hk_maintenance_report_unique');
        });
        Schema::create('lost_found_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id');
            $table->foreign(['hotel_id','room_id'])->references(['hotel_id','id'])->on('rooms')->restrictOnDelete();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('description', 500);
            $table->string('storage_location', 160);
            $table->string('status', 20)->default('stored');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['hotel_id','reported_by','request_key'], 'lost_found_report_unique');
            $table->index(['hotel_id','status','id']);
        });
        Schema::create('lost_found_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained('lost_found_items')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->json('before_state')->nullable();
            $table->json('after_state');
            $table->text('note')->nullable();
            $table->timestamp('created_at');
            $table->index(['hotel_id','item_id','id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('lost_found_events');
        Schema::dropIfExists('lost_found_items');
        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->dropUnique('hk_maintenance_report_unique');
            $table->dropConstrainedForeignId('housekeeping_task_id');
            $table->dropColumn('housekeeping_report_key');
        });
    }
};
