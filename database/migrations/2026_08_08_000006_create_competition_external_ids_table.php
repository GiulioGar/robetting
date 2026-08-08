<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_external_ids', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_id');
            $table->unsignedBigInteger('data_source_id');
            $table->string('external_id', 100);
            $table->string('external_name', 255)->nullable();
            $table->timestamps();

            $table->foreign('competition_id')
                ->references('id')
                ->on('competitions')
                ->onDelete('cascade');

            $table->foreign('data_source_id')
                ->references('id')
                ->on('data_sources')
                ->onDelete('restrict');

            $table->unique(['data_source_id', 'external_id']);
            $table->index('competition_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_external_ids');
    }
};
