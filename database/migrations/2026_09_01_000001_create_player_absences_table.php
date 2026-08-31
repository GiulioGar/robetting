<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->restrictOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();

            // API-Football absence fields
            // type:   e.g. "Missing Fixture", "Questionable"
            // reason: e.g. "Knee Injury", "Muscular Injury"
            $table->string('absence_type', 100)->nullable();
            $table->string('reason', 255)->nullable();

            // Full API item preserved for future unmapped fields
            $table->json('raw_data')->nullable();

            $table->timestamps();

            // One absence per player per fixture per source.
            // A player can only appear once in /injuries?fixture={id} per team,
            // and cannot play for both teams, so this UNIQUE is safe.
            $table->unique(['match_id', 'player_id', 'data_source_id'], 'pa_match_player_ds_unique');

            // Bulk lookup: all absences for a fixture from a given source
            $table->index(['match_id', 'data_source_id'], 'pa_match_ds_idx');
            // Per-player absence history
            $table->index(['player_id'], 'pa_player_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_absences');
    }
};
