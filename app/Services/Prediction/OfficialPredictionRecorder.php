<?php

namespace App\Services\Prediction;

use App\Models\Prediction;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Persists an immutable snapshot of an already-computed pre-match prediction.
 *
 * Pure persistence: no inference, no training, no scheduling. Never called
 * from PredictionEngineV1 / MatchPredictionService / CandidateModelService —
 * those stay stateless. The caller computes a prediction, then explicitly
 * asks this service to record it as official.
 */
class OfficialPredictionRecorder
{
    private const REQUIRED_FIELDS = [
        'match_id',
        'model_key',
        'model_version',
        'feature_set_version',
        'generated_at',
        'kickoff_at',
        'lambda_home',
        'lambda_away',
        'probability_home',
        'probability_draw',
        'probability_away',
        'features_json',
    ];

    /**
     * @param array{
     *   match_id: int,
     *   model_key: string,
     *   model_version: string,
     *   feature_set_version: string,
     *   artifact_sha256?: string|null,
     *   artifact_path?: string|null,
     *   generated_at: \DateTimeInterface|string,
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
     *
     * @throws InvalidArgumentException if the snapshot is invalid or would leak the result
     */
    public static function record(array $data): Prediction
    {
        self::assertValid($data);

        $artifactSha256 = $data['artifact_sha256'] ?? null;
        if ($artifactSha256 === null && ! empty($data['artifact_path'])) {
            $artifactSha256 = self::hashArtifact($data['artifact_path']);
        }

        return Prediction::create([
            'match_id'                     => $data['match_id'],
            'model_key'                    => $data['model_key'],
            'model_version'                => $data['model_version'],
            'feature_set_version'          => $data['feature_set_version'],
            'artifact_sha256'              => $artifactSha256,
            'generated_at'                 => $data['generated_at'],
            'kickoff_at'                   => $data['kickoff_at'],
            'lambda_home'                  => $data['lambda_home'],
            'lambda_away'                  => $data['lambda_away'],
            'lambda3'                      => $data['lambda3'] ?? null,
            'probability_home'             => $data['probability_home'],
            'probability_draw'             => $data['probability_draw'],
            'probability_away'             => $data['probability_away'],
            'features_json'                => $data['features_json'],
            'structural_snapshot_version'  => $data['structural_snapshot_version'] ?? null,
            'latent_snapshot_generated_at' => $data['latent_snapshot_generated_at'] ?? null,
        ]);
    }

    /**
     * Fill in the real outcome for every still-unresolved prediction row of a match.
     *
     * Only ever writes result columns (home_goals, away_goals, outcome,
     * match_status_at_result, result_recorded_at) and only on rows where
     * home_goals is still null — resolved rows and prediction/model fields
     * are never touched.
     *
     * @return int  Number of rows updated (one per model/generation still unresolved for this match)
     */
    public static function recordResult(int $matchId, int $homeGoals, int $awayGoals, string $matchStatus): int
    {
        $outcome = $homeGoals > $awayGoals ? '1' : ($homeGoals === $awayGoals ? 'X' : '2');

        return Prediction::query()
            ->where('match_id', $matchId)
            ->whereNull('home_goals')
            ->update([
                'home_goals'              => $homeGoals,
                'away_goals'              => $awayGoals,
                'outcome'                 => $outcome,
                'match_status_at_result'  => $matchStatus,
                'result_recorded_at'      => now(),
            ]);
    }

    private static function assertValid(array $data): void
    {
        foreach (self::REQUIRED_FIELDS as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null) {
                throw new InvalidArgumentException("OfficialPredictionRecorder: missing required field '{$key}'.");
            }
        }

        $generatedAt = Carbon::parse($data['generated_at']);
        $kickoffAt   = Carbon::parse($data['kickoff_at']);
        if (! $generatedAt->lt($kickoffAt)) {
            throw new InvalidArgumentException(
                'OfficialPredictionRecorder: generated_at must be strictly before kickoff_at '
                . "(generated_at={$generatedAt->toIso8601String()}, kickoff_at={$kickoffAt->toIso8601String()})."
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
                throw new InvalidArgumentException("OfficialPredictionRecorder: '{$key}' is not finite.");
            }
        }

        foreach (['probability_home', 'probability_draw', 'probability_away'] as $key) {
            $value = $numericFields[$key];
            if ($value < 0.0 || $value > 1.0) {
                throw new InvalidArgumentException("OfficialPredictionRecorder: '{$key}' ({$value}) out of [0,1] range.");
            }
        }

        $sum = $numericFields['probability_home'] + $numericFields['probability_draw'] + $numericFields['probability_away'];
        if (abs($sum - 1.0) > 1e-3) {
            throw new InvalidArgumentException("OfficialPredictionRecorder: probabilities sum to {$sum}, expected ~1.0.");
        }

        if (! is_array($data['features_json']) || $data['features_json'] === []) {
            throw new InvalidArgumentException('OfficialPredictionRecorder: features_json must be a non-empty array.');
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
