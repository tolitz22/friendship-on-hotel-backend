<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('push_endpoint_owners', function (Blueprint $table): void {
            $table->string('endpoint_hash', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // Preserve unambiguous registrations. Conflicting legacy endpoints are
        // quarantined: no owner is assigned and all conflicting devices must opt in again.
        DB::table('push_subscriptions')->select('endpoint_hash')
            ->selectRaw('MIN(user_id) as first_owner, MAX(user_id) as last_owner')
            ->groupBy('endpoint_hash')->orderBy('endpoint_hash')->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    if ((string) $row->first_owner !== (string) $row->last_owner) {
                        continue;
                    }
                    DB::table('push_endpoint_owners')->insert([
                        'endpoint_hash' => $row->endpoint_hash, 'user_id' => $row->first_owner,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_endpoint_owners');
    }
};
