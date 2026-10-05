<?php

namespace App\Services\Prediction;

use App\Models\Prediction;
use Illuminate\Support\Collection;

/**
 * P27D1 — core of the Learning Loop's Evaluation Engine: scores resolved
 * official predictions against their real outcome using the exact LogLoss/
 * Brier/RPS conventions already established in this project (see below for
 * sources), with no DB writes — metrics are computed on demand, never
 * persisted (same "on-demand, not persisted" policy already used for
 * PredictionSnapshotsStatus/LatentFitMatchEligibility).
 *
 * Formula provenance (audited before implementing, per P27D1 instructions):
 *   - LogLoss = -log(max(p_actual, 1e-12)), mean over N.
 *     Source: tools/scripts/p16a_step4_train_compare.py:264-289 (eval_model),
 *     the only place in the repo that already scores a 1X2 candidate model.
 *   - Brier = sum over {H,D,A} of (p_i - onehot_i)^2, mean over N (NOT
 *     divided by 3 — summed, exactly as in the same eval_model()).
 *   - RPS: no prior CODE implementation exists anywhere in the repo (only
 *     tools/analysis/*.py's `brier()` helpers, which are binary xG Brier,
 *     unrelated to 1X2). The formula is instead taken from the project's own
 *     canonical knowledge base, knowledge/evaluation/rps.md section 2 ("Robetting
 *     requirement" / normalized convention, divide by K-1=2) and section 12
 *     (canonical internal ordering Away < Draw < Home). That document is this
 *     project's documented methodology decision, not an external guess.
 *
 * Tie-break for predicted_outcome reuses the exact priority order already
 * used in p16a_step4_train_compare.py:279 (Home wins ties, then Away, then Draw).
 *
 * Duplicate policy: for a given (match_id, model_key, model_version), only
 * the resolved prediction with the latest generated_at enters the metrics;
 * older rows are counted but excluded. Tie-break on identical generated_at
 * falls back to the highest id (deterministic, never arbitrary).
 */
class PredictionEvaluationService
{
    private const EXCLUDED_NON_FINISHED_STATUSES = ['awarded', 'walkover'];

    private const FINISHED_STATUS = 'finished';

    private const LOG_LOSS_FLOOR = 1e-12;

    /**
     * @return array<int, array{
     *   model_key: string,
     *   model_version: string,
     *   dataset: array{total: int, resolved: int, evaluated: int, unresolved: int, excluded_awarded_walkover: int, older_duplicates_excluded: int},
     *   metrics: array{n: int, log_loss: float, brier: float, rps: float, accuracy: float, predicted_mean: array{'1': float, X: float, '2': float}, actual_frequency: array{'1': float, X: float, '2': float}, bias: array{'1': float, X: float, '2': float}}|null,
     * }>
     */
    public function evaluate(string $modelKey, ?string $modelVersion = null): array
    {
        $versions = $modelVersion !== null
            ? [$modelVersion]
            : Prediction::query()
                ->where('model_key', $modelKey)
                ->distinct()
                ->orderBy('model_version')
                ->pluck('model_version')
                ->all();

        $reports = [];
        foreach ($versions as $version) {
            $reports[] = $this->evaluateVersion($modelKey, $version);
        }

        return $reports;
    }

    private function evaluateVersion(string $modelKey, string $modelVersion): array
    {
        $rows = Prediction::query()
            ->where('model_key', $modelKey)
            ->where('model_version', $modelVersion)
            ->get();

        $total = $rows->count();

        $resolved = $rows->filter(fn (Prediction $r) => $r->home_goals !== null);
        $unresolved = $total - $resolved->count();

        $excludedStatus = $resolved->filter(
            fn (Prediction $r) => in_array($r->match_status_at_result, self::EXCLUDED_NON_FINISHED_STATUSES, true)
        );

        $finished = $resolved->filter(
            fn (Prediction $r) => $r->match_status_at_result === self::FINISHED_STATUS
        );

        [$evaluated, $olderDuplicatesExcluded] = $this->dedupeLatestPerMatch($finished);

        return [
            'model_key' => $modelKey,
            'model_version' => $modelVersion,
            'dataset' => [
                'total' => $total,
                'resolved' => $resolved->count(),
                'evaluated' => $evaluated->count(),
                'unresolved' => $unresolved,
                'excluded_awarded_walkover' => $excludedStatus->count(),
                'older_duplicates_excluded' => $olderDuplicatesExcluded,
            ],
            'metrics' => $this->computeMetrics($evaluated),
        ];
    }

