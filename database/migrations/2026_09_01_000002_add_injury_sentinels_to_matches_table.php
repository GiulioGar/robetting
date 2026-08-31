<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Updated on EVERY attempt (including HTTP errors and empty responses).
            // Used to record that a sync was triggered; does NOT gate future syncs.
            $table->timestamp('injuries_last_attempt_at')->nullable()->after('player_stats_fetched_at');

            // Updated only after a VALID 2xx response (including empty [] = "no injuries").
            // Used for throttle decisions: how recently did we get confirmed injury data?
            // Unlike post-match sentinels, this is intentionally refreshed before kickoff.
            $table->timestamp('injuries_fetched_at')->nullable()->after('injuries_last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['injuries_last_attempt_at', 'injuries_fetched_at']);
        });
    }
};
