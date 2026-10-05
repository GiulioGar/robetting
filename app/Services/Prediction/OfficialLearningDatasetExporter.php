<?php

namespace App\Services\Prediction;

use App\Models\Prediction;
use Illuminate\Support\Collection;

/**
 * P27E1 — read-only builder of the official learning dataset: resolved
 * official predictions (frozen pre-match `features_json`) matched with their
 * real outcome, destined for future offline retraining. Touches the DB in
 * read mode only; CSV writing (with its own temp-file/rename failure safety)
 * is the command's job, not this service's.
 *
 * Eligibility/dedup policy mirrors PredictionEvaluationService (P27D1)
 * EXACTLY (same values: match_status_at_result='finished' required,
 * awarded/walkover excluded, latest generated_at per match_id+model_key+
 * model_version wins ties on highest id) — reimplemented here rather than
 * reused because those methods are private and P27D1-D3 (the Evaluation
 * Engine) must not be modified for this task. This is the SAME established
 * project policy (see also LatentFitMatchEligibility::TRAINING_STATUS and
 * ApiFootballFixtureSyncService::DEFINITIVE_STATUSES), not a second/competing
 * one.
 *
 * Feature schema: FAIL-CLOSED. The canonical feature order is read
 * EXCLUSIVELY from the Candidate V2 LOG artifact
 * (tools/models/prediction_engine_<model_key>.json, 'features' array) — the
 * same file CandidateModelService consumes, read independently here
 * (read-only, no shared mutable state) so this service never has to modify
 * CandidateModelService. There is deliberately NO fallback: if the artifact
 * is missing, unreadable, not valid JSON, or its 'features' field is
 * missing/empty/not an array, export() throws immediately and nothing is
 * exported — a learning dataset must never derive its own schema from
 * whatever a prediction row happens to contain, because that could silently
 * accept a corrupt or drifted schema. Every row's feature KEY SET is then
 * compared against that one verified reference; any mismatch (missing or
 * extra key) is classified INVALID_FEATURE_SCHEMA and excluded from export,
 * never silently coerced.
 */
class OfficialLearningDatasetExporter
{
    private const EXCLUDED_NON_FINISHED_STATUSES = ['awarded', 'walkover'];

    private const FINISHED_STATUS = 'finished';

    private const VALID_OUTCOMES = ['1', 'X', '2'];

    private static ?string $artifactDir = null;

    /** Override artifact directory (for unit tests, mirrors CandidateModelService::setArtifactDir). */
    public static function setArtifactDir(string $dir): void
    {
        self::$artifactDir = rtrim($dir, '/\\');
    }

    /** Reset override (call in tearDown). */
    public static function resetArtifactDir(): void
    {
        self::$artifactDir = null;
    }

    private function artifactDir(): string
    {
        return self::$artifactDir ?? base_path('tools/models');
    }

    /**
     * Distinct model_version values currently present in `predictions` for
     * this model_key (no eligibility filter — purely "what versions exist",
     * used to decide whether --model-version must be requested).
     *
     * @return array<int, string>
     */
    public function availableModelVersions(string $modelKey): array
    {
        return Prediction::query()
            ->where('model_key', $modelKey)
            ->distinct()
            ->orderBy('model_version')
            ->pluck('model_version')
            ->all();
    }

    /**
     * @return array{
     *   columns: array<int, string>,
     *   rows: array<int, array<string, mixed>>,
     *   summary: array{total: int, resolved: int, eligible: int, exported: int, excluded_awarded_walkover: int, excluded_older_duplicates: int, invalid_feature_schema: int, invalid_incomplete: int},
     * }
     * @throws \RuntimeException if the canonical feature schema artifact for
     *   $modelKey cannot be resolved (missing/invalid) — fail-closed, no
     *   fallback. Checked FIRST, before any DB query.
     */
    public function export(string $modelKey, string $modelVersion): array
    {
        $referenceFeatures = $this->resolveCanonicalFeatureSchema($modelKey);

        $rows = Prediction::query()
            ->where('model_key', $modelKey)
            ->where('model_version', $modelVersion)
            ->with('match')
            ->get();

        $total = $rows->count();

        $resolved = $rows->filter(fn (Prediction $r) => $r->home_goals !== null);

        $excludedAwardedWalkover = $resolved->filter(
            fn (Prediction $r) => in_array($r->match_status_at_result, self::EXCLUDED_NON_FINISHED_STATUSES, true)
        );

        $finished = $resolved->filter(
            fn (Prediction $r) => $r->match_status_at_result === self::FINISHED_STATUS
        );

        $validFinished = $finished->filter(fn (Prediction $r) => $this->isStructurallyValid($r));
        $invalidIncomplete = $finished->count() - $validFinished->count();

        [$deduped, $olderDuplicatesExcluded] = $this->dedupeLatestPerMatch($validFinished);

        $exportedRows = [];
        $invalidFeatureSchema = 0;

        foreach ($deduped as $row) {
            if (! $this->matchesFeatureSchema($row, $referenceFeatures)) {
                $invalidFeatureSchema++;
                continue;
            }

            $exportedRows[] = $this->buildRow($row, $referenceFeatures);
        }

        $columns = $this->buildColumns($referenceFeatures);

        return [
            'columns' => $columns,
            'rows' => $exportedRows,
            'summary' => [
                'total' => $total,
                'resolved' => $resolved->count(),
                'eligible' => $validFinished->count(),
                'exported' => count($exportedRows),
                'excluded_awarded_walkover' => $excludedAwardedWalkover->count(),
                'excluded_older_duplicates' => $olderDuplicatesExcluded,
                'invalid_feature_schema' => $invalidFeatureSchema,
                'invalid_incomplete' => $invalidIncomplete,
            ],
        ];
    }

