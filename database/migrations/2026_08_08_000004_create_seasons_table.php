<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_id');
            $table->string('name', 20);
            $table->unsignedSmallInteger('year_start');
            $table->unsignedSmallInteger('year_end');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->foreign('competition_id')
                ->references('id')
                ->on('competitions')
                ->onDelete('restrict');

            $table->unique(['competition_id', 'name']);
            $table->index(['competition_id', 'is_current']);
            $table->index('year_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};
