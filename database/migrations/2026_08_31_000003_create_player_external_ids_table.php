<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_external_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players')->restrictOnDelete();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();
            $table->string('external_id', 50);
            $table->string('external_name')->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'data_source_id']);
            $table->unique(['data_source_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_external_ids');
    }
};
