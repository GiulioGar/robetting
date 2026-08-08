<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('name', 255);
            $table->string('short_name', 100)->nullable();
            $table->string('code', 10)->nullable();
            $table->string('type', 20)->default('club');
            $table->unsignedSmallInteger('founded_year')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('country_id')
                ->references('id')
                ->on('countries')
                ->onDelete('restrict');

            $table->index('country_id');
            $table->index('type');
            $table->index('name');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
