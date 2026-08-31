<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_statistics', function (Blueprint $table) {
            // --- extended shot metrics ---
            $table->unsignedSmallInteger('home_shots_off_target')->nullable()->after('away_shots_on_target');
            $table->unsignedSmallInteger('away_shots_off_target')->nullable()->after('home_shots_off_target');
            $table->unsignedSmallInteger('home_blocked_shots')->nullable()->after('away_shots_off_target');
            $table->unsignedSmallInteger('away_blocked_shots')->nullable()->after('home_blocked_shots');
            $table->unsignedSmallInteger('home_shots_insidebox')->nullable()->after('away_blocked_shots');
            $table->unsignedSmallInteger('away_shots_insidebox')->nullable()->after('home_shots_insidebox');
            $table->unsignedSmallInteger('home_shots_outsidebox')->nullable()->after('away_shots_insidebox');
            $table->unsignedSmallInteger('away_shots_outsidebox')->nullable()->after('home_shots_outsidebox');

            // --- possession (e.g. "55%" → 55.0) ---
            $table->decimal('home_possession', 4, 1)->nullable()->after('away_shots_outsidebox');
            $table->decimal('away_possession', 4, 1)->nullable()->after('home_possession');

            // --- offsides ---
            $table->unsignedSmallInteger('home_offsides')->nullable()->after('away_possession');
            $table->unsignedSmallInteger('away_offsides')->nullable()->after('home_offsides');

            // --- goalkeeper saves ---
            $table->unsignedSmallInteger('home_goalkeeper_saves')->nullable()->after('away_offsides');
            $table->unsignedSmallInteger('away_goalkeeper_saves')->nullable()->after('home_goalkeeper_saves');

            // --- passes ---
            $table->unsignedSmallInteger('home_passes_total')->nullable()->after('away_goalkeeper_saves');
            $table->unsignedSmallInteger('away_passes_total')->nullable()->after('home_passes_total');
            $table->unsignedSmallInteger('home_passes_accurate')->nullable()->after('away_passes_total');
            $table->unsignedSmallInteger('away_passes_accurate')->nullable()->after('home_passes_accurate');
            $table->decimal('home_passes_percentage', 5, 2)->nullable()->after('away_passes_accurate');
            $table->decimal('away_passes_percentage', 5, 2)->nullable()->after('home_passes_percentage');

            // --- raw payload for future metrics ---
            $table->json('raw_stats')->nullable()->after('away_passes_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('match_statistics', function (Blueprint $table) {
            $table->dropColumn([
                'home_shots_off_target', 'away_shots_off_target',
                'home_blocked_shots',    'away_blocked_shots',
                'home_shots_insidebox',  'away_shots_insidebox',
                'home_shots_outsidebox', 'away_shots_outsidebox',
                'home_possession',       'away_possession',
                'home_offsides',         'away_offsides',
                'home_goalkeeper_saves', 'away_goalkeeper_saves',
                'home_passes_total',     'away_passes_total',
                'home_passes_accurate',  'away_passes_accurate',
                'home_passes_percentage','away_passes_percentage',
                'raw_stats',
            ]);
        });
    }
};
