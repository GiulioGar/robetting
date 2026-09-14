<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Schema version semantics for match_statistics.stats_schema_version:
     *
     *   null  = row never successfully fetched (no data, or fetch still pending)
     *
     *   1     = fetched before the extended-stats migration (2026-08-31) or before the
     *           xG migration (2026-09-14).  The row may have shots/sot/fouls/corners
     *           but lacks shots_off_target, blocked_shots, possession, passes, goalkeeper_saves,
     *           offsides, expected_goals, goals_prevented, and raw_stats.
     *           → Eligible for re-fetch: stats incomplete relative to current schema.
     *
     *   2     = CURRENT.  Parser ran against the full current schema (extended stats +
     *           expected_goals + goals_prevented + raw_stats).  Some column values may be
     *           null when the API does not provide that metric for the league (e.g. Bundesliga
     *           returns no xG), but the parser and schema are fully applied.
     *           → Skip on normal sync.
     */
    public function up(): void
    {
        Schema::table('match_statistics', function (Blueprint $table) {
            $table->unsignedSmallInteger('stats_schema_version')->nullable()->after('away_goals_prevented');
        });

        // Classify existing rows conservatively.
        //
        // Rows with raw_stats: were fetched AFTER the 2026-08-31 extended migration
        // and AFTER the 2026-09-14 xG migration (raw_stats was added in 2026-08-31).
        // These represent the most complete payload available → v2.
        DB::statement('UPDATE match_statistics SET stats_schema_version = 2 WHERE raw_stats IS NOT NULL');

        // Rows with fetched_at but no raw_stats: were fetched BEFORE raw_stats column
        // existed (i.e. before 2026-08-31).  Extended columns and raw_stats are null.
        // These are incomplete relative to the current schema → v1.
        DB::statement('UPDATE match_statistics SET stats_schema_version = 1 WHERE fetched_at IS NOT NULL AND raw_stats IS NULL');

        // Rows with fetched_at IS NULL remain stats_schema_version = NULL (never fetched).
        // From the 2026-09-15 DB audit: 0 such rows exist currently; the branch covers
        // future matches not yet processed.
    }

    public function down(): void
    {
        Schema::table('match_statistics', function (Blueprint $table) {
            $table->dropColumn('stats_schema_version');
        });
    }
};
