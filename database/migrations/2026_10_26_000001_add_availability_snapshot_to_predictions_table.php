<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only: prospective Availability snapshot frozen together with a NEW
 * prediction (exactly what Robetting knew when that prediction was saved).
 * Deliberately separate from features_json, which must stay the pure Candidate47
 * artifact feature vector. Existing rows keep NULL (no backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            $table->json('availability_snapshot_json')->nullable()->after('features_json');
        });
    }

    public function down(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            $table->dropColumn('availability_snapshot_json');
        });
    }
};
