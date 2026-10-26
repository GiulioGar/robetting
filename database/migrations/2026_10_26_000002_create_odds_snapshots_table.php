<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odds V1 — append-only PRE-MATCH 1X2 (Match Winner) observations from
 * API-Football. One row = one bookmaker observation at one fetch; rows are
 * never updated. fetched_at + kickoff_at (kickoff as known AT fetch time)
 * are stored together so `fetched_at < kickoff_at` is certifiable per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odds_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->restrictOnDelete();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();

            $table->unsignedInteger('bookmaker_id');
            $table->string('bookmaker_name', 100);
            $table->unsignedInteger('bet_id');
            $table->string('bet_name', 100);

            $table->decimal('home_odd', 8, 3);
            $table->decimal('draw_odd', 8, 3);
            $table->decimal('away_odd', 8, 3);

            // Provider-side "update" time of the odds block, when the response carries it.
            $table->dateTime('provider_updated_at')->nullable();
            // When Robetting received this observation.
            $table->dateTime('fetched_at');
            // kickoff_at of the match as known at fetch time (provenance for fetched_at < kickoff_at).
            $table->dateTime('kickoff_at');

            $table->json('raw_data')->nullable();

            // Append-only: no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['match_id', 'bookmaker_id', 'bet_id'], 'odds_match_bm_bet_idx');
            $table->index(['match_id', 'fetched_at'], 'odds_match_fetched_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odds_snapshots');
    }
};
