<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('data_source_id');
            $table->string('sync_type', 50);
            $table->unsignedBigInteger('competition_id')->nullable();
            $table->unsignedBigInteger('season_id')->nullable();
            $table->string('mode', 20)->nullable();
            $table->datetime('started_at');
            $table->datetime('finished_at')->nullable();
            $table->string('status', 20)->default('ok');
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('warnings_count')->default(0);
            $table->unsignedInteger('api_calls')->default(0);
            $table->unsignedInteger('daily_remaining')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->foreign('data_source_id')->references('id')->on('data_sources')->onDelete('restrict');
            $table->foreign('competition_id')->references('id')->on('competitions')->onDelete('set null');
            $table->foreign('season_id')->references('id')->on('seasons')->onDelete('set null');

            $table->index(['data_source_id', 'sync_type', 'competition_id', 'started_at'], 'dsr_ds_type_comp_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sync_runs');
    }
};
