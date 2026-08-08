<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_external_ids', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('competition_id');
            $table->unsignedBigInteger('data_source_id');
            $table->string('external_id', 100);
            $table->string('external_name', 100)->nullable();
            $table->timestamps();

            $table->foreign('season_id')
                ->references('id')
                ->on('seasons')
                ->onDelete('cascade');

            $table->foreign('competition_id')
                ->references('id')
                ->on('competitions')
                ->onDelete('restrict');

            $table->foreign('data_source_id')
                ->references('id')
                ->on('data_sources')
                ->onDelete('restrict');

            $table->unique(['data_source_id', 'competition_id', 'external_id'], 'sei_ds_comp_ext_unique');
            $table->index('season_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_external_ids');
    }
};