    /**
     * Resolved + finished + valid outcome + non-empty array features_json.
     * Does NOT check feature schema (missing/extra keys) — that is a
     * separate, later classification (INVALID_FEATURE_SCHEMA), not folded
     * into "invalid/incomplete".
     */
    private function isStructurallyValid(Prediction $row): bool
    {
        if (! in_array($row->outcome, self::VALID_OUTCOMES, true)) {
            return false;
        }

        return is_array($row->features_json) && $row->features_json !== [];
    }

    /**
     * Identical algorithm to PredictionEvaluationService::dedupeLatestPerMatch()
     * (P27D1) — see class docblock for why it is reimplemented rather than reused.
     *
     * @param Collection<int, Prediction> $validFinished
     * @return array{0: Collection<int, Prediction>, 1: int}
     */
    private function dedupeLatestPerMatch(Collection $validFinished): array
    {
        $deduped = collect();
        $olderDuplicatesExcluded = 0;

        foreach ($validFinished->groupBy('match_id') as $group) {
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

            $deduped->push($latest);
            $olderDuplicatesExcluded += $group->count() - 1;
        }

        return [$deduped, $olderDuplicatesExcluded];
    }

    /**
     * FAIL-CLOSED: the ONLY source of the canonical feature schema is the
     * Candidate artifact (tools/models/prediction_engine_<model_key>.json,
     * same path convention as CandidateModelService::CAND47_STRUCTURAL_LOG_FILE).
     * No fallback to any prediction row's own features_json — a learning
     * dataset must never trust an unverified schema.
     *
     * @return array<int, string>
     * @throws \RuntimeException
     */
    private function resolveCanonicalFeatureSchema(string $modelKey): array
    {
        $path = $this->artifactDir() . DIRECTORY_SEPARATOR . 'prediction_engine_' . $modelKey . '.json';

        if (! is_file($path)) {
            throw new \RuntimeException(
                "Cannot export learning dataset: canonical feature schema artifact not found for model_key={$modelKey} ({$path})."
            );
        }

        $json = file_get_contents($path);
        $data = $json === false ? null : json_decode($json, true);

        if (! is_array($data)) {
            throw new \RuntimeException(
                "Cannot export learning dataset: artifact is not valid JSON for model_key={$modelKey} ({$path})."
            );
        }

        if (! isset($data['features']) || ! is_array($data['features']) || $data['features'] === []) {
            throw new \RuntimeException(
                "Cannot export learning dataset: artifact has no valid 'features' schema for model_key={$modelKey} ({$path})."
            );
        }

        return array_values($data['features']);
    }

    /**
     * Exact key-SET match required (order-independent) — missing or extra
     * keys both fail. Never auto-corrected.
     *
     * @param array<int, string> $referenceFeatures
     */
    private function matchesFeatureSchema(Prediction $row, array $referenceFeatures): bool
    {
        if ($referenceFeatures === []) {
            return false;
        }

        $rowKeys = array_keys($row->features_json);
        sort($rowKeys);

        $reference = $referenceFeatures;
        sort($reference);

        return $rowKeys === $reference;
    }

    /**
     * @param array<int, string> $referenceFeatures
     * @return array<int, string>
     */
    private function buildColumns(array $referenceFeatures): array
    {
        return array_merge(
            ['prediction_id', 'match_id', 'model_key', 'model_version', 'feature_set_version', 'artifact_sha256', 'generated_at', 'kickoff_at', 'competition_id'],
            $referenceFeatures,
            ['lambda_home', 'lambda_away', 'lambda3', 'probability_home', 'probability_draw', 'probability_away'],
            ['home_goals', 'away_goals', 'outcome'],
        );
    }

    /**
     * @param array<int, string> $referenceFeatures
     * @return array<string, mixed>
     */
    private function buildRow(Prediction $row, array $referenceFeatures): array
    {
        $out = [
            'prediction_id' => $row->id,
            'match_id' => $row->match_id,
            'model_key' => $row->model_key,
            'model_version' => $row->model_version,
            'feature_set_version' => $row->feature_set_version,
            'artifact_sha256' => $row->artifact_sha256,
            'generated_at' => $row->generated_at->toIso8601String(),
            'kickoff_at' => $row->kickoff_at->toIso8601String(),
            'competition_id' => $row->match?->competition_id,
        ];

        foreach ($referenceFeatures as $feature) {
            $out[$feature] = $row->features_json[$feature];
        }

        $out['lambda_home'] = $row->lambda_home;
        $out['lambda_away'] = $row->lambda_away;
        $out['lambda3'] = $row->lambda3;
        $out['probability_home'] = $row->probability_home;
        $out['probability_draw'] = $row->probability_draw;
        $out['probability_away'] = $row->probability_away;

        $out['home_goals'] = $row->home_goals;
        $out['away_goals'] = $row->away_goals;
        $out['outcome'] = $row->outcome;

        return $out;
    }
}
