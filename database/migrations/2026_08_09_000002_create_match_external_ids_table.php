<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_external_ids', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('match_id');
            $table->unsignedBigInteger('data_source_id');
            $table->string('external_id', 100);
            $table->string('external_name', 255)->nullable();
            $table->timestamps();

            $table->foreign('match_id')
                ->references('id')
                ->on('matches')
                ->onDelete('cascade');

            $table->foreign('data_source_id')
                ->references('id')
                ->on('data_sources')
                ->onDelete('restrict');

            $table->unique(['data_source_id', 'external_id']);
            $table->index('match_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_external_ids');
    }
};
