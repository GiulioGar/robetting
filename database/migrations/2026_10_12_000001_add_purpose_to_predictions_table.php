<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only: separates OFFICIAL (Learning Loop, immutable/append-only)
 * from PUBLIC_LATEST (portal-facing, refreshed daily — not implemented yet)
 * predictions within the same `predictions` table. Default 'official' means
 * every existing row, and every row written by the current
 * OfficialPredictionCaptureService/OfficialPredictionRecorder path, keeps its
 * current meaning with zero code changes elsewhere. No UNIQUE constraint is
 * added here on purpose — a future public_latest "single current row per
 * match_id+model_key" rule must never risk colliding with official's
 * append-only multi-row history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            $table->string('purpose', 16)->default('official')->after('model_key');
            $table->index('purpose', 'predictions_purpose_idx');
        });
    }

    public function down(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            $table->dropIndex('predictions_purpose_idx');
            $table->dropColumn('purpose');
        });
    }
};
