<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballMatchLineupSyncService;
use Illuminate\Console\Command;

class BackfillLineups extends Command
{
    protected $signature   = 'robetting:backfill-lineups {--season= : year_start of the target season (e.g. 2025). Defaults to current season.}';
    protected $description = 'Backfill lineups for all definitive matches in the current (or specified) season that have no lineup data yet.';

    public function handle(ApiFootballMatchLineupSyncService $service): int
    {
        set_time_limit(0);

        $seasonYear = $this->option('season') !== null ? (int) $this->option('season') : null;

        $label = $seasonYear !== null ? "season year_start={$seasonYear}" : 'current season';
        $this->info("Starting lineup backfill for {$label} …");

        $result = $service->syncMissingHistorical($seasonYear);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Status',          $result['status']],
                ['Candidates',      $result['candidates']],
                ['Synced',          $result['synced']],
                ['Empty (retry)',    $result['empty']],
                ['Failed (retry)',   $result['failed']],
                ['API calls',       $result['api_calls']],
                ['Daily remaining', $result['daily_remaining'] ?? '—'],
            ],
        );

        return Command::SUCCESS;
    }
}
