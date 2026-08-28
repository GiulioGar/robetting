<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('data_source_id')->constrained('data_sources')->cascadeOnDelete();
            $table->string('event_type', 30);            // goal, yellow_card, red_card, substitution, sync_complete
            $table->unsignedSmallInteger('minute')->nullable();
            $table->string('minute_label', 20)->nullable(); // "45+2", "90+3" etc.
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('player_external_id', 100)->nullable();
            $table->string('player_name', 255)->nullable();
            $table->string('related_player_external_id', 100)->nullable(); // assist or player_in
            $table->string('related_player_name', 255)->nullable();
            $table->json('detail')->nullable();
            $table->string('source_event_key', 150);     // API event id; idempotency anchor
            $table->timestamps();

            // Idempotency: one source_event_key per match+source pair
            $table->unique(['match_id', 'data_source_id', 'source_event_key'], 'match_events_idempotency');
            $table->index(['match_id', 'data_source_id', 'event_type'], 'match_events_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_events');
    }
};
