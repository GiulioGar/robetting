<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->timestamp('lineups_fetched_at')->nullable()->after('events_fetched_at');
            $table->timestamp('lineups_last_attempt_at')->nullable()->after('lineups_fetched_at');
            $table->index(['kickoff_at', 'lineups_last_attempt_at'], 'matches_lineups_candidate_idx');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex('matches_lineups_candidate_idx');
            $table->dropColumn(['lineups_fetched_at', 'lineups_last_attempt_at']);
        });
    }
};
