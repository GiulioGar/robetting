<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('name', 255);
            $table->string('short_name', 100)->nullable();
            $table->string('slug', 100)->unique();
            $table->string('format', 30)->default('league');
            $table->string('gender', 20)->default('male');
            $table->unsignedTinyInteger('tier')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('country_id')
                ->references('id')
                ->on('countries')
                ->onDelete('restrict');

            $table->index('country_id');
            $table->index('format');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitions');
    }
};
