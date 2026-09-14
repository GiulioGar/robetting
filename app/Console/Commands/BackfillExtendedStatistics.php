<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use Illuminate\Console\Command;

class BackfillExtendedStatistics extends Command
{
    protected $signature   = 'robetting:backfill-extended-statistics {--season= : year_start of the target season (e.g. 2025). Required.} {--force : Re-fetch even rows already at the current schema version (bypasses stats_schema_version gate).}';
    protected $description = 'Fetch /fixtures/statistics for definitive matches in a season that are below the current schema version. Use --force to re-fetch v2 rows too.';

    public function handle(ApiFootballMatchStatisticsSyncService $service): int
    {
        $seasonOption = $this->option('season');

        if ($seasonOption === null) {
            $this->error('--season is required. Usage: robetting:backfill-extended-statistics --season=2025');
            return Command::FAILURE;
        }

        $seasonYear = (int) $seasonOption;

        $force = (bool) $this->option('force');

        set_time_limit(0);

        $this->info("Starting extended statistics backfill for season year_start={$seasonYear} …");
        if ($force) {
            $this->warn('--force: re-fetching ALL definitive matches regardless of stats_schema_version.');
        } else {
            $this->info('Skipping rows already at the current schema version (pass --force to override).');
        }

        $result = $service->backfillExtendedHistorical($seasonYear, force: $force);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Status',          $result['status']],
                ['Candidates',      $result['candidates']],
                ['Updated',         $result['updated']],
                ['Unchanged (v2)',  $result['unchanged']],
                ['Failed (retry)',   $result['failed']],
                ['API calls',       $result['api_calls']],
                ['Daily remaining', $result['daily_remaining'] ?? '—'],
            ],
        );

        return Command::SUCCESS;
    }
}
