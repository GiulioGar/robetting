<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use App\Services\DataSources\ApiFootball\MatchDataScope;
use Illuminate\Console\Command;

class BackfillStatistics extends Command
{
    protected $signature   = 'robetting:backfill-statistics
                              {--season= : year_start of the target season (e.g. 2025). Defaults to current season.}
                              {--scope=  : Optional MatchDataScope filter. Omit for the existing default (CORE+TEAM_HISTORY). "team-history" restricts to Team History matches only (see Step B6); "uefa-current" restricts to UEFA CL/EL/ECL matches of the current season (pair with --season=<year_start>).}';
    protected $description = 'Backfill match statistics for all definitive matches in the current (or specified) season that have no statistics yet.';

    public function handle(ApiFootballMatchStatisticsSyncService $service): int
    {
        set_time_limit(0);

        $seasonYear = $this->option('season') !== null ? (int) $this->option('season') : null;

        $allowedScopes = null; // service default: [CORE, TEAM_HISTORY]
        $scopeOption   = $this->option('scope');
        $uefaCurrentOnly = false;
        if ($scopeOption !== null) {
            if (! in_array($scopeOption, ['team-history', 'uefa-current'], true)) {
                $this->error("Invalid --scope '{$scopeOption}'. Accepted: 'team-history', 'uefa-current'.");
                return self::FAILURE;
            }
            if ($scopeOption === 'uefa-current') {
                $uefaCurrentOnly = true;
            } else {
                $allowedScopes = [MatchDataScope::TEAM_HISTORY];
            }
        }

        $label = $seasonYear !== null ? "season year_start={$seasonYear}" : 'current season';
        $label .= $uefaCurrentOnly ? ', scope=uefa-current' : ($allowedScopes !== null ? ', scope=team-history' : '');
        $this->info("Starting statistics backfill for {$label} …");

        $result = $uefaCurrentOnly
            ? $service->syncMissingHistorical($seasonYear, null, true)
            : $service->syncMissingHistorical($seasonYear, $allowedScopes);

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
