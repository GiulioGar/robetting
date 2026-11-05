<?php

namespace App\Console\Commands;

use App\Services\Prediction\RecentExperimentReportService;
use Illuminate\Console\Command;

/**
 * Read-only: Team History vs RECENT_ALL (Candidate47, purpose recent_exp) on concluded UEFA matches. No writes, no API.
 */
class RecentExperimentReport extends Command
{
    protected $signature = 'robetting:recent-experiment-report';

    protected $description = 'Compare the parallel Candidate47 Team History vs RECENT_ALL predictions on concluded matches (Brier, log loss).';

    public function handle(RecentExperimentReportService $service): int
    {
        $r = $service->build();
        $f = fn ($v, int $d = 4) => $v === null ? 'n/a' : number_format((float) $v, $d, '.', '');

        $this->line("recorded predictions={$r['predictions_recorded']}  matches with rows={$r['matches_with_rows']}  EVALUABLE MATCHES={$r['evaluable']}");
        foreach ($r['excluded']['incoherent_pair'] as $id => $why) {
            $this->warn("  excluded #{$id}: incoherent pair — {$why}");
        }
        $reasons = array_count_values($r['excluded']['not_evaluable']);
        if ($reasons !== []) {
            $this->line('  not evaluable yet: ' . collect($reasons)->map(fn ($n, $k) => "{$k}={$n}")->implode(', '));
        }

        if ($r['evaluable'] === 0) {
            $this->warn('0 evaluable cases: no coherent pair belongs to a finished, certified match yet. Nothing is reconstructed retroactively.');

            return self::SUCCESS;
        }

        $s = $r['summary'];
        $this->newLine();
        $this->table(['variant', 'matches', 'Brier (3-class)', 'log loss', 'accuracy'], [
            ['team_history', $s['n_matches'], $f($s['team_history']['brier']), $f($s['team_history']['log_loss']), $f($s['team_history']['accuracy'], 3)],
            ['recent_all',   $s['n_matches'], $f($s['recent_all']['brier']),   $f($s['recent_all']['log_loss']),   $f($s['recent_all']['accuracy'], 3)],
            ['diff (ALL-TH, + = ALL worse)', '', $f($s['brier_diff'], 4), $f($s['log_loss_diff'], 4), ''],
        ]);
        $this->line("matches where RECENT_ALL log loss is worse={$s['recent_all_worse_log_loss']} better={$s['recent_all_better_log_loss']} identical predictions={$s['identical_predictions']}");

        $this->newLine();
        $this->table(['match', 'kickoff', 'FT', 'p TH (1/X/2)', 'p ALL (1/X/2)', 'dBrier', 'dLogLoss', 'struct gap', 'shot cov ALL'], array_map(fn ($d) => [
            "#{$d['match_id']} {$d['label']}", $d['kickoff']->format('d/m H:i'), $d['score'] . ' (' . $d['outcome'] . ')',
            implode('/', array_map(fn ($p) => number_format($p, 3), $d['th']['p'])), implode('/', array_map(fn ($p) => number_format($p, 3), $d['all']['p'])),
            sprintf('%+.4f', $d['brier_diff']), sprintf('%+.4f', $d['log_loss_diff']), $f($d['structural_gap'], 3), $f($d['shot_coverage_all'], 2),
        ], $r['matches']));

        $x = $r['descriptive'];
        $this->line("variants differ in {$x['matches_where_variants_differ']} match(es); corr(dLogLoss, |structural gap|)=" . $f($x['corr_log_loss_diff_vs_abs_structural_gap'], 3)
            . "  corr(dLogLoss, ALL shot coverage)=" . $f($x['corr_log_loss_diff_vs_all_shot_coverage'], 3));
        $this->warn($x['note']);

        return self::SUCCESS;
    }
}
