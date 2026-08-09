<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_statistics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('match_id');
            $table->unsignedBigInteger('data_source_id');

            $table->unsignedSmallInteger('home_shots')->nullable();
            $table->unsignedSmallInteger('away_shots')->nullable();
            $table->unsignedSmallInteger('home_shots_on_target')->nullable();
            $table->unsignedSmallInteger('away_shots_on_target')->nullable();
            $table->unsignedSmallInteger('home_fouls')->nullable();
            $table->unsignedSmallInteger('away_fouls')->nullable();
            $table->unsignedSmallInteger('home_corners')->nullable();
            $table->unsignedSmallInteger('away_corners')->nullable();
            $table->unsignedTinyInteger('home_yellow_cards')->nullable();
            $table->unsignedTinyInteger('away_yellow_cards')->nullable();
            $table->unsignedTinyInteger('home_red_cards')->nullable();
            $table->unsignedTinyInteger('away_red_cards')->nullable();

            $table->timestamps();

            $table->unique(['match_id', 'data_source_id']);
            $table->index('data_source_id');

            $table->foreign('match_id')
                ->references('id')->on('matches')
                ->onDelete('cascade');

            $table->foreign('data_source_id')
                ->references('id')->on('data_sources')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_statistics');
    }
};
