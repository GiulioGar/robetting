<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballMatchPlayerStatisticsSyncService;
use Illuminate\Console\Command;

class BackfillPlayerStatistics extends Command
{
    protected $signature   = 'robetting:backfill-player-statistics
                              {--season= : year_start of the target season (e.g. 2025). Omit for current season(s).}';
    protected $description = 'Fetch /fixtures/players for all definitive matches that have no player_stats_fetched_at yet.';

    public function handle(ApiFootballMatchPlayerStatisticsSyncService $service): int
    {
        $seasonOption = $this->option('season');
        $seasonYear   = $seasonOption !== null ? (int) $seasonOption : null;

        set_time_limit(0);

        $scope = $seasonYear !== null ? "season year_start={$seasonYear}" : 'current season(s)';
        $this->info("Backfilling player statistics for {$scope} …");

        $result = $service->syncMissingHistorical($seasonYear);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Status',          $result['status']],
                ['Candidates',      $result['candidates']],
                ['Synced',          $result['synced']],
                ['Empty responses', $result['empty']],
                ['Failed (retry)',   $result['failed']],
                ['API calls',       $result['api_calls']],
            ],
        );

        return Command::SUCCESS;
    }
}
