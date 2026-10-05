<?php

namespace App\Console\Commands;

use App\Services\Prediction\PredictionEvaluationService;
use Illuminate\Console\Command;

/**
 * P27D1 — read-only report comparing resolved official predictions
 * (`predictions` table) against their real outcome, using
 * PredictionEvaluationService. No DB writes, no model/artifact access.
 */
class EvaluateOfficialPredictions extends Command
{
    protected $signature = 'robetting:evaluate-official-predictions
        {--model-key=candidate47_structural_log}
        {--model-version=}';

    protected $description = 'Report LogLoss/Brier/RPS/accuracy for resolved official predictions of one model.';

    public function handle(PredictionEvaluationService $service): int
    {
        $modelKey = (string) $this->option('model-key');
        $modelVersion = $this->option('model-version');
        $modelVersion = $modelVersion !== null && $modelVersion !== '' ? (string) $modelVersion : null;

        $reports = $service->evaluate($modelKey, $modelVersion);

        if ($reports === []) {
            $this->line("No predictions found for model_key={$modelKey}" . ($modelVersion !== null ? " model_version={$modelVersion}" : '') . '.');
            $this->line('NO EVALUABLE OFFICIAL PREDICTIONS YET');

            return Command::SUCCESS;
        }

        foreach ($reports as $i => $report) {
            if ($i > 0) {
                $this->newLine();
                $this->line('────────────────────────────────────────');
                $this->newLine();
            }

            $this->renderReport($report);
        }

        return Command::SUCCESS;
    }

    private function renderReport(array $report): void
    {
        $this->info("model_key={$report['model_key']}  model_version={$report['model_version']}");

        $d = $report['dataset'];
        $this->newLine();
        $this->info('=== DATASET ===');
        $this->line('total:                         ' . $d['total']);
        $this->line('resolved:                      ' . $d['resolved']);
        $this->line('evaluated:                     ' . $d['evaluated']);
        $this->line('unresolved:                     ' . $d['unresolved']);
        $this->line('excluded awarded/walkover:      ' . $d['excluded_awarded_walkover']);
        $this->line('older duplicates excluded:      ' . $d['older_duplicates_excluded']);

        $this->newLine();
        $this->info('=== GLOBAL ===');

        $m = $report['metrics'];
        if ($m === null) {
            $this->line('NO EVALUABLE OFFICIAL PREDICTIONS YET');

            return;
        }

        $this->line('N:        ' . $m['n']);
        $this->line('LogLoss:  ' . number_format($m['log_loss'], 5));
        $this->line('Brier:    ' . number_format($m['brier'], 5));
        $this->line('RPS:      ' . number_format($m['rps'], 5));
        $this->line('Accuracy: ' . number_format($m['accuracy'] * 100, 2) . '%');

        $this->newLine();
        $this->line('predicted mean:');
        $this->line('  P1: ' . number_format($m['predicted_mean']['1'], 4));
        $this->line('  PX: ' . number_format($m['predicted_mean']['X'], 4));
        $this->line('  P2: ' . number_format($m['predicted_mean']['2'], 4));

        $this->newLine();
        $this->line('actual:');
        $this->line('  1: ' . number_format($m['actual_frequency']['1'], 4));
        $this->line('  X: ' . number_format($m['actual_frequency']['X'], 4));
        $this->line('  2: ' . number_format($m['actual_frequency']['2'], 4));

        $this->newLine();
        $this->line('bias (predicted - actual):');
        $this->line('  1: ' . number_format($m['bias']['1'], 4));
        $this->line('  X: ' . number_format($m['bias']['X'], 4));
        $this->line('  2: ' . number_format($m['bias']['2'], 4));

        $this->renderByLeague($report['by_league']);
        $this->renderFavoriteAnalysis($report['favorite_analysis']);
    }

    private function renderByLeague(array $byLeague): void
    {
        if ($byLeague === []) {
            return;
        }

        $this->newLine();
        $this->info('=== BY LEAGUE ===');

        foreach ($byLeague as $league) {
            $m = $league['metrics'];
            $this->newLine();
            $this->line($league['competition_name'] . ' (competition_id=' . $league['competition_id'] . '):');
            $this->line('  N:        ' . $m['n']);
            $this->line('  LogLoss:  ' . number_format($m['log_loss'], 5));
            $this->line('  Brier:    ' . number_format($m['brier'], 5));
            $this->line('  RPS:      ' . number_format($m['rps'], 5));
            $this->line('  Accuracy: ' . number_format($m['accuracy'] * 100, 2) . '%');
            $this->line('  predicted mean: P1=' . number_format($m['predicted_mean']['1'], 4)
                . ' PX=' . number_format($m['predicted_mean']['X'], 4)
                . ' P2=' . number_format($m['predicted_mean']['2'], 4));
            $this->line('  actual freq:    1=' . number_format($m['actual_frequency']['1'], 4)
                . ' X=' . number_format($m['actual_frequency']['X'], 4)
                . ' 2=' . number_format($m['actual_frequency']['2'], 4));
        }
    }

    private function renderFavoriteAnalysis(?array $favorite): void
    {
        if ($favorite === null) {
            return;
        }

        $this->newLine();
        $this->info('=== FAVORITE ANALYSIS ===');
        $this->line('NO_CLEAR_FAVORITE (P1 == P2): ' . $favorite['no_clear_favorite_count']);

        $this->newLine();
        $this->line('By classification:');
        foreach ($favorite['by_classification'] as $label => $group) {
            $this->renderFavoriteGroup('  ' . $label, $group);
        }

        $this->newLine();
        $this->line('By favorite strength bucket:');
        foreach ($favorite['by_bucket'] as $label => $group) {
            $this->renderFavoriteGroup('  ' . $label, $group);
        }
    }

    private function renderFavoriteGroup(string $label, ?array $group): void
    {
        if ($group === null) {
            $this->line($label . ': N=0');

            return;
        }

        $this->line($label . ':');
        $this->line('    N:                              ' . $group['n']);
        $this->line('    mean favorite probability:      ' . number_format($group['mean_favorite_probability'], 4));
        $this->line('    actual favorite win rate:       ' . number_format($group['actual_favorite_win_rate'], 4));
        $this->line('    mean predicted draw probability:' . number_format($group['mean_predicted_draw_probability'], 4));
        $this->line('    actual draw rate:               ' . number_format($group['actual_draw_rate'], 4));
        $this->line('    mean predicted underdog prob.:  ' . number_format($group['mean_predicted_underdog_probability'], 4));
        $this->line('    actual underdog win rate:       ' . number_format($group['actual_underdog_win_rate'], 4));
        $this->line('    LogLoss:                        ' . number_format($group['log_loss'], 5));
    }
}
