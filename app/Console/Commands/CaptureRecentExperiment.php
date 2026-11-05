<?php

namespace App\Console\Commands;

use App\Services\Prediction\RecentExperimentCaptureService;
use Illuminate\Console\Command;

/**
 * Records the parallel Candidate47 pair (Team History Recent vs RECENT_ALL) for upcoming UEFA fixtures, purpose
 * recent_exp (never read by the official Learning Loop). No API calls. Idempotent. Wired into robetting:serve startup.
 */
class CaptureRecentExperiment extends Command
{
    protected $signature = 'robetting:capture-recent-experiment
        {--within-hours=72 : Only UEFA fixtures kicking off within this many hours}
        {--dry-run : List eligible fixtures without writing}';

    protected $description = 'Capture the parallel Candidate47 Team History vs RECENT_ALL experiment pair for upcoming UEFA fixtures.';

    public function handle(RecentExperimentCaptureService $service): int
    {
        $matches = $service->findEligibleMatches((int) $this->option('within-hours'));
        $counts = [];

        foreach ($matches as $match) {
            if ($this->option('dry-run')) {
                $this->line("  would capture #{$match->id} {$match->homeTeam->name} - {$match->awayTeam->name} ({$match->kickoff_at})");
                continue;
            }
            $r = $service->capture($match);
            $counts[$r['outcome']] = ($counts[$r['outcome']] ?? 0) + 1;
            if (in_array($r['outcome'], ['ERROR', 'MODEL_UNAVAILABLE'], true)) {
                $this->warn("  #{$match->id}: {$r['outcome']} — " . ($r['reason'] ?? ''));
            }
        }

        $this->line('eligible=' . $matches->count() . '  ' . json_encode($counts));

        return self::SUCCESS;
    }
}
