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
 *
 * P27D2 — adds BY LEAGUE and FAVORITE ANALYSIS breakdowns on top of the SAME
 * deduped `$evaluated` set the global metrics already use (no separate
 * eligibility/dedup query, no new LogLoss/Brier/RPS formula). "By league"
 * reuses computeMetrics() verbatim per competition group. "Favorite
 * analysis" reuses the same per-row LogLoss convention (logLossForRow(),
 * extracted out of computeMetrics() so both paths share one formula) but
 * needs its own aggregation because "favorite"/"underdog" are relative to
 * who is favored (P1 vs P2), not fixed H/D/A buckets.
 *
 * P27D3 — adds CALIBRATION bins and MONTHLY performance, again on the SAME
 * deduped `$evaluated` set. Calibration reuses predictedOutcome() (the
 * confidence assigned IS max(P1,PX,P2) by construction, since that's exactly
 * which outcome predictedOutcome() picks). Monthly reuses computeMetrics()
 * verbatim per calendar month, grouped by `kickoff_at` in UTC (never
 * generated_at/result_recorded_at, which are operational timestamps, not the
 * match's own calendar date).
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
            ->with('match.competition')
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
            'by_league' => $evaluated->isEmpty() ? [] : $this->computeByLeague($evaluated),
            'favorite_analysis' => $evaluated->isEmpty() ? null : $this->computeFavoriteAnalysis($evaluated),
            'calibration' => $evaluated->isEmpty() ? null : $this->computeCalibration($evaluated),
            'monthly' => $evaluated->isEmpty() ? [] : $this->computeMonthly($evaluated),
        ];
    }

    /**
     * Same deduped $evaluated set as the GLOBAL metrics, grouped by
     * competition. Reuses computeMetrics() verbatim per group — no separate
     * formula.
     *
     * @param Collection<int, Prediction> $evaluated
     * @return array<int, array{competition_id: int, competition_name: string, metrics: array}>
     */
    private function computeByLeague(Collection $evaluated): array
    {
        $groups = $evaluated->groupBy(fn (Prediction $r) => $r->match?->competition_id ?? 0);

        $result = [];
        foreach ($groups as $competitionId => $group) {
            $competition = $group->first()->match?->competition;
            $result[] = [
                'competition_id' => (int) $competitionId,
                'competition_name' => $competition?->name ?? 'Unknown',
                'metrics' => $this->computeMetrics($group),
            ];
        }

        usort($result, fn (array $a, array $b) => strcmp($a['competition_name'], $b['competition_name']));

        return $result;
    }

    /**
     * Favorite = max(P1, P2); draw can never be the favorite. P1 == P2 is
     * classified NO_CLEAR_FAVORITE and excluded from both the HOME/AWAY
     * classification breakdown and the strength buckets, but counted
     * separately (never arbitrarily assigned to home or away).
     *
     * @param Collection<int, Prediction> $evaluated
     * @return array{
     *   no_clear_favorite_count: int,
     *   by_classification: array<string, array|null>,
     *   by_bucket: array<string, array|null>,
     * }
     */
    private function computeFavoriteAnalysis(Collection $evaluated): array
    {
        $noClearFavoriteCount = 0;
        $byClassification = ['HOME_FAVORITE' => [], 'AWAY_FAVORITE' => []];
        $byBucket = ['<0.45' => [], '0.45-0.55' => [], '0.55-0.65' => [], '>=0.65' => []];

        foreach ($evaluated as $row) {
            $pHome = (float) $row->probability_home;
            $pAway = (float) $row->probability_away;

            if ($pHome === $pAway) {
                $noClearFavoriteCount++;
                continue;
            }

            $stats = $this->favoriteRowStats($row);
            $byClassification[$stats['classification']][] = $stats;
            $byBucket[$this->strengthBucket($stats['favorite_probability'])][] = $stats;
        }

        return [
            'no_clear_favorite_count' => $noClearFavoriteCount,
            'by_classification' => [
                'HOME_FAVORITE' => $this->aggregateFavoriteGroup($byClassification['HOME_FAVORITE']),
                'AWAY_FAVORITE' => $this->aggregateFavoriteGroup($byClassification['AWAY_FAVORITE']),
            ],
            'by_bucket' => [
                '<0.45' => $this->aggregateFavoriteGroup($byBucket['<0.45']),
                '0.45-0.55' => $this->aggregateFavoriteGroup($byBucket['0.45-0.55']),
                '0.55-0.65' => $this->aggregateFavoriteGroup($byBucket['0.55-0.65']),
                '>=0.65' => $this->aggregateFavoriteGroup($byBucket['>=0.65']),
            ],
        ];
    }

    /**
     * Per-row relative stats for favorite analysis. classification/favorite/
     * underdog are relative to which side (home or away) has the higher
     * probability — never Draw.
     *
     * @return array{classification: string, favorite_probability: float, favorite_win: bool, draw_probability: float, draw_actual: bool, underdog_probability: float, underdog_win: bool, log_loss: float}
     */
    private function favoriteRowStats(Prediction $row): array
    {
        $pHome = (float) $row->probability_home;
        $pDraw = (float) $row->probability_draw;
        $pAway = (float) $row->probability_away;
        $actual = $row->outcome;

        $isHomeFavorite = $pHome > $pAway;
        $classification = $isHomeFavorite ? 'HOME_FAVORITE' : 'AWAY_FAVORITE';

        $favoriteProbability = $isHomeFavorite ? $pHome : $pAway;
        $underdogProbability = $isHomeFavorite ? $pAway : $pHome;
        $favoriteOutcome = $isHomeFavorite ? '1' : '2';
        $underdogOutcome = $isHomeFavorite ? '2' : '1';

        return [
            'classification' => $classification,
            'favorite_probability' => $favoriteProbability,
            'favorite_win' => $actual === $favoriteOutcome,
            'draw_probability' => $pDraw,
            'draw_actual' => $actual === 'X',
            'underdog_probability' => $underdogProbability,
            'underdog_win' => $actual === $underdogOutcome,
            'log_loss' => $this->logLossForRow($pHome, $pDraw, $pAway, $actual),
        ];
    }

    private function strengthBucket(float $favoriteProbability): string
    {
        if ($favoriteProbability < 0.45) {
            return '<0.45';
        }
        if ($favoriteProbability < 0.55) {
            return '0.45-0.55';
        }
        if ($favoriteProbability < 0.65) {
            return '0.55-0.65';
        }

        return '>=0.65';
    }

    /**
     * @param array<int, array{favorite_probability: float, favorite_win: bool, draw_probability: float, draw_actual: bool, underdog_probability: float, underdog_win: bool, log_loss: float}> $rowsStats
     * @return array{n: int, mean_favorite_probability: float, actual_favorite_win_rate: float, mean_predicted_draw_probability: float, actual_draw_rate: float, mean_predicted_underdog_probability: float, actual_underdog_win_rate: float, log_loss: float}|null
     */
    private function aggregateFavoriteGroup(array $rowsStats): ?array
    {
        $n = count($rowsStats);
        if ($n === 0) {
            return null;
        }

        $sumFavoriteProbability = 0.0;
        $sumFavoriteWin = 0;
        $sumDrawProbability = 0.0;
        $sumDrawActual = 0;
        $sumUnderdogProbability = 0.0;
        $sumUnderdogWin = 0;
        $sumLogLoss = 0.0;

        foreach ($rowsStats as $s) {
            $sumFavoriteProbability += $s['favorite_probability'];
            $sumFavoriteWin += $s['favorite_win'] ? 1 : 0;
            $sumDrawProbability += $s['draw_probability'];
            $sumDrawActual += $s['draw_actual'] ? 1 : 0;
            $sumUnderdogProbability += $s['underdog_probability'];
            $sumUnderdogWin += $s['underdog_win'] ? 1 : 0;
            $sumLogLoss += $s['log_loss'];
        }

        return [
            'n' => $n,
            'mean_favorite_probability' => $sumFavoriteProbability / $n,
            'actual_favorite_win_rate' => $sumFavoriteWin / $n,
            'mean_predicted_draw_probability' => $sumDrawProbability / $n,
            'actual_draw_rate' => $sumDrawActual / $n,
            'mean_predicted_underdog_probability' => $sumUnderdogProbability / $n,
            'actual_underdog_win_rate' => $sumUnderdogWin / $n,
            'log_loss' => $sumLogLoss / $n,
        ];
    }

    /**
     * Confidence = max(P1, PX, P2) — the probability mass the model actually
     * put behind its own predicted outcome (predictedOutcome() picks exactly
     * that outcome, so no separate "which is max" logic is introduced here).
     * Diagnostic only — no isotonic regression, no Platt scaling, no
     * correction is applied anywhere in this method.
     *
     * @param Collection<int, Prediction> $evaluated
     * @return array<string, array{n: int, mean_confidence: float, actual_accuracy: float, calibration_gap: float}|null>
     */
    private function computeCalibration(Collection $evaluated): array
    {
        $buckets = [
            '[0.30,0.40)' => [],
            '[0.40,0.50)' => [],
            '[0.50,0.60)' => [],
            '[0.60,0.70)' => [],
            '>=0.70' => [],
        ];

        foreach ($evaluated as $row) {
            $pHome = (float) $row->probability_home;
            $pDraw = (float) $row->probability_draw;
            $pAway = (float) $row->probability_away;
            $actual = $row->outcome;

            $predicted = $this->predictedOutcome($pHome, $pDraw, $pAway);
            $confidence = max($pHome, $pDraw, $pAway);

            $buckets[$this->calibrationBucket($confidence)][] = [
                'confidence' => $confidence,
                'correct' => $predicted === $actual,
            ];
        }

        $result = [];
        foreach ($buckets as $label => $rows) {
            $result[$label] = $this->aggregateCalibrationBucket($rows);
        }

        return $result;
    }

    /**
     * Boundaries fixed by P27D3 spec: [0.30,0.40), [0.40,0.50), [0.50,0.60),
     * [0.60,0.70), >=0.70. No extra bucket below 0.30 is added — confidence
     * (max of the three 1X2 probabilities) cannot normally fall below 1/3.
     */
    private function calibrationBucket(float $confidence): string
    {
        if ($confidence < 0.40) {
            return '[0.30,0.40)';
        }
        if ($confidence < 0.50) {
            return '[0.40,0.50)';
        }
        if ($confidence < 0.60) {
            return '[0.50,0.60)';
        }
        if ($confidence < 0.70) {
            return '[0.60,0.70)';
        }

        return '>=0.70';
    }

    /**
     * @param array<int, array{confidence: float, correct: bool}> $rows
     * @return array{n: int, mean_confidence: float, actual_accuracy: float, calibration_gap: float}|null
     */
    private function aggregateCalibrationBucket(array $rows): ?array
    {
        $n = count($rows);
        if ($n === 0) {
            return null;
        }

        $sumConfidence = 0.0;
        $sumCorrect = 0;
        foreach ($rows as $r) {
            $sumConfidence += $r['confidence'];
            $sumCorrect += $r['correct'] ? 1 : 0;
        }

        $meanConfidence = $sumConfidence / $n;
        $actualAccuracy = $sumCorrect / $n;

        return [
            'n' => $n,
            'mean_confidence' => $meanConfidence,
            'actual_accuracy' => $actualAccuracy,
            'calibration_gap' => $meanConfidence - $actualAccuracy,
        ];
    }

    /**
     * Grouped by the match's own kickoff_at (UTC), never generated_at/
     * result_recorded_at — those are operational timestamps, not the
     * match's calendar date. Reuses computeMetrics() verbatim per month; no
     * moving average, no trend fitting, no automatic drift interpretation.
     *
     * @param Collection<int, Prediction> $evaluated
     * @return array<int, array{month: string, metrics: array}> ascending chronological order
     */
    private function computeMonthly(Collection $evaluated): array
    {
        $groups = $evaluated->groupBy(fn (Prediction $r) => $r->kickoff_at->copy()->utc()->format('Y-m'));

        $result = [];
        foreach ($groups as $month => $group) {
            $result[] = [
                'month' => $month,
                'metrics' => $this->computeMetrics($group),
            ];
        }

        usort($result, fn (array $a, array $b) => strcmp($a['month'], $b['month']));

        return $result;
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

            $sumLogLoss += $this->logLossForRow($pHome, $pDraw, $pAway, $actual);

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

    /**
     * Single source of the LogLoss-per-row formula (-log(max(p_actual,
     * 1e-12))), shared by computeMetrics() (global/by-league) and
     * favoriteRowStats() (favorite analysis) so there is exactly one place
     * that implements it.
     */
    private function logLossForRow(float $pHome, float $pDraw, float $pAway, string $actualOutcome): float
    {
        $probByOutcome = ['1' => $pHome, 'X' => $pDraw, '2' => $pAway];

        return -log(max($probByOutcome[$actualOutcome], self::LOG_LOSS_FLOOR));
    }
}
