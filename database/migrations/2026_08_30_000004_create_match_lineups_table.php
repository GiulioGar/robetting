<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_lineups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();
            $table->string('formation', 20)->nullable();
            $table->string('coach_external_id', 50)->nullable();
            $table->string('coach_name')->nullable();
            $table->timestamps();

            $table->unique(['match_id', 'team_id', 'data_source_id'], 'match_lineups_unique');
            $table->index('data_source_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_lineups');
    }
};