    /**
     * @param Collection<int, Prediction> $finished
     * @return array{0: Collection<int, Prediction>, 1: int}
     */
    private function dedupeLatestPerMatch(Collection $finished): array
    {
        $evaluated = collect();
        $olderDuplicatesExcluded = 0;

        foreach ($finished->groupBy('match_id') as $group) {
            $latest = $group->reduce(function (?Prediction $carry, Prediction $item) {
                if ($carry === null) {
                    return $item;
                }

                $carryTs = $carry->generated_at->getTimestamp();
                $itemTs = $item->generated_at->getTimestamp();

                if ($itemTs > $carryTs || ($itemTs === $carryTs && $item->id > $carry->id)) {
                    return $item;
                }

                return $carry;
            });

            $evaluated->push($latest);
            $olderDuplicatesExcluded += $group->count() - 1;
        }

        return [$evaluated, $olderDuplicatesExcluded];
    }

    /**
     * @param Collection<int, Prediction> $evaluated
     * @return array{n: int, log_loss: float, brier: float, rps: float, accuracy: float, predicted_mean: array{'1': float, X: float, '2': float}, actual_frequency: array{'1': float, X: float, '2': float}, bias: array{'1': float, X: float, '2': float}}|null
     */
    private function computeMetrics(Collection $evaluated): ?array
    {
        $n = $evaluated->count();
        if ($n === 0) {
            return null;
        }

        $sumLogLoss = 0.0;
        $sumBrier = 0.0;
        $sumRps = 0.0;
        $correct = 0;

        $sumPredicted = ['1' => 0.0, 'X' => 0.0, '2' => 0.0];
        $countActual = ['1' => 0, 'X' => 0, '2' => 0];

        foreach ($evaluated as $row) {
            $pHome = (float) $row->probability_home;
            $pDraw = (float) $row->probability_draw;
            $pAway = (float) $row->probability_away;
            $actual = $row->outcome;

            $predicted = $this->predictedOutcome($pHome, $pDraw, $pAway);

            $probByOutcome = ['1' => $pHome, 'X' => $pDraw, '2' => $pAway];
            $pActual = max($probByOutcome[$actual], self::LOG_LOSS_FLOOR);
            $sumLogLoss += -log($pActual);

            $oneHot = [
                '1' => [1.0, 0.0, 0.0],
                'X' => [0.0, 1.0, 0.0],
                '2' => [0.0, 0.0, 1.0],
            ][$actual];
            $sumBrier += ($pHome - $oneHot[0]) ** 2 + ($pDraw - $oneHot[1]) ** 2 + ($pAway - $oneHot[2]) ** 2;

            // RPS: canonical internal ordering Away(1) < Draw(2) < Home(3),
            // per knowledge/evaluation/rps.md section 12.
            $f1 = $pAway;
            $f2 = $pAway + $pDraw;
            [$o1, $o2] = [
                '2' => [1.0, 1.0],
                'X' => [0.0, 1.0],
                '1' => [0.0, 0.0],
            ][$actual];
            $sumRps += (($f1 - $o1) ** 2 + ($f2 - $o2) ** 2) / 2;

            if ($predicted === $actual) {
                $correct++;
            }

            $sumPredicted['1'] += $pHome;
            $sumPredicted['X'] += $pDraw;
            $sumPredicted['2'] += $pAway;
            $countActual[$actual]++;
        }

        $predictedMean = [
            '1' => $sumPredicted['1'] / $n,
            'X' => $sumPredicted['X'] / $n,
            '2' => $sumPredicted['2'] / $n,
        ];
        $actualFrequency = [
            '1' => $countActual['1'] / $n,
            'X' => $countActual['X'] / $n,
            '2' => $countActual['2'] / $n,
        ];
        $bias = [
            '1' => $predictedMean['1'] - $actualFrequency['1'],
            'X' => $predictedMean['X'] - $actualFrequency['X'],
            '2' => $predictedMean['2'] - $actualFrequency['2'],
        ];

        return [
            'n' => $n,
            'log_loss' => $sumLogLoss / $n,
            'brier' => $sumBrier / $n,
            'rps' => $sumRps / $n,
            'accuracy' => $correct / $n,
            'predicted_mean' => $predictedMean,
            'actual_frequency' => $actualFrequency,
            'bias' => $bias,
        ];
    }

    /**
     * Tie-break priority exactly as in p16a_step4_train_compare.py:279 —
     * Home wins ties against Draw/Away; otherwise Away wins ties against Draw.
     */
    private function predictedOutcome(float $pHome, float $pDraw, float $pAway): string
    {
        if ($pHome >= $pDraw && $pHome >= $pAway) {
            return '1';
        }

        return $pAway >= $pDraw ? '2' : 'X';
    }
}
