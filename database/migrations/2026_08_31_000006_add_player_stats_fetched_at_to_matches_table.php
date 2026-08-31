<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Sentinel for /fixtures/players completeness.
            // Set once per fixture on any valid 2xx response (including empty []).
            // Kept NULL on HTTP error or fully-unmapped payload so the fixture can be retried.
            $table->timestamp('player_stats_fetched_at')->nullable()->after('lineups_last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('player_stats_fetched_at');
        });
    }
};
