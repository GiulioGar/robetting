<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballOddsSyncService;
use Illuminate\Console\Command;

/**
 * Manual run of the Odds V1 prospective collection (PRE-MATCH 1X2, append-only).
 * `robetting:serve` runs the same service automatically with its ~3h throttle.
 */
class SyncApiFootballOdds extends Command
{
    protected $signature = 'robetting:sync-odds
        {--competition= : Only this in-scope competition slug (e.g. serie-a, uefa-champions-league)}
        {--force : Ignore the per-competition throttle (manual runs only)}';

    protected $description = 'Collect PRE-MATCH 1X2 (Match Winner) odds from API-Football into the append-only odds_snapshots table.';

    public function handle(ApiFootballOddsSyncService $service): int
    {
        $result = $service->syncPending(
            $this->option('competition') ?: null,
            (bool) $this->option('force'),
        );

        if (! $result['enabled']) {
            $this->warn('Odds sync disabled (api-football.odds.enabled=false).');
            return self::SUCCESS;
        }

        $this->table(['Metric', 'Value'], [
            ['Competitions queried', $result['competitions']],
            ['API calls',            $result['api_calls']],
            ['Snapshots created',    $result['created']],
            ['Duplicates skipped',   $result['duplicates']],
            ['Not pre-match skipped', $result['skipped']],
            ['Warnings',             $result['warnings']],
            ['Competitions throttled', $result['skipped_throttle']],
            ['Errors',               $result['errors']],
        ]);

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
