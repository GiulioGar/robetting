<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * P27B — first ACTIVE component of the Learning Loop: automatically capture
 * official ROBETTING CANDIDATE V2 LOG (candidate47_structural_log)
 * predictions for eligible future matches, within a configurable window.
 *
 * Purely additive: reuses CandidateModelService::officialPredictionData()
 * (P26B, exact same runtime pipeline as compare()) and
 * OfficialPredictionRecorder::record() (P15, immutable, anti-leakage
 * enforced) as-is. No feature calculation, no inference math, and no
 * anti-leakage logic are duplicated here — this service only decides WHICH
 * matches to capture and WHEN, never HOW to compute a prediction.
 *
 * Never falls back to FULL59: if Candidate V2 LOG is unavailable for a
 * match, that match is skipped (MODEL_UNAVAILABLE), never silently
 * substituted. The public Champion runtime is never touched by this service.
 */
class OfficialPredictionCaptureService
{
    public const MODEL_KEY = 'candidate47_structural_log';

    private const CORE_COMPETITION_IDS = [15, 16, 17, 18, 19];

    public function __construct(
        private readonly MatchPredictionService $predictionService,
        private readonly CandidateModelService $candidateService,
    ) {}

    /**
     * Matches in the capture window: core leagues, current season per
     * competition, status scheduled, kickoff strictly in the future and
     * within $withinHours from $now. Does NOT apply the safety margin —
     * that is a per-match classification (see evaluate()), not an exclusion
     * from the "considered" set.
     */
    public function findEligibleMatches(CarbonImmutable $now, int $withinHours): Collection
    {
        $windowEnd = $now->addHours($withinHours);

        $currentSeasonIds = Season::whereIn('competition_id', self::CORE_COMPETITION_IDS)
            ->selectRaw('competition_id, MAX(year_start) as max_year')
            ->groupBy('competition_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->competition_id => $row->max_year]);

        $seasonIds = Season::whereIn('competition_id', self::CORE_COMPETITION_IDS)
            ->get(['id', 'competition_id', 'year_start'])
            ->filter(fn ($s) => ($currentSeasonIds[$s->competition_id] ?? null) === $s->year_start)
            ->pluck('id');

        return FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->whereIn('competition_id', self::CORE_COMPETITION_IDS)
            ->whereIn('season_id', $seasonIds)
            ->where('status', 'scheduled')
            ->whereNotNull('kickoff_at')
            ->where('kickoff_at', '>', $now)
            ->where('kickoff_at', '<=', $windowEnd)
            ->orderBy('kickoff_at')
            ->get();
    }

    /**
     * Pre-classify a match before attempting any (potentially expensive)
     * capture: TOO_CLOSE (safety margin) or ALREADY_CAPTURED (idempotency —
     * a prediction already exists for this exact current kickoff_at).
     * Returns null if the match should proceed to capture()/preview.
     */
    public function preClassify(FootballMatch $match, CarbonImmutable $now, int $safetyMarginMinutes): ?string
    {
        if ($match->kickoff_at->lt($now->addMinutes($safetyMarginMinutes))) {
            return 'TOO_CLOSE';
        }

        $currentKickoff = $match->kickoff_at->copy()->utc()->format('Y-m-d H:i:s');

        $alreadyCaptured = Prediction::where('match_id', $match->id)
            ->where('model_key', self::MODEL_KEY)
            ->get(['kickoff_at'])
            ->contains(fn ($p) => $p->kickoff_at->copy()->utc()->format('Y-m-d H:i:s') === $currentKickoff);

        return $alreadyCaptured ? 'ALREADY_CAPTURED' : null;
    }

    /**
     * Resolve the Candidate V2 LOG payload for a match, WITHOUT persisting
     * anything. Reused identically by capture() (live) and by dry-run
     * preview, so both modes run the exact same code path up to the point
     * of writing.
     *
     * @return array{status: 'ok', data: array}|array{status: 'unavailable'|'error', reason: string}
     */
    private function resolveOfficialData(FootballMatch $match): array
    {
        try {
            $base = $this->predictionService->predictWithDebug($match);
        } catch (Throwable $e) {
            return ['status' => 'error', 'reason' => 'feature extraction failed: ' . $e->getMessage()];
        }

        try {
            $official = $this->candidateService->officialPredictionData(
                $base['features'],
                (int) $match->home_team_id,
                (int) $match->away_team_id,
                (string) $match->kickoff_at,
            );
        } catch (Throwable $e) {
            return ['status' => 'error', 'reason' => 'officialPredictionData() failed: ' . $e->getMessage()];
        }

        if ($official === null) {
            return ['status' => 'unavailable', 'reason' => 'Structural/Latent snapshot mancante o squadra non presente nello snapshot'];
        }

        return ['status' => 'ok', 'data' => $official];
    }

    /**
     * Attempt to capture (or, if $dryRun, only preview) an official
     * Candidate V2 LOG prediction for $match. Caller is responsible for
     * preClassify() first (TOO_CLOSE / ALREADY_CAPTURED short-circuit
     * before this is ever invoked, to avoid unnecessary inference work).
     *
     * A failure here (feature extraction, snapshot unavailable, record()
     * validation) NEVER throws out of this method and never touches
     * anything already persisted for other matches — see class docblock.
     *
     * @return array{outcome: 'CAPTURED'|'WOULD_CAPTURE'|'MODEL_UNAVAILABLE'|'ERROR', reason?: string}
     */
    public function capture(FootballMatch $match, bool $dryRun): array
    {
        $resolved = $this->resolveOfficialData($match);

        if ($resolved['status'] === 'error') {
            return ['outcome' => 'ERROR', 'reason' => $resolved['reason']];
        }
        if ($resolved['status'] === 'unavailable') {
            return ['outcome' => 'MODEL_UNAVAILABLE', 'reason' => $resolved['reason']];
        }

        if ($dryRun) {
            return ['outcome' => 'WOULD_CAPTURE'];
        }

        $official = $resolved['data'];

        try {
            OfficialPredictionRecorder::record([
                'match_id'             => $match->id,
                'model_key'            => $official['model_key'],
                'model_version'        => $official['model_version'],
                'feature_set_version'  => $official['feature_set_version'],
                'artifact_path'        => $official['artifact_path'],
                'generated_at'         => now(),
                'kickoff_at'           => $match->kickoff_at,
                'lambda_home'          => $official['lambda_home'],
                'lambda_away'          => $official['lambda_away'],
                'lambda3'              => $official['lambda3'],
                'probability_home'     => $official['probability_home'],
                'probability_draw'     => $official['probability_draw'],
                'probability_away'     => $official['probability_away'],
                'features_json'        => $official['features_json'],
                'structural_snapshot_version'  => $official['structural_snapshot_generated_at'],
                'latent_snapshot_generated_at' => $official['latent_snapshot_generated_at'],
            ]);
        } catch (Throwable $e) {
            return ['outcome' => 'ERROR', 'reason' => 'record() failed: ' . $e->getMessage()];
        }

        return ['outcome' => 'CAPTURED'];
    }
}
