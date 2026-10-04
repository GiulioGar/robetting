<?php

namespace App\Console\Commands;

use App\Models\DataSyncRun;
use App\Services\DataSources\ApiFootball\ApiFootballDataSyncStatusService;
use Illuminate\Console\Command;

/**
 * P24B — read-only freshness check: API-Football -> DB, for the 5 core
 * leagues' current season.
 *
 * Deliberately separate from `robetting:prediction-snapshots-status`, which
 * checks a different layer of the chain (DB -> Latent/Structural snapshots
 * -> Candidate V2 LOG) and costs zero API calls. This command DOES make real
 * API-Football calls (capped, see ApiFootballDataSyncStatusService::MAX_API_CALLS_PER_RUN)
 * but never writes to matches, match_external_ids, or data_sync_runs — it
 * only reports differences, it never fixes them.
 */
class DataSyncStatus extends Command
{
    protected $signature = 'robetting:data-sync-status';

    protected $description = 'Read-only check: is the DB aligned with API-Football for the 5 core leagues (current season)?';

    public function handle(ApiFootballDataSyncStatusService $service): int
    {
        $this->showLastSyncInfo();

        $result = $service->checkAll();

        $anyError = false;
        $anyStale = false;
        $totals = ['missing' => 0, 'kickoff_diff' => 0, 'status_diff' => 0, 'score_diff' => 0];

        foreach ($result['leagues'] as $league) {
            $this->printLeague($league);
            $this->newLine();

            if ($league['status'] === 'ERROR') {
                $anyError = true;
            } elseif ($league['status'] === 'STALE') {
                $anyStale = true;
            }

            $totals['missing']      += count($league['missing'] ?? []);
            $totals['kickoff_diff'] += count($league['kickoff_diff'] ?? []);
            $totals['status_diff']  += count($league['status_diff'] ?? []);
            $totals['score_diff']   += count($league['score_diff'] ?? []);
        }

        $this->info('=== GLOBAL ===');
        if ($anyError) {
            $this->error('API -> DB: ERROR');
        } elseif ($anyStale) {
            $this->error('API -> DB: STALE');
        } else {
            $this->info('API -> DB: CURRENT');
        }

        $this->line("API calls used: {$result['api_calls_used']}");
        $this->line("missing: {$totals['missing']}  kickoff_diff: {$totals['kickoff_diff']}  "
            . "status_diff: {$totals['status_diff']}  score_diff: {$totals['score_diff']}");
        if ($result['requests_remaining'] !== null) {
            $this->line("daily quota remaining: {$result['requests_remaining']}");
        }

        if ($anyError) {
            return 2;
        }

        return $anyStale ? 1 : 0;
    }

    /**
     * Informational only — read from data_sync_runs, never written here.
     * These timestamps do NOT determine CURRENT/STALE; only the live
     * API<->DB comparison above does.
     */
    private function showLastSyncInfo(): void
    {
        $lastFixtureSync = DataSyncRun::where('sync_type', 'fixture_sync')
            ->orderByDesc('started_at')->first();
        $lastResultRefresh = DataSyncRun::whereIn('sync_type', ['result_refresh', 'catch_up'])
            ->orderByDesc('started_at')->first();

        $this->info('=== LAST KNOWN SYNC ACTIVITY (informational only — does not affect verdict) ===');
        $this->line('Last fixture_sync:    ' . ($lastFixtureSync?->started_at?->toIso8601String() ?? 'never'));
        $this->line('Last result_refresh:  ' . ($lastResultRefresh?->started_at?->toIso8601String() ?? 'never'));
        $this->newLine();
    }

    private function printLeague(array $league): void
    {
        $this->info("=== {$league['competition_slug']} (league_id={$league['league_id']}) ===");

        if ($league['status'] === 'ERROR') {
            $this->error('STATUS: ERROR');
            $this->line('message: ' . ($league['message'] ?? 'unknown error'));
            if (($league['api_calls'] ?? 0) > 0) {
                $this->line('api_calls: ' . $league['api_calls']);
            }
            return;
        }

        $this->line('season:                 ' . ($league['season'] ?? 'n/a'));
        $this->line('api_fixtures_received:  ' . $league['api_fixtures_received']);
        $this->line('db_fixtures_compared:   ' . $league['db_fixtures_compared']);
        $this->line('missing:                ' . count($league['missing']));
        $this->line('kickoff_diff:           ' . count($league['kickoff_diff']));
        $this->line('status_diff:            ' . count($league['status_diff']));
        $this->line('score_diff:             ' . count($league['score_diff']));
        $this->line('api_calls:              ' . $league['api_calls']);
        if ($league['requests_remaining'] !== null) {
            $this->line('requests_remaining:     ' . $league['requests_remaining']);
        }

        if ($league['status'] === 'STALE') {
            $this->error('STATUS: STALE');
            $this->printSample('  missing examples:     ', $league['missing']);
            $this->printSample('  kickoff_diff examples:', $league['kickoff_diff']);
            $this->printSample('  status_diff examples: ', $league['status_diff']);
            $this->printSample('  score_diff examples:  ', $league['score_diff']);
        } else {
            $this->info('STATUS: CURRENT');
        }
    }

    private function printSample(string $label, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $sample = array_slice($ids, 0, 5);
        $suffix = count($ids) > 5 ? ' (+' . (count($ids) - 5) . ' more)' : '';
        $this->line($label . ' ' . implode(', ', $sample) . $suffix);
    }
}
