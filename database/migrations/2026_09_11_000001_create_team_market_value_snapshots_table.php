<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_market_value_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();
            $table->date('snapshot_date');
            $table->unsignedBigInteger('market_value');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'data_source_id', 'snapshot_date'], 'tmvs_team_ds_date_unique');
            $table->index(['team_id', 'snapshot_date'], 'tmvs_team_date_idx');
            $table->index('snapshot_date', 'tmvs_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_market_value_snapshots');
    }
};
