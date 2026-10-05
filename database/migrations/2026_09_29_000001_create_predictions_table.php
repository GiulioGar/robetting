<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->restrictOnDelete();

            $table->string('model_key', 64);
            $table->string('model_version', 32);
            $table->string('feature_set_version', 64);
            $table->char('artifact_sha256', 64)->nullable();

            $table->dateTime('generated_at');
            $table->dateTime('kickoff_at');

            $table->double('lambda_home');
            $table->double('lambda_away');
            $table->double('lambda3')->nullable();

            $table->double('probability_home');
            $table->double('probability_draw');
            $table->double('probability_away');

            $table->json('features_json');

            $table->string('structural_snapshot_version', 64)->nullable();
            $table->dateTime('latent_snapshot_generated_at')->nullable();

            // Result, populated only after the match by a future job.
            $table->unsignedSmallInteger('home_goals')->nullable();
            $table->unsignedSmallInteger('away_goals')->nullable();
            $table->char('outcome', 1)->nullable(); // '1' | 'X' | '2'
            $table->string('match_status_at_result', 32)->nullable();
            $table->dateTime('result_recorded_at')->nullable();

            $table->timestamps();

            // No UNIQUE on match_id alone: the same match can have several
            // predictions across models and/or generation timestamps.
            $table->index('match_id', 'predictions_match_idx');
            $table->index('model_key', 'predictions_model_key_idx');
            $table->index('generated_at', 'predictions_generated_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
