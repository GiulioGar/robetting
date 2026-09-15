<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * advanced_stats_checked_at represents the last time the enrichment cycle
     * attempted to retrieve late-arriving advanced statistics (xG, goals_prevented)
     * for a row that is already at the current schema version (v2) but has null xG.
     *
     * This is deliberately separate from fetched_at and stats_schema_version:
     *   - stats_schema_version = 2 means "parser ran against full schema" — it does NOT
     *     mean "API-Football will never add xG later". Historical seasons prove API-Football
     *     populates xG retroactively over weeks/months.
     *   - advanced_stats_checked_at throttles re-fetch attempts so we respect the retry
     *     interval (config: api-football.late_stats_retry_days) without burning quota.
     *
     * Semantics:
     *   NULL  = never attempted enrichment (treated as eligible)
     *   timestamp = last enrichment attempt; eligible again after retry_days
     */
    public function up(): void
    {
        Schema::table('match_statistics', function (Blueprint $table) {
            $table->timestamp('advanced_stats_checked_at')->nullable()->after('stats_schema_version');
        });
    }

    public function down(): void
    {
        Schema::table('match_statistics', function (Blueprint $table) {
            $table->dropColumn('advanced_stats_checked_at');
        });
    }
};
