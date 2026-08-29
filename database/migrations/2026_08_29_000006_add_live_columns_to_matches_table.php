<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_home_score')->nullable()->after('away_score_penalties');
            $table->unsignedTinyInteger('current_away_score')->nullable()->after('current_home_score');
            $table->unsignedSmallInteger('live_minute')->nullable()->after('current_away_score');
            $table->string('live_status', 10)->nullable()->after('live_minute');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['current_home_score', 'current_away_score', 'live_minute', 'live_status']);
        });
    }
};
