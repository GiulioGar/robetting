<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Services\Odds\GoalEvaluationOutcomeService;
use Illuminate\Support\Collection;

/**
 * Read-only comparison of the two parallel Candidate47 variants recorded by RecentExperimentCaptureService
 * (purpose recent_exp): Team History Recent vs RECENT_ALL, on CONCLUDED matches only. Nothing is written or recomputed:
 * probabilities are the stored ones, outcomes come from the certified match result.
 *
 * Evaluable match = a coherent pair (one row per variant, same generated_at / artifact / versions / snapshots / kickoff,
 * prediction strictly before kickoff) whose match is `finished`, has definitive_at and a valid FT (90') score.
 * awarded/walkover/abandoned/postponed/live/scheduled matches never count. One match weighs exactly once.
 *
 * Metrics reuse PredictionEvaluationService::computeMetrics() (multiclass Brier = sum over the 3 classes, LogLoss with the
 * same 1e-12 floor), identically for both variants. Differences are RECENT_ALL minus Team History: positive = RECENT_ALL worse.
 */
final class RecentExperimentReportService
{
    public function __construct(private readonly PredictionEvaluationService $metrics) {}

    /** @return array<string,mixed> */
    public function build(): array
    {
        $rows = Prediction::where('purpose', Prediction::PURPOSE_RECENT_EXPERIMENT)
            ->whereIn('model_key', [RecentExperimentCaptureService::MODEL_KEY_TEAM_HISTORY, RecentExperimentCaptureService::MODEL_KEY_RECENT_ALL])
            ->orderBy('id')->get()->groupBy('match_id');
        $matches = FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])->whereIn('id', $rows->keys())->get()->keyBy('id');

        $excluded = ['incoherent_pair' => [], 'not_evaluable' => []];
        $details = [];

        foreach ($rows as $matchId => $group) {
            $pair = $this->pair($group);
            if (is_string($pair)) {
                $excluded['incoherent_pair'][$matchId] = $pair;
                continue;
            }

            $match = $matches->get($matchId);
            $score = GoalEvaluationOutcomeService::regulationScore($match);
            if ($score === null) {
                $excluded['not_evaluable'][$matchId] = $this->notEvaluableReason($match);
                continue;
            }

            $outcome = $score[0] > $score[1] ? '1' : ($score[0] === $score[1] ? 'X' : '2');
            $th  = $this->scored($pair['th'], $outcome);
            $all = $this->scored($pair['all'], $outcome);
            $meta = $pair['all']->features_json['_experiment'] ?? [];

            $details[] = [
                'match_id'      => (int) $matchId,
                'label'         => ($match->homeTeam?->name ?? '?') . ' - ' . ($match->awayTeam?->name ?? '?'),
                'kickoff'       => $match->kickoff_at,
                'score'         => $score[0] . '-' . $score[1],
                'outcome'       => $outcome,
                'th'            => $th,
                'all'           => $all,
                'brier_diff'    => $all['brier'] - $th['brier'],
                'log_loss_diff' => $all['log_loss'] - $th['log_loss'],
                'windows_differ' => $th['p'] !== $all['p'],
                'structural_gap' => $meta['structural']['log_ratio_gap'] ?? null,
                'shot_coverage_all' => $this->shotCoverage($meta['recent_windows']['recent_all'] ?? null),
                'shot_coverage_th'  => $this->shotCoverage($meta['recent_windows']['team_history'] ?? null),
            ];
        }

        return [
            'predictions_recorded' => $rows->flatten()->count(),
            'matches_with_rows'    => $rows->count(),
            'evaluable'            => count($details),
            'excluded'             => $excluded,
            'summary'              => $this->summary($details),
            'matches'              => $details,
            'descriptive'          => $this->descriptive($details),
        ];
    }

    /** @return array{th: Prediction, all: Prediction}|string  string = reason the pair is unusable */
    private function pair(Collection $group): array|string
    {
        $th  = $group->where('model_key', RecentExperimentCaptureService::MODEL_KEY_TEAM_HISTORY);
        $all = $group->where('model_key', RecentExperimentCaptureService::MODEL_KEY_RECENT_ALL);
        if ($th->count() !== 1 || $all->count() !== 1) {
            return 'expected exactly one row per variant (team_history=' . $th->count() . ', recent_all=' . $all->count() . ')';
        }
        $a = $th->first();
        $b = $all->first();
        foreach (['generated_at', 'kickoff_at', 'artifact_sha256', 'model_version', 'feature_set_version', 'structural_snapshot_version', 'latent_snapshot_generated_at'] as $k) {
            if ((string) $a->$k !== (string) $b->$k) {
                return "variants differ on {$k}";
            }
        }
        if (! $a->generated_at->lt($a->kickoff_at)) {
            return 'prediction not strictly before kickoff';
        }

        return ['th' => $a, 'all' => $b];
    }

    private function notEvaluableReason(?FootballMatch $m): string
    {
        return match (true) {
            $m === null                                   => 'match not found',
            $m->status !== 'finished'                     => 'status ' . $m->status,
            $m->definitive_at === null                    => 'finished but not certified (definitive_at missing)',
            default                                       => 'FT score missing',
        };
    }

    /** @return array{p: array{0:float,1:float,2:float}, brier: float, log_loss: float} */
    private function scored(Prediction $p, string $outcome): array
    {
        $row = (new Prediction())->forceFill([
            'probability_home' => $p->probability_home, 'probability_draw' => $p->probability_draw,
            'probability_away' => $p->probability_away, 'outcome' => $outcome,
        ]);
        $m = $this->metrics->computeMetrics(collect([$row]));

        return [
            'p'        => [(float) $p->probability_home, (float) $p->probability_draw, (float) $p->probability_away],
            'brier'    => $m['brier'],
            'log_loss' => $m['log_loss'],
        ];
    }

    /** Share of the (smaller) Recent window of the two teams that has shot statistics; null when unknown. */
    private function shotCoverage(?array $window): ?float
    {
        if ($window === null) {
            return null;
        }
        $shares = [];
        foreach (['home', 'away'] as $side) {
            $n = $window[$side]['matches_with_results'] ?? 0;
            $shares[] = $n > 0 ? ($window[$side]['matches_with_shot_stats'] ?? 0) / $n : null;
        }
        $shares = array_filter($shares, fn ($s) => $s !== null);

        return $shares === [] ? null : min($shares);
    }

    /** @param list<array<string,mixed>> $details */
    private function summary(array $details): ?array
    {
        if ($details === []) {
            return null;
        }
        $rowsFor = fn (string $v) => collect($details)->map(fn ($d) => (new Prediction())->forceFill([
            'probability_home' => $d[$v]['p'][0], 'probability_draw' => $d[$v]['p'][1], 'probability_away' => $d[$v]['p'][2], 'outcome' => $d['outcome'],
        ]));
        $th  = $this->metrics->computeMetrics($rowsFor('th'));
        $all = $this->metrics->computeMetrics($rowsFor('all'));

        return [
            'n_matches'        => count($details),
            'team_history'     => ['brier' => $th['brier'], 'log_loss' => $th['log_loss'], 'accuracy' => $th['accuracy']],
            'recent_all'       => ['brier' => $all['brier'], 'log_loss' => $all['log_loss'], 'accuracy' => $all['accuracy']],
            'brier_diff'       => $all['brier'] - $th['brier'],            // positive = RECENT_ALL worse
            'log_loss_diff'    => $all['log_loss'] - $th['log_loss'],
            'recent_all_worse_log_loss' => count(array_filter($details, fn ($d) => $d['log_loss_diff'] > 1e-12)),
            'recent_all_better_log_loss' => count(array_filter($details, fn ($d) => $d['log_loss_diff'] < -1e-12)),
            'identical_predictions'      => count(array_filter($details, fn ($d) => ! $d['windows_differ'])),
        ];
    }

    /**
     * Descriptive only (no thresholds, no significance): metrics restricted to the matches where the two variants really
     * differ, and Pearson correlations of the per-match log-loss difference with the stored Structural gap and with the
     * shot-statistics coverage of the RECENT_ALL window. Correlations are null below 3 usable matches.
     *
     * @param list<array<string,mixed>> $details
     */
    private function descriptive(array $details): array
    {
        $differing = array_values(array_filter($details, fn ($d) => $d['windows_differ']));
        $pearson = function (array $pairs): ?float {
            $pairs = array_values(array_filter($pairs, fn ($p) => $p[0] !== null && $p[1] !== null));
            $n = count($pairs);
            if ($n < 3) {
                return null;
            }
            $mx = array_sum(array_column($pairs, 0)) / $n;
            $my = array_sum(array_column($pairs, 1)) / $n;
            $sxy = $sxx = $syy = 0.0;
            foreach ($pairs as [$x, $y]) {
                $sxy += ($x - $mx) * ($y - $my);
                $sxx += ($x - $mx) ** 2;
                $syy += ($y - $my) ** 2;
            }

            return $sxx > 0 && $syy > 0 ? $sxy / sqrt($sxx * $syy) : null;
        };

        return [
            'matches_where_variants_differ' => count($differing),
            'metrics_where_variants_differ' => $differing === [] ? null : $this->summary($differing),
            'corr_log_loss_diff_vs_abs_structural_gap' => $pearson(array_map(fn ($d) => [$d['structural_gap'] === null ? null : abs($d['structural_gap']), $d['log_loss_diff']], $differing)),
            'corr_log_loss_diff_vs_all_shot_coverage'  => $pearson(array_map(fn ($d) => [$d['shot_coverage_all'], $d['log_loss_diff']], $differing)),
            'note' => 'Descriptive only: small samples, no significance claims.',
        ];
    }
}
