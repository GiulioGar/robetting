<?php

namespace App\Services\Prediction;

use App\Models\Prediction;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Persists PUBLIC_LATEST predictions — the portal-facing counterpart of
 * OfficialPredictionRecorder (Learning Loop). Deliberately a SEPARATE class:
 * the two purposes have opposite persistence semantics (official is
 * append-only/immutable; public_latest is a single current row per
 * match_id+model_key, overwritten in place), so sharing one recorder would
 * make "what does record() do" ambiguous. Nothing here touches
 * OfficialPredictionRecorder or any purpose=official row.
 *
 * Single-current-row invariant WITHOUT a DB UNIQUE constraint (deliberate,
 * Step 1A.3 decision — a future constraint must never risk colliding with
 * official's own multi-row history on the same table): enforced purely at
 * the application level by always looking up the existing
 * (match_id, model_key, purpose=public_latest) row first and updating it in
 * place, never inserting a second one.
 *
 * Daily guard is PER ROW (one match's own `generated_at`), calendar day in
 * Europe/Rome (same display timezone already used by the portal's day
 * navigation) — never a global/batch-level flag, so an interrupted refresh
 * run can always be safely resumed: already-updated-today matches are
 * skipped, everything else is (re)computed.
 */
class PublicPredictionRecorder
{
    private const DISPLAY_TIMEZONE = 'Europe/Rome';

    private const REQUIRED_FIELDS = [
        'match_id',
        'model_key',
        'model_version',
        'feature_set_version',
        'kickoff_at',
        'lambda_home',
        'lambda_away',
        'probability_home',
        'probability_draw',
        'probability_away',
        'features_json',
    ];

    /**
     * True when a public_latest row already exists for this
     * (match_id, model_key) and was last generated today (Europe/Rome).
     * Checked by the caller BEFORE running the (expensive) prediction
     * pipeline, so an already-fresh match costs one query, never a full
     * recompute.
     */
    public static function alreadyUpdatedToday(int $matchId, string $modelKey, CarbonImmutable $now): bool
    {
        $generatedAt = Prediction::where('match_id', $matchId)
            ->where('model_key', $modelKey)
            ->where('purpose', Prediction::PURPOSE_PUBLIC_LATEST)
            ->value('generated_at');

        return $generatedAt !== null && self::isSameRomeDay(Carbon::parse($generatedAt), $now);
    }

    /**
     * @param array{
     *   match_id: int,
     *   model_key: string,
     *   model_version: string,
     *   feature_set_version: string,
     *   artifact_sha256?: string|null,
     *   artifact_path?: string|null,
     *   kickoff_at: \DateTimeInterface|string,
     *   lambda_home: float,
     *   lambda_away: float,
     *   lambda3?: float|null,
     *   probability_home: float,
     *   probability_draw: float,
     *   probability_away: float,
     *   features_json: array,
     *   structural_snapshot_version?: string|null,
     *   latent_snapshot_generated_at?: \DateTimeInterface|string|null,
     * } $data
     * @return array{outcome: 'CREATED'|'UPDATED'|'SKIPPED_TODAY', prediction: Prediction}
     *
     * @throws InvalidArgumentException if the snapshot is invalid or would leak the result
     */
    public static function upsert(array $data, CarbonImmutable $now): array
    {
        self::assertValid($data, $now);

        $existing = Prediction::where('match_id', $data['match_id'])
            ->where('model_key', $data['model_key'])
            ->where('purpose', Prediction::PURPOSE_PUBLIC_LATEST)
            ->first();

        if ($existing !== null && self::isSameRomeDay($existing->generated_at, $now)) {
            return ['outcome' => 'SKIPPED_TODAY', 'prediction' => $existing];
        }

        $artifactSha256 = $data['artifact_sha256'] ?? null;
        if ($artifactSha256 === null && ! empty($data['artifact_path'])) {
            $artifactSha256 = self::hashArtifact($data['artifact_path']);
        }

        $attributes = [
            'model_key'                    => $data['model_key'],
            'purpose'                      => Prediction::PURPOSE_PUBLIC_LATEST,
            'model_version'                => $data['model_version'],
            'feature_set_version'          => $data['feature_set_version'],
            'artifact_sha256'              => $artifactSha256,
            'generated_at'                 => $now,
            'kickoff_at'                   => $data['kickoff_at'],
            'lambda_home'                  => $data['lambda_home'],
            'lambda_away'                  => $data['lambda_away'],
            'lambda3'                      => $data['lambda3'] ?? null,
            'probability_home'             => $data['probability_home'],
            'probability_draw'             => $data['probability_draw'],
            'probability_away'             => $data['probability_away'],
            'features_json'                => $data['features_json'],
            // Frozen with THIS generation of the row (it is replaced together with the prediction).
            'availability_snapshot_json'   => $data['availability_snapshot_json'] ?? null,
            'structural_snapshot_version'  => $data['structural_snapshot_version'] ?? null,
            'latent_snapshot_generated_at' => $data['latent_snapshot_generated_at'] ?? null,
        ];

        if ($existing !== null) {
            $existing->update($attributes);
            return ['outcome' => 'UPDATED', 'prediction' => $existing->refresh()];
        }

        $created = Prediction::create(array_merge(['match_id' => $data['match_id']], $attributes));
        return ['outcome' => 'CREATED', 'prediction' => $created];
    }

    private static function isSameRomeDay(?\DateTimeInterface $generatedAt, CarbonImmutable $now): bool
    {
        if ($generatedAt === null) {
            return false;
        }

        return Carbon::instance($generatedAt)->copy()->setTimezone(self::DISPLAY_TIMEZONE)->isSameDay(
            $now->copy()->setTimezone(self::DISPLAY_TIMEZONE)
        );
    }

    private static function assertValid(array $data, CarbonImmutable $now): void
    {
        foreach (self::REQUIRED_FIELDS as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null) {
                throw new InvalidArgumentException("PublicPredictionRecorder: missing required field '{$key}'.");
            }
        }

        $kickoffAt = Carbon::parse($data['kickoff_at']);
        if (! $now->lt($kickoffAt)) {
            throw new InvalidArgumentException(
                'PublicPredictionRecorder: generated_at (now) must be strictly before kickoff_at '
                . "(now={$now->toIso8601String()}, kickoff_at={$kickoffAt->toIso8601String()})."
            );
        }

        $numericFields = [
            'lambda_home'      => (float) $data['lambda_home'],
            'lambda_away'      => (float) $data['lambda_away'],
            'probability_home' => (float) $data['probability_home'],
            'probability_draw' => (float) $data['probability_draw'],
            'probability_away' => (float) $data['probability_away'],
        ];
        if (isset($data['lambda3'])) {
            $numericFields['lambda3'] = (float) $data['lambda3'];
        }

        foreach ($numericFields as $key => $value) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException("PublicPredictionRecorder: '{$key}' is not finite.");
            }
        }

        foreach (['probability_home', 'probability_draw', 'probability_away'] as $key) {
            $value = $numericFields[$key];
            if ($value < 0.0 || $value > 1.0) {
                throw new InvalidArgumentException("PublicPredictionRecorder: '{$key}' ({$value}) out of [0,1] range.");
            }
        }

        $sum = $numericFields['probability_home'] + $numericFields['probability_draw'] + $numericFields['probability_away'];
        if (abs($sum - 1.0) > 1e-3) {
            throw new InvalidArgumentException("PublicPredictionRecorder: probabilities sum to {$sum}, expected ~1.0.");
        }

        if (! is_array($data['features_json']) || $data['features_json'] === []) {
            throw new InvalidArgumentException('PublicPredictionRecorder: features_json must be a non-empty array.');
        }
    }

    private static function hashArtifact(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);
        return $hash === false ? null : $hash;
    }
}
