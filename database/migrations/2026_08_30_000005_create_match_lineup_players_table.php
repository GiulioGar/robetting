<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_lineup_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_lineup_id')->constrained('match_lineups')->cascadeOnDelete();
            $table->string('player_external_id', 50);
            $table->string('player_name');
            $table->unsignedTinyInteger('shirt_number')->nullable();
            $table->string('position', 5)->nullable();
            $table->string('grid', 10)->nullable();
            $table->boolean('is_starter');
            $table->timestamps();

            $table->unique(['match_lineup_id', 'player_external_id'], 'match_lineup_players_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_lineup_players');
    }
};
