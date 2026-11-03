<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odds GOAL — append-only PRE-MATCH goal-market quotes from API-Football (core leagues).
 * One row = one selection of one bookmaker observation; rows are never updated.
 * `observation_id` groups the complete selections of the SAME (bookmaker, market, line)
 * observation (Over+Under or Yes+No), so a pair always stays consistent.
 * fetched_at + kickoff_at (kickoff as known at fetch time) make `fetched_at < kickoff_at`
 * certifiable per row, as for odds_snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goal_odds_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->restrictOnDelete();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();

            $table->char('observation_id', 36);

            $table->unsignedInteger('bookmaker_id');
            $table->string('bookmaker_name', 100);
            $table->unsignedInteger('bet_id');
            $table->string('bet_name', 100);

            // Normalised: market in {total_goals, btts, team_goals_home, team_goals_away};
            // selection in {over, under, yes, no}; line is NULL for btts.
            $table->string('market', 30);
            $table->string('selection', 10);
            $table->decimal('line', 3, 1)->nullable();
            // Original provider label, e.g. "Over 2.5" / "Yes".
            $table->string('provider_label', 50);

            $table->decimal('odd', 8, 3);

            // Provider-side "update" time of the odds block, when the response carries it.
            $table->dateTime('provider_updated_at')->nullable();
            // When Robetting received this observation.
            $table->dateTime('fetched_at');
            // kickoff_at of the match as known at fetch time.
            $table->dateTime('kickoff_at');

            // Append-only: no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['observation_id', 'selection'], 'goal_odds_obs_sel_unique');
            $table->index(['match_id', 'market', 'line', 'bookmaker_id'], 'goal_odds_match_market_idx');
            $table->index(['match_id', 'fetched_at'], 'goal_odds_match_fetched_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_odds_snapshots');
    }
};
