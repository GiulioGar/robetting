<?php

namespace Tests\Unit\Services\Prediction;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\OfficialLearningDatasetExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P27E1 — OfficialLearningDatasetExporter core: eligibility (finished only,
 * awarded/walkover/unresolved excluded), the SAME dedup policy as P27D1,
 * model_key/version scoping, and FAIL-CLOSED feature-schema validation
 * against the artifact's canonical feature reference (no fallback). No DB
 * writes anywhere.
 */
class OfficialLearningDatasetExporterTest extends TestCase
{
    use RefreshDatabase;

    private OfficialLearningDatasetExporter $service;
    private Team $home;
    private Team $away;
    private Competition $competition;
    private Season $season;
    private string $artifactDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OfficialLearningDatasetExporter();

        // Isolated temp dir (never the REAL tools/models/ project artifacts).
        // Carries a default 3-feature artifact matching createPrediction()'s
        // default features_json, so existing eligibility/dedup/filter tests
        // don't need to care about the (now fail-closed, no-fallback)
        // feature-schema resolution. Tests that specifically exercise
        // schema resolution overwrite/delete this file themselves.
        $this->artifactDir = sys_get_temp_dir() . '/robetting_p27e1_test_' . uniqid();
        mkdir($this->artifactDir, 0755, true);
        OfficialLearningDatasetExporter::setArtifactDir($this->artifactDir);
        $this->writeArtifact(['feat_a', 'feat_b', 'feat_c']);

