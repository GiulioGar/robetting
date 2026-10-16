<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballMatchPlayerStatisticsSyncService;
use App\Services\DataSources\ApiFootball\MatchDataScope;
use Illuminate\Console\Command;

class BackfillPlayerStatistics extends Command
{
    protected $signature   = 'robetting:backfill-player-statistics
                              {--season= : year_start of the target season (e.g. 2025). Omit for current season(s).}
                              {--scope=  : Optional MatchDataScope filter. Omit for the existing default (CORE+TEAM_HISTORY). Only "team-history" is accepted — restricts to Team History matches only (see Step B4).}';
    protected $description = 'Fetch /fixtures/players for all definitive matches that have no player_stats_fetched_at yet.';

    public function handle(ApiFootballMatchPlayerStatisticsSyncService $service): int
    {
        $seasonOption = $this->option('season');
        $seasonYear   = $seasonOption !== null ? (int) $seasonOption : null;

        $allowedScopes = null; // service default: [CORE, TEAM_HISTORY]
        $scopeOption   = $this->option('scope');
        if ($scopeOption !== null) {
            if ($scopeOption !== 'team-history') {
                $this->error("Invalid --scope '{$scopeOption}'. Only 'team-history' is accepted.");
                return self::FAILURE;
            }
            $allowedScopes = [MatchDataScope::TEAM_HISTORY];
        }

        set_time_limit(0);

        $scopeLabel = $seasonYear !== null ? "season year_start={$seasonYear}" : 'current season(s)';
        $scopeLabel .= $allowedScopes !== null ? ', scope=team-history' : '';
        $this->info("Backfilling player statistics for {$scopeLabel} …");

        $result = $service->syncMissingHistorical($seasonYear, $allowedScopes);

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
