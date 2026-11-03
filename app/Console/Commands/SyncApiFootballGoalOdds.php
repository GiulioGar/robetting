<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballGoalOddsSyncService;
use Illuminate\Console\Command;

/**
 * Manual run of the Odds GOAL prospective collection (PRE-MATCH Over/Under, BTTS, Team Goals; core leagues + UEFA club competitions;
 * append-only goal_odds_snapshots). Not wired into `robetting:serve`; the 1X2 collection is separate.
 */
class SyncApiFootballGoalOdds extends Command
{
    protected $signature = 'robetting:sync-goal-odds
        {--competition= : Only this in-scope competition slug (e.g. serie-a, uefa-champions-league)}
        {--force : Ignore the per-competition throttle (manual runs only)}';

    protected $description = 'Collect PRE-MATCH GOAL-market odds (Over/Under, BTTS, Team Goals) from API-Football into the append-only goal_odds_snapshots table.';

    public function handle(ApiFootballGoalOddsSyncService $service): int
    {
        $result = $service->syncPending(
            $this->option('competition') ?: null,
            (bool) $this->option('force'),
        );

        if (! $result['enabled']) {
            $this->warn('GOAL odds sync disabled (api-football.goal_odds.enabled=false).');
            return self::SUCCESS;
        }

        $this->table(['Metric', 'Value'], [
            ['Competitions queried',   $result['competitions']],
            ['API calls',              $result['api_calls']],
            ['Observations created',   $result['created']],
            ['Duplicates skipped',     $result['duplicates']],
            ['Not pre-match skipped',  $result['skipped']],
            ['Discarded selections',   $result['discarded']],
            ['Competitions throttled', $result['skipped_throttle']],
            ['Errors',                 $result['errors']],
        ]);

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
