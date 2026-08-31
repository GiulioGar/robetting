<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_player', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('seasons')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('player_id')->constrained('players')->restrictOnDelete();
            $table->string('position', 20)->nullable();
            $table->timestamps();

            $table->unique(['season_id', 'team_id', 'player_id'], 'season_player_unique');
            $table->index(['season_id', 'team_id'],   'sp_season_team_idx');
            $table->index(['player_id', 'season_id'], 'sp_player_season_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_player');
    }
};
