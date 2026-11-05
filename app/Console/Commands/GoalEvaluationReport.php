<?php

namespace App\Console\Commands;

use App\Services\Odds\GoalEvaluationOutcomeService;
use Illuminate\Console\Command;

/**
 * Read-only report: GOAL evaluation register vs real regulation (90') results. No writes, no API.
 */
class GoalEvaluationReport extends Command
{
    protected $signature = 'robetting:goal-evaluation-report';

    protected $description = 'Read-only summary of settled GOAL evaluations (first evaluation per match/market/line/selection, 1-unit theoretical return).';

    public function handle(): int
    {
        $r = GoalEvaluationOutcomeService::report();

        $this->line("registered={$r['registered_evaluations']}  first-per-selection={$r['first_evaluations']}  matches_in_register={$r['matches_in_register']}  not_settleable={$r['not_settleable']}  unsupported={$r['unsupported']}");

        $rows = [['overall', $r['overall']]];
        foreach ($r['by_market'] as $k => $s) {
            $rows[] = ["market:$k", $s];
        }
        foreach ($r['by_ev_sign'] as $k => $s) {
            $rows[] = [$k, $s];
        }

        $f = fn ($v, int $d = 4) => $v === null ? 'n/a' : number_format((float) $v, $d, '.', '');
        $this->table(
            ['group', 'matches', 'sel', 'won', 'lost', 'profit', 'ROI', 'brier_model', 'brier_cons'],
            array_map(fn ($x) => [$x[0], $x[1]['matches'], $x[1]['selections'], $x[1]['won'], $x[1]['lost'],
                $f($x[1]['profit'], 2), $f($x[1]['roi']), $f($x[1]['brier_model']), $f($x[1]['brier_consensus'])], $rows)
        );
        $this->warn('Independent units = matches (' . $r['caveats']['independent_units_are_matches'] . '). ' . $r['caveats']['note']);

        return self::SUCCESS;
    }
}
