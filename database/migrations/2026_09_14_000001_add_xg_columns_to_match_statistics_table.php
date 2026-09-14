<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_statistics', function (Blueprint $table) {
            // --- expected goals (xG) ---
            // API key: "expected_goals"; value arrives as string "1.08" or float 1.43 depending on season.
            // decimal(5,2): signed, range −999.99…999.99; 2 d.p. matches API precision.
            $table->decimal('home_expected_goals', 5, 2)->nullable()->after('raw_stats');
            $table->decimal('away_expected_goals', 5, 2)->nullable()->after('home_expected_goals');

            // --- goals prevented (xGOT proxy) ---
            // API key: "goals_prevented"; can be negative (goalkeeper performed below expectation).
            $table->decimal('home_goals_prevented', 5, 2)->nullable()->after('away_expected_goals');
            $table->decimal('away_goals_prevented', 5, 2)->nullable()->after('home_goals_prevented');
        });
    }

    public function down(): void
    {
        Schema::table('match_statistics', function (Blueprint $table) {
            $table->dropColumn([
                'home_expected_goals', 'away_expected_goals',
                'home_goals_prevented', 'away_goals_prevented',
            ]);
        });
    }
};