        $country = Country::create(['name' => 'Italy', 'code' => 'IT']);
        $this->home = Team::create(['name' => 'Inter', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Udinese', 'country_id' => $country->id]);
        $this->competition = Competition::create(['name' => 'Serie A', 'slug' => 'serie-a-test', 'country_id' => $country->id]);
        $this->season = Season::create([
            'competition_id' => $this->competition->id, 'name' => '2025/26', 'year_start' => 2025, 'year_end' => 2026,
        ]);
    }

    protected function tearDown(): void
    {
        OfficialLearningDatasetExporter::resetArtifactDir();
        @array_map('unlink', glob($this->artifactDir . '/*') ?: []);
        @rmdir($this->artifactDir);

        parent::tearDown();
    }

    /** @param array<int, string> $features */
    private function writeArtifact(array $features, ?string $filename = null): void
    {
        $filename ??= 'prediction_engine_candidate47_structural_log.json';
        file_put_contents($this->artifactDir . '/' . $filename, json_encode([
            'feature_set_version' => 'core_v1_candidate47_structural_log',
            'features' => $features,
        ]));
    }

    private function deleteArtifact(?string $filename = null): void
    {
        $filename ??= 'prediction_engine_candidate47_structural_log.json';
        @unlink($this->artifactDir . '/' . $filename);
    }

    private function createMatch(string $status, ?int $hg, ?int $ag, string $kickoffAt = '2026-01-01 18:00:00'): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->home->id,
            'away_team_id'   => $this->away->id,
            'kickoff_at'     => $kickoffAt,
            'status'         => $status,
            'home_score_ft'  => $hg,
            'away_score_ft'  => $ag,
        ]);
    }

    private function createPrediction(FootballMatch $match, array $overrides = []): Prediction
    {
        return Prediction::create(array_merge([
            'match_id'             => $match->id,
            'model_key'            => 'candidate47_structural_log',
            'model_version'        => '1.0.0',
            'feature_set_version'  => 'core_v1_candidate47_structural_log',
            'artifact_sha256'      => str_repeat('a', 64),
            'generated_at'         => now()->subDays(2),
            'kickoff_at'           => $match->kickoff_at,
            'lambda_home'          => 1.5,
            'lambda_away'          => 1.1,
            'lambda3'              => 0.15,
            'probability_home'     => 0.5,
            'probability_draw'     => 0.3,
            'probability_away'     => 0.2,
            'features_json'        => ['feat_b' => 2.0, 'feat_a' => 1.0, 'feat_c' => 3.0],
        ], $overrides));
    }

    private function resolve(Prediction $prediction, int $hg, int $ag, string $status = 'finished'): Prediction
    {
        $outcome = $hg > $ag ? '1' : ($hg === $ag ? 'X' : '2');
        $prediction->update([
            'home_goals'             => $hg,
            'away_goals'             => $ag,
            'outcome'                => $outcome,
            'match_status_at_result' => $status,
            'result_recorded_at'     => now(),
        ]);

        return $prediction->refresh();
    }

    // 1. finished valid -> exported
    public function test_finished_valid_prediction_is_exported(): void
    {
        $match = $this->createMatch('finished', 2, 0);
        $pred = $this->createPrediction($match);
        $this->resolve($pred, 2, 0);

        $result = $this->service->export('candidate47_structural_log', '1.0.0');

        $this->assertSame(1, $result['summary']['exported']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame($pred->id, $result['rows'][0]['prediction_id']);
    }

    // 2. unresolved -> excluded
    public function test_unresolved_excluded(): void
    {
        $match = $this->createMatch('scheduled', null, null);
        $this->createPrediction($match);

        $result = $this->service->export('candidate47_structural_log', '1.0.0');

        $this->assertSame(0, $result['summary']['exported']);
        $this->assertSame(0, $result['summary']['eligible']);
    }

    // 3. awarded -> excluded
    public function test_awarded_excluded(): void
    {
        $match = $this->createMatch('awarded', 3, 0);
        $pred = $this->createPrediction($match);
        $this->resolve($pred, 3, 0, 'awarded');

        $result = $this->service->export('candidate47_structural_log', '1.0.0');

        $this->assertSame(0, $result['summary']['exported']);
        $this->assertSame(1, $result['summary']['excluded_awarded_walkover']);
    }

    // 4. walkover -> excluded
    public function test_walkover_excluded(): void
    {
        $match = $this->createMatch('walkover', 3, 0);
        $pred = $this->createPrediction($match);
        $this->resolve($pred, 3, 0, 'walkover');

        $result = $this->service->export('candidate47_structural_log', '1.0.0');

        $this->assertSame(0, $result['summary']['exported']);
        $this->assertSame(1, $result['summary']['excluded_awarded_walkover']);
    }

    // 5. dedup latest generated_at
    public function test_dedup_keeps_latest_generated_at(): void
    {
        $match = $this->createMatch('finished', 2, 0);

        $older = $this->createPrediction($match, [
            'generated_at' => now()->subDays(5),
            'features_json' => ['feat_a' => 1.0, 'feat_b' => 2.0, 'feat_c' => 3.0],
        ]);
        $this->resolve($older, 2, 0);

        $newer = $this->createPrediction($match, [
            'generated_at' => now()->subDays(1),
            'features_json' => ['feat_a' => 9.0, 'feat_b' => 9.0, 'feat_c' => 9.0],
        ]);
        $this->resolve($newer, 2, 0);

        $result = $this->service->export('candidate47_structural_log', '1.0.0');

        $this->assertSame(1, $result['summary']['exported']);
        $this->assertSame(1, $result['summary']['excluded_older_duplicates']);
        $this->assertSame($newer->id, $result['rows'][0]['prediction_id']);
        $this->assertEquals(9.0, $result['rows'][0]['feat_a']);
    }

    // 6. tie generated_at -> highest id wins
    public function test_tie_generated_at_keeps_highest_id(): void
    {
        $match = $this->createMatch('finished', 2, 0);
        $sameTimestamp = now()->subDays(2);

        $first = $this->createPrediction($match, ['generated_at' => $sameTimestamp]);
        $this->resolve($first, 2, 0);

        $second = $this->createPrediction($match, ['generated_at' => $sameTimestamp]);
        $this->resolve($second, 2, 0);

        $this->assertGreaterThan($first->id, $second->id);

        $result = $this->service->export('candidate47_structural_log', '1.0.0');

        $this->assertSame(1, $result['summary']['exported']);
        $this->assertSame($second->id, $result['rows'][0]['prediction_id']);
    }

    // 7. 47 features expanded correctly into columns (using a real-shaped artifact fixture)
    public function test_features_expanded_into_columns_using_artifact_reference(): void
    {
        $artifactFeatures = array_map(fn ($i) => "feature_{$i}", range(1, 47));
        $this->writeArtifact($artifactFeatures);

        $featuresJson = array_combine($artifactFeatures, range(1, 47));

        $match = $this->createMatch('finished', 1, 0);
        $pred = $this->createPrediction($match, ['features_json' => $featuresJson]);
        $this->resolve($pred, 1, 0);

        $result = $this->service->export('candidate47_structural_log', '1.0.0');

        $this->assertSame(1, $result['summary']['exported']);
        foreach ($artifactFeatures as $i => $feature) {
            $this->assertSame($i + 1, $result['rows'][0][$feature]);
        }
    }

    // 8. feature column order is deterministic (matches artifact order, not storage order)
    public function test_feature_column_order_matches_artifact_not_json_storage_order(): void
    {
        $this->writeArtifact(['alpha', 'beta', 'gamma']);

        $match = $this->createMatch('finished', 1, 0);
        // Stored in a DIFFERENT order than the artifact declares.
        $pred = $this->createPrediction($match, ['features_json' => ['gamma' => 3.0, 'alpha' => 1.0, 'beta' => 2.0]]);
        $this->resolve($pred, 1, 0);

        $result = $this->service->export('candidate47_structural_log', '1.0.0');

        $featureColumns = array_values(array_intersect($result['columns'], ['alpha', 'beta', 'gamma']));
        $this->assertSame(['alpha', 'beta', 'gamma'], $featureColumns);
    }

    // 9. missing feature -> INVALID_FEATURE_SCHEMA, not exported
    public function test_missing_feature_is_invalid_feature_schema(): void
    {
        $this->writeArtifact(['alpha', 'beta', 'gamma']);

        $match = $this->createMatch('finished', 1, 0);
        $pred = $this->createPrediction($match, ['features_json' => ['alpha' => 1.0, 'beta' => 2.0]]); // gamma missing
        $this->resolve($pred, 1, 0);

        $result = $this->service->export('candidate47_structural_log', '1.0.0');

        $this->assertSame(0, $result['summary']['exported']);
        $this->assertSame(1, $result['summary']['invalid_feature_schema']);
    }

    // 10. extra feature -> INVALID_FEATURE_SCHEMA, not exported
    public function test_extra_feature_is_invalid_feature_schema(): void
    {
        $this->writeArtifact(['alpha', 'beta', 'gamma']);

        $match = $this->createMatch('finished', 1, 0);
        $pred = $this->createPrediction($match, [
            'features_json' => ['alpha' => 1.0, 'beta' => 2.0, 'gamma' => 3.0, 'unexpected_extra' => 9.0],
        ]);
        $this->resolve($pred, 1, 0);

        $result = $this->service->export('candidate47_structural_log', '1.0.0');

        $this->assertSame(0, $result['summary']['exported']);
        $this->assertSame(1, $result['summary']['invalid_feature_schema']);
    }

    // 11. model_key filter excludes other models' rows
    public function test_model_key_filter(): void
    {
        $match1 = $this->createMatch('finished', 1, 0, '2026-01-01 18:00:00');
        $pred1 = $this->createPrediction($match1, ['model_key' => 'candidate47_structural_log']);
        $this->resolve($pred1, 1, 0);

        $match2 = $this->createMatch('finished', 1, 0, '2026-01-02 18:00:00');
        $pred2 = $this->createPrediction($match2, ['model_key' => 'full59']);
        $this->resolve($pred2, 1, 0);

        $result = $this->service->export('candidate47_structural_log', '1.0.0');

        $this->assertSame(1, $result['summary']['total']);
        $this->assertSame(1, $result['summary']['exported']);
    }

    // 12. model_version filter excludes other versions' rows
    public function test_model_version_filter(): void
    {
        $match1 = $this->createMatch('finished', 1, 0, '2026-01-01 18:00:00');
        $pred1 = $this->createPrediction($match1, ['model_version' => '1.0.0']);
        $this->resolve($pred1, 1, 0);

        $match2 = $this->createMatch('finished', 1, 0, '2026-01-02 18:00:00');
        $pred2 = $this->createPrediction($match2, ['model_version' => '2.0.0']);
        $this->resolve($pred2, 1, 0);

        $result = $this->service->export('candidate47_structural_log', '1.0.0');

        $this->assertSame(1, $result['summary']['total']);
        $this->assertSame(1, $result['summary']['exported']);
    }

    // availableModelVersions() helper used by the command to decide whether --model-version is required
    public function test_available_model_versions_lists_distinct_versions(): void
    {
        $match1 = $this->createMatch('finished', 1, 0, '2026-01-01 18:00:00');
        $this->createPrediction($match1, ['model_version' => '1.0.0']);

        $match2 = $this->createMatch('finished', 1, 0, '2026-01-02 18:00:00');
        $this->createPrediction($match2, ['model_version' => '2.0.0']);

        $versions = $this->service->availableModelVersions('candidate47_structural_log');

        $this->assertSame(['1.0.0', '2.0.0'], $versions);
    }

    // ─────────────────────────────────────────────────────────────────────
    // P27E1-FIX — FAIL-CLOSED feature schema (no fallback)
    // ─────────────────────────────────────────────────────────────────────

    // artifact missing -> error, no export, no CSV built
    public function test_missing_artifact_throws_and_exports_nothing(): void
    {
        $this->deleteArtifact();

        $match = $this->createMatch('finished', 1, 0);
        $pred = $this->createPrediction($match);
        $this->resolve($pred, 1, 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/canonical feature schema artifact not found/');

        $this->service->export('candidate47_structural_log', '1.0.0');
    }

    // artifact present but not valid JSON -> error, no export
    public function test_invalid_json_artifact_throws(): void
    {
        file_put_contents($this->artifactDir . '/prediction_engine_candidate47_structural_log.json', '{not valid json');

        $match = $this->createMatch('finished', 1, 0);
        $pred = $this->createPrediction($match);
        $this->resolve($pred, 1, 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        $this->service->export('candidate47_structural_log', '1.0.0');
    }

    // artifact valid JSON but missing the 'features' field -> error, no export
    public function test_artifact_without_features_field_throws(): void
    {
        file_put_contents($this->artifactDir . '/prediction_engine_candidate47_structural_log.json', json_encode([
            'feature_set_version' => 'core_v1_candidate47_structural_log',
        ]));

        $match = $this->createMatch('finished', 1, 0);
        $pred = $this->createPrediction($match);
        $this->resolve($pred, 1, 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/no valid 'features' schema/");

        $this->service->export('candidate47_structural_log', '1.0.0');
    }

    // artifact 'features' is an empty array -> error, no export
    public function test_artifact_with_empty_features_array_throws(): void
    {
        $this->writeArtifact([]);

        $match = $this->createMatch('finished', 1, 0);
        $pred = $this->createPrediction($match);
        $this->resolve($pred, 1, 0);

        $this->expectException(\RuntimeException::class);

        $this->service->export('candidate47_structural_log', '1.0.0');
    }

    // Even with zero eligible rows, a missing artifact still fails closed
    // (schema must be verifiable independently of whether there is data).
    public function test_missing_artifact_throws_even_with_zero_predictions(): void
    {
        $this->deleteArtifact();

        $this->expectException(\RuntimeException::class);

        $this->service->export('candidate47_structural_log', '1.0.0');
    }

    // The REAL production Candidate V2 LOG artifact exposes exactly 47 features
    // (sanity/regression check against the actual repo file, not a fixture).
    public function test_real_candidate47_structural_log_artifact_has_exactly_47_features(): void
    {
        OfficialLearningDatasetExporter::resetArtifactDir(); // point back at the real tools/models/

        $path = base_path('tools/models/prediction_engine_candidate47_structural_log.json');
        $this->assertFileExists($path);

        $artifact = json_decode(file_get_contents($path), true);

        $this->assertIsArray($artifact['features']);
        $this->assertCount(47, $artifact['features']);

        // Restore the test isolation override for tearDown's cleanup.
        OfficialLearningDatasetExporter::setArtifactDir($this->artifactDir);
    }

    // Multiple model versions without --model-version is a command-level
    // concern (ExportLearningDatasetCommandTest); availableModelVersions()
    // itself just reports what exists, already covered above.
}
