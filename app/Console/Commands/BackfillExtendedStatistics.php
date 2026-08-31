<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use Illuminate\Console\Command;

class BackfillExtendedStatistics extends Command
{
    protected $signature   = 'robetting:backfill-extended-statistics {--season= : year_start of the target season (e.g. 2025). Required.}';
    protected $description = 'Re-fetch /fixtures/statistics for all definitive matches in a season to populate extended columns, regardless of existing fetched_at.';

    public function handle(ApiFootballMatchStatisticsSyncService $service): int
    {
        $seasonOption = $this->option('season');

        if ($seasonOption === null) {
            $this->error('--season is required. Usage: robetting:backfill-extended-statistics --season=2025');
            return Command::FAILURE;
        }

        $seasonYear = (int) $seasonOption;

        set_time_limit(0);

        $this->info("Starting extended statistics backfill for season year_start={$seasonYear} …");
        $this->warn('This command re-fetches ALL definitive matches in the season regardless of fetched_at.');

        $result = $service->backfillExtendedHistorical($seasonYear);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Status',          $result['status']],
                ['Candidates',      $result['candidates']],
                ['Updated',         $result['updated']],
                ['Failed (retry)',   $result['failed']],
                ['API calls',       $result['api_calls']],
                ['Daily remaining', $result['daily_remaining'] ?? '—'],
            ],
        );

        return Command::SUCCESS;
    }
}
