<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballInjurySyncService;
use Illuminate\Console\Command;

class SyncApiFootballInjuries extends Command
{
    protected $signature   = 'robetting:sync-api-football-injuries
                              {--season= : year_start for historical backfill (e.g. 2026). Omit for pending pre-match sync.}
                              {--league= : competition slug to limit sync to (e.g. serie-a).}
                              {--window=7 : days ahead for pending sync (default 7).}';
    protected $description = 'Sync player absences from API-Football /injuries. Without --season runs pending pre-match sync; with --season runs historical backfill.';

    public function handle(ApiFootballInjurySyncService $service): int
    {
        $season = $this->option('season');
        $league = $this->option('league') ?: null;
        $window = (int) ($this->option('window') ?: 7);

        set_time_limit(0);

        if ($season !== null) {
            $seasonYear = (int) $season;
            $this->info("Backfilling injuries for season year_start={$seasonYear}" . ($league ? " league={$league}" : '') . ' …');
            $result = $service->syncMissingHistorical($seasonYear, $league);
        } else {
            $this->info("Syncing pending injuries (next {$window} days)" . ($league ? " league={$league}" : '') . ' …');
            $result = $service->syncPending($window, $league);
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Status',            $result['status']],
                ['Candidates',        $result['candidates']],
                ['Synced',            $result['synced']],
                ['Empty (no injury)', $result['empty']],
                ['Skipped (throttle)',$result['skipped_throttle'] ?? 0],
                ['Failed (retry)',    $result['failed']],
                ['Created',           $result['created']],
                ['Updated',           $result['updated']],
                ['Removed/Recovered', $result['removed']],
                ['Unchanged',         $result['unchanged']],
                ['Warnings',          $result['warnings']],
                ['API calls',         $result['api_calls']],
                ['Daily remaining',   $result['daily_remaining'] ?? 'n/a'],
            ],
        );

        return Command::SUCCESS;
    }
}
