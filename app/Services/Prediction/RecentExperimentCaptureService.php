<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Services\Analytics\PreMatchFeatureAggregator;
use App\Services\Analytics\UefaPhase;
use App\Services\Matches\PreferredMatchStatisticResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prospective parallel experiment on UPCOMING UEFA club fixtures: Candidate47 with the current Team History Recent window
 * vs Candidate47 with the experimental RECENT_ALL window. Both rows are produced by ONE CandidateModelService instance
 * (same artifact, same Latent/Structural snapshots as the public UEFA profile) at ONE timestamp; only the Recent window
 * differs, which is verified before anything is written (any other feature differing => nothing is recorded).
 *
 * Persistence: the existing append-only `predictions` table with its own purpose (recent_exp) — every official consumer
 * filters purpose = 'official', so the Learning Loop, evaluation, datasets, GOAL register and the public page never see
 * these rows. model_key tells the variant. Outcomes are not stored here: evaluate by joining the certified match result.
 *
 * Idempotent: a match that already has an experiment row is never captured again (first photograph kept). Nothing is
 * back-filled: only matches not yet started are processed.
 */
class RecentExperimentCaptureService
{
    public const MODEL_KEY_TEAM_HISTORY = 'candidate47_recent_th';
    public const MODEL_KEY_RECENT_ALL   = 'candidate47_recent_all';

    public function __construct(private readonly MatchPredictionService $predictionService) {}

    /** @return Collection<int, FootballMatch> UEFA club fixtures not yet started, kickoff within the next $withinHours. */
    public function findEligibleMatches(int $withinHours = 72, ?CarbonInterface $now = null): Collection
    {
        $now = ($now ?? now())->copy()->utc();

        return FootballMatch::with(['competition:id,name,slug', 'homeTeam:id,name', 'awayTeam:id,name'])
            ->whereHas('competition', fn ($q) => $q->whereIn('slug', UefaPhase::CLUB_SLUGS))
            ->where('status', 'scheduled')
            ->whereNotNull('kickoff_at')
            ->where('kickoff_at', '>', $now)
            ->where('kickoff_at', '<=', $now->copy()->addHours($withinHours))
            ->orderBy('kickoff_at')
            ->get();
    }

    /** @return array{outcome: 'CAPTURED'|'ALREADY_CAPTURED'|'MODEL_UNAVAILABLE'|'ERROR'|'TOO_LATE', reason?: string} */
    public function capture(FootballMatch $match, ?CarbonInterface $now = null): array
    {
        $generatedAt = ($now ?? now())->copy()->utc();

        if ($match->kickoff_at === null || ! $generatedAt->lt($match->kickoff_at)) {
            return ['outcome' => 'TOO_LATE', 'reason' => 'prediction time is not strictly before kickoff'];
        }

        $exists = Prediction::where('match_id', $match->id)
            ->where('purpose', Prediction::PURPOSE_RECENT_EXPERIMENT)
            ->whereIn('model_key', [self::MODEL_KEY_TEAM_HISTORY, self::MODEL_KEY_RECENT_ALL])
            ->exists();
        if ($exists) {
            return ['outcome' => 'ALREADY_CAPTURED'];
        }

        try {
            $candidate = new CandidateModelService(
                base_path('tools/models/' . PublicMatchPredictionService::EXPERIMENTAL_CAND47_ARTIFACT),
                base_path('tools/models/' . PublicMatchPredictionService::EXPERIMENTAL_LATENT_SNAPSHOT),
                base_path('tools/models/' . PublicMatchPredictionService::EXPERIMENTAL_STRUCTURAL_SNAPSHOT),
            );

            $variants = [];
            foreach ([
                self::MODEL_KEY_TEAM_HISTORY => PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY,
                self::MODEL_KEY_RECENT_ALL   => PreMatchFeatureAggregator::RECENT_WINDOW_ALL,
            ] as $modelKey => $mode) {
                $base = $this->predictionService->predictWithDebug($match, $mode, PreMatchFeatureAggregator::ELO_MODE_EXTENDED);
                $data = $candidate->officialPredictionData(
                    $base['features_candidate47'] ?? $base['features'],
                    (int) $match->home_team_id,
                    (int) $match->away_team_id,
                    (string) $match->kickoff_at,
                );
                if ($data === null) {
                    return ['outcome' => 'MODEL_UNAVAILABLE', 'reason' => 'Structural/Latent snapshot missing or team not in snapshot'];
                }
                $variants[$modelKey] = ['mode' => $mode, 'data' => $data];
            }

            $th  = $variants[self::MODEL_KEY_TEAM_HISTORY]['data'];
            $all = $variants[self::MODEL_KEY_RECENT_ALL]['data'];

            // Only the Recent window may differ: same model/artifact/snapshots and identical non-Recent features.
            foreach (['model_version', 'feature_set_version', 'artifact_path', 'structural_snapshot_generated_at', 'latent_snapshot_generated_at'] as $k) {
                if ($th[$k] !== $all[$k]) {
                    return ['outcome' => 'ERROR', 'reason' => "variants differ on {$k}"];
                }
            }
            $nonRecent = fn (array $f) => array_filter($f, fn ($key) => ! str_starts_with($key, 'core_recent_'), ARRAY_FILTER_USE_KEY);
            if ($nonRecent($th['features_json']) !== $nonRecent($all['features_json'])) {
                return ['outcome' => 'ERROR', 'reason' => 'variants differ on a non-Recent feature'];
            }

            // No information later than the prediction time: snapshots must already exist at $generatedAt.
            foreach (['structural_snapshot_generated_at', 'latent_snapshot_generated_at'] as $k) {
                if (\Carbon\Carbon::parse($th[$k])->gt($generatedAt)) {
                    return ['outcome' => 'ERROR', 'reason' => "{$k} is later than the prediction time"];
                }
            }

            $meta = $this->metadata($match, $th, $generatedAt);
            $sha  = is_file($th['artifact_path']) ? hash_file('sha256', $th['artifact_path']) : null;

            DB::transaction(function () use ($variants, $match, $generatedAt, $meta, $sha): void {
                foreach ($variants as $modelKey => $v) {
                    $d = $v['data'];
                    Prediction::create([
                        'match_id'                     => $match->id,
                        'model_key'                    => $modelKey,
                        'purpose'                      => Prediction::PURPOSE_RECENT_EXPERIMENT,
                        'model_version'                => $d['model_version'],
                        'feature_set_version'          => $d['feature_set_version'],
                        'artifact_sha256'              => $sha,
                        'generated_at'                 => $generatedAt->format('Y-m-d H:i:s'),
                        'kickoff_at'                   => $match->kickoff_at,
                        'lambda_home'                  => $d['lambda_home'],
                        'lambda_away'                  => $d['lambda_away'],
                        'lambda3'                      => $d['lambda3'],
                        'probability_home'             => $d['probability_home'],
                        'probability_draw'             => $d['probability_draw'],
                        'probability_away'             => $d['probability_away'],
                        'features_json'                => $d['features_json'] + ['_experiment' => $meta + ['variant' => $v['mode']]],
                        'structural_snapshot_version'  => $d['structural_snapshot_generated_at'],
                        'latent_snapshot_generated_at' => $d['latent_snapshot_generated_at'],
                    ]);
                }
            });
        } catch (Throwable $e) {
            return ['outcome' => 'ERROR', 'reason' => $e->getMessage()];
        }

        return ['outcome' => 'CAPTURED'];
    }

