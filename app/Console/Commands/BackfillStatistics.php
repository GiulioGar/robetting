<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use Illuminate\Console\Command;

class BackfillStatistics extends Command
{
    protected $signature   = 'robetting:backfill-statistics {--season= : year_start of the target season (e.g. 2025). Defaults to current season.}';
    protected $description = 'Backfill match statistics for all definitive matches in the current (or specified) season that have no statistics yet.';

    public function handle(ApiFootballMatchStatisticsSyncService $service): int
    {
        set_time_limit(0);

        $seasonYear = $this->option('season') !== null ? (int) $this->option('season') : null;

        $label = $seasonYear !== null ? "season year_start={$seasonYear}" : 'current season';
        $this->info("Starting statistics backfill for {$label} …");

        $result = $service->syncMissingHistorical($seasonYear);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Status',          $result['status']],
                ['Candidates',      $result['candidates']],
                ['Created',         $result['created']],
                ['Updated',         $result['updated']],
                ['Unchanged',       $result['unchanged']],
                ['Failed (retry)',   $result['failed']],
                ['API calls',       $result['api_calls']],
                ['Daily remaining', $result['daily_remaining'] ?? '—'],
            ],
        );

        return Command::SUCCESS;
    }
}
