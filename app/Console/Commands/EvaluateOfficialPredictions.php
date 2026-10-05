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
    }
}
