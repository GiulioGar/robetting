<?php

namespace App\Console\Commands;

use App\Services\Odds\GoalEvaluationRecorder;
use Illuminate\Console\Command;

/**
 * Records the append-only PRE-MATCH GOAL evaluations (official Candidate47 vs stored bookmaker quotes). No API calls.
 * Wired into the robetting:serve startup after the official predictions capture.
 */
class RecordGoalEvaluations extends Command
{
    protected $signature = 'robetting:record-goal-evaluations';

    protected $description = 'Record append-only pre-match GOAL evaluations (official Candidate47 vs bookmaker quotes).';

    public function handle(): int
    {
        $r = GoalEvaluationRecorder::recordPending();
        $this->line("matches={$r['matches']}  created={$r['created']}  duplicates={$r['duplicates']}");

        return self::SUCCESS;
    }
}
