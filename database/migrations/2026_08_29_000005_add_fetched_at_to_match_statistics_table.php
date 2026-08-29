<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_statistics', function (Blueprint $table) {
            $table->timestamp('fetched_at')->nullable()->after('data_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('match_statistics', function (Blueprint $table) {
            $table->dropColumn('fetched_at');
        });
    }
};
