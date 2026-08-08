<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_id');
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('home_team_id');
            $table->unsignedBigInteger('away_team_id');

            $table->datetime('kickoff_at')->nullable();
            $table->string('kickoff_timezone', 50)->nullable();

            $table->string('round', 50)->nullable();
            $table->unsignedSmallInteger('matchday')->nullable();

            $table->string('status', 30)->default('scheduled');

            $table->string('venue_name', 255)->nullable();

            $table->unsignedTinyInteger('home_score_ht')->nullable();
            $table->unsignedTinyInteger('away_score_ht')->nullable();

            $table->unsignedTinyInteger('home_score_ft')->nullable();
            $table->unsignedTinyInteger('away_score_ft')->nullable();

            $table->unsignedTinyInteger('home_score_et')->nullable();
            $table->unsignedTinyInteger('away_score_et')->nullable();

            $table->unsignedTinyInteger('home_score_penalties')->nullable();
            $table->unsignedTinyInteger('away_score_penalties')->nullable();

            $table->timestamps();

            $table->foreign('competition_id')
                ->references('id')
                ->on('competitions')
                ->onDelete('restrict');

            $table->foreign('season_id')
                ->references('id')
                ->on('seasons')
                ->onDelete('restrict');

            $table->foreign('home_team_id')
                ->references('id')
                ->on('teams')
                ->onDelete('restrict');

            $table->foreign('away_team_id')
                ->references('id')
                ->on('teams')
                ->onDelete('restrict');

            $table->index('competition_id');
            $table->index(['season_id', 'kickoff_at']);
            $table->index(['season_id', 'matchday']);
            $table->index(['home_team_id', 'kickoff_at']);
            $table->index(['away_team_id', 'kickoff_at']);
            $table->index('kickoff_at');
            $table->index(['status', 'kickoff_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
