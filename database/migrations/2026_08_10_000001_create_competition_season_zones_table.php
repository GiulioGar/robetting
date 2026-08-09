<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_season_zones', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('season_id');
            $table->foreign('season_id')
                ->references('id')
                ->on('seasons')
                ->onDelete('cascade');

            $table->unsignedTinyInteger('from_position');
            $table->unsignedTinyInteger('to_position');

            $table->string('type', 50);
            $table->string('label', 100);

            $table->string('css_class', 50)->nullable();
            $table->string('color', 20)->nullable();

            // 'confirmed' | 'provisional' — not enum to stay flexible
            $table->string('status', 20)->default('confirmed');

            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index('season_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_season_zones');
    }
};
