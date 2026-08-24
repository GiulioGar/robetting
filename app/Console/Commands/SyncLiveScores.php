<?php

namespace App\Console\Commands;

use App\Services\Imports\LiveScoreSyncService;
use Illuminate\Console\Command;

class SyncLiveScores extends Command
{
    protected $signature = 'robetting:sync-live-scores
                            {--hours-after-kickoff= : (live mode) how long after kickoff a match is still considered "in progress" — defaults to imports.live_sync_hours_after_kickoff}
                            {--catch-up : Reconciliation mode instead of live mode — finds past-kickoff matches still not finalized in DB (current season per league) and refreshes them}
                            {--max-days= : (catch-up mode) lookback window in days — defaults to imports.catch_up_max_days}';

    protected $description = 'Poll football-data.org for score/status updates: live mode (matches currently in progress) or --catch-up mode (past matches never finalized in DB)';

    public function handle(LiveScoreSyncService $service): int
    {
        return $this->option('catch-up')
            ? $this->runCatchUp($service)
            : $this->runLiveSync($service);
    }

    private function runLiveSync(LiveScoreSyncService $service): int
    {
        $hoursAfterKickoff = $this->option('hours-after-kickoff');

        try {
            $report = $service->sync($hoursAfterKickoff !== null ? (int) $hoursAfterKickoff : null, $this->output);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($report['status'] === 'idle') {
            $this->line('[INFO]  No matches in progress — nothing to sync.');

            return self::SUCCESS;
        }

        foreach ($report['leagues'] as $league) {
            if ($league['status'] === 'success') {
                $result = $league['result']['matches'];
                $this->line(sprintf(
                    '[OK]    %s: %d updated, %d linked, %d created',
                    $league['slug'], $result['updated'], $result['linked'], $result['created'],
                ));
            } else {
                $this->error("[{$league['status']}]  {$league['slug']}: {$league['error']}");
            }
        }

        return $report['status'] === 'success' ? self::SUCCESS : self::FAILURE;
    }

    private function runCatchUp(LiveScoreSyncService $service): int
    {
        $maxDays = $this->option('max-days');

        try {
            $report = $service->catchUp($maxDays !== null ? (int) $maxDays : null, $this->output);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $hadErrors = false;

        foreach ($report['leagues'] as $league) {
            $this->newLine();
            $this->line("<fg=cyan>{$league['slug']}</> " . ($league['season'] ? "({$league['season']})" : '(nessuna stagione)'));
            $this->line("  Candidates:       {$league['candidates']}");
            $this->line("  Checked:          {$league['checked']}");
            $this->line("  Updated:          {$league['updated']}");
            $this->line("  Already current:  {$league['already_current']}");
            $this->line("  Conflicts:        {$league['conflicts']}");
            $this->line("  Errors:           {$league['errors']}");

            if (!empty($league['error_messages'])) {
                $hadErrors = true;
                foreach ($league['error_messages'] as $msg) {
                    $this->error("    - {$msg}");
                }
            }
        }

        $this->newLine();

        return $hadErrors ? self::FAILURE : self::SUCCESS;
    }
}
