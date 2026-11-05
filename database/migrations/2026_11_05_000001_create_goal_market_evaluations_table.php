<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GOAL evaluation register — append-only, PRE-MATCH photographs of the comparison between an OFFICIAL Candidate47
 * prediction and the bookmaker quotes (goal_odds_snapshots) observed at evaluation_at (UTC).
 * One row = one comparable selection. Rows are never updated or deleted; every value needed to read the evaluation
 * back is stored, nothing is recomputed later.
 *
 * Dedup key: (prediction_id, market, line_key, selection, quote_state_hash) where quote_state_hash is the SHA-1 of the
 * sorted observation_ids that formed the bookmaker consensus: the same prediction against the same quotes is recorded
 * once (restart-safe); a new quote observation yields a new, genuinely different photograph.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goal_market_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_id')->constrained('predictions')->restrictOnDelete();
            $table->foreignId('match_id')->constrained('matches')->restrictOnDelete();
            $table->string('model_key', 60);
            $table->string('model_version', 30);
            $table->dateTime('kickoff_at');

            $table->string('market', 30);
            $table->decimal('line', 3, 1)->nullable();
            $table->string('line_key', 4);            // '2.5' ... or '-' for btts (non-null, usable in the unique key)
            $table->string('selection', 10);

            $table->double('model_probability');
            $table->double('model_fair_odd')->nullable();
            $table->double('consensus_probability');
            $table->double('probability_edge');
            $table->double('theoretical_ev');

            $table->decimal('best_odd', 8, 3);
            $table->unsignedInteger('best_bookmaker_id');
            $table->string('best_bookmaker_name', 100);
            $table->char('best_observation_id', 36);   // goal_odds_snapshots.observation_id of the best quote
            $table->unsignedSmallInteger('bookmaker_count');
            $table->json('bookmakers_json');           // per bookmaker: id, name, odd, fair_probability, observation_id, fetched_at
            $table->char('quote_state_hash', 40);

            $table->dateTime('prediction_generated_at');
            $table->dateTime('quotes_latest_fetched_at');
            $table->dateTime('quotes_oldest_fetched_at');
            $table->dateTime('evaluation_at');

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['prediction_id', 'market', 'line_key', 'selection', 'quote_state_hash'], 'goal_eval_dedup_unique');
            $table->index(['match_id', 'evaluation_at'], 'goal_eval_match_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_market_evaluations');
    }
};