    /**
     * Audit block stored with BOTH rows: Structural strengths/gap (from the snapshot used, never later than the prediction),
     * and for each team and variant the Recent window size, period and technical-data coverage (matches with results vs
     * matches with shot statistics).
     *
     * @param  array<string,mixed>  $data  officialPredictionData() payload (identical for both variants on non-Recent inputs)
     * @return array<string,mixed>
     */
    private function metadata(FootballMatch $match, array $data, CarbonInterface $generatedAt): array
    {
        $f = $data['features_json'];
        $structural = [
            'top25_home'     => isset($f['structural_log_home']) ? round(exp($f['structural_log_home'])) : null,
            'top25_away'     => isset($f['structural_log_away']) ? round(exp($f['structural_log_away'])) : null,
            'log_home'       => $f['structural_log_home'] ?? null,
            'log_away'       => $f['structural_log_away'] ?? null,
            'log_ratio_gap'  => $f['structural_log_ratio'] ?? null,
            'snapshot_generated_at' => $data['structural_snapshot_generated_at'],
        ];

        $windows = [];
        foreach ([
            'team_history' => PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY,
            'recent_all'   => PreMatchFeatureAggregator::RECENT_WINDOW_ALL,
        ] as $label => $mode) {
            foreach (['home' => $match->home_team_id, 'away' => $match->away_team_id] as $side => $teamId) {
                $w = PreMatchFeatureAggregator::recentWindowFor($match, (int) $teamId, $mode);
                $stats = PreferredMatchStatisticResolver::forMatchIds($w->pluck('id'));
                $windows[$label][$side] = [
                    'matches_with_results' => $w->count(),
                    'matches_with_shot_stats' => $w->filter(fn ($m) => $stats->has($m->id))->count(),
                    'first' => $w->first()?->kickoff_at?->toDateString(),
                    'last'  => $w->last()?->kickoff_at?->toDateString(),
                    'competition_ids' => $w->pluck('competition_id')->countBy()->all(),
                ];
            }
        }

        return [
            'reference_time_utc' => $generatedAt->format('Y-m-d H:i:s'),
            'elo_mode'           => PreMatchFeatureAggregator::ELO_MODE_EXTENDED,
            'structural'         => $structural,
            'recent_windows'     => $windows,
        ];
    }
}
