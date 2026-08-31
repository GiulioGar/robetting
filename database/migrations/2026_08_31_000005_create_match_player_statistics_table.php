<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_player_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->restrictOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();

            // utilization
            $table->unsignedSmallInteger('games_minutes')->nullable();
            $table->unsignedSmallInteger('games_number')->nullable();
            $table->string('games_position', 10)->nullable();
            $table->decimal('games_rating', 4, 2)->nullable();
            $table->boolean('games_captain')->default(false);
            $table->boolean('games_substitute')->default(false);

            // shots
            $table->unsignedSmallInteger('shots_total')->nullable();
            $table->unsignedSmallInteger('shots_on_target')->nullable();

            // goals
            $table->unsignedSmallInteger('goals_scored')->nullable();
            $table->unsignedSmallInteger('goals_conceded')->nullable();
            $table->unsignedSmallInteger('goals_assists')->nullable();
            $table->unsignedSmallInteger('goals_saves')->nullable();

            // passes
            $table->unsignedSmallInteger('passes_total')->nullable();
            $table->unsignedSmallInteger('passes_key')->nullable();
            $table->decimal('passes_accuracy', 5, 2)->nullable();

            // tackles
            $table->unsignedSmallInteger('tackles_total')->nullable();
            $table->unsignedSmallInteger('tackles_blocks')->nullable();
            $table->unsignedSmallInteger('tackles_interceptions')->nullable();

            // duels
            $table->unsignedSmallInteger('duels_total')->nullable();
            $table->unsignedSmallInteger('duels_won')->nullable();

            // dribbling
            $table->unsignedSmallInteger('dribbles_attempts')->nullable();
            $table->unsignedSmallInteger('dribbles_success')->nullable();
            $table->unsignedSmallInteger('dribbles_past')->nullable();

            // fouls
            $table->unsignedSmallInteger('fouls_drawn')->nullable();
            $table->unsignedSmallInteger('fouls_committed')->nullable();

            // discipline
            $table->unsignedTinyInteger('cards_yellow')->nullable();
            $table->unsignedTinyInteger('cards_red')->nullable();

            // penalties
            $table->unsignedTinyInteger('penalty_won')->nullable();
            $table->unsignedTinyInteger('penalty_committed')->nullable(); // API sends "commited" (1 t)
            $table->unsignedTinyInteger('penalty_scored')->nullable();
            $table->unsignedTinyInteger('penalty_missed')->nullable();
            $table->unsignedTinyInteger('penalty_saved')->nullable();

            // raw payload — preserves unmapped fields for future use
            $table->json('raw_stats')->nullable();

            $table->timestamps();

            $table->unique(['match_id', 'player_id', 'data_source_id'], 'mps_match_player_ds_unique');
            $table->index(['player_id', 'match_id'], 'mps_player_match_idx');
            $table->index(['team_id',   'match_id'], 'mps_team_match_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_player_statistics');
    }
};
