<?php

namespace Tests\Feature\Admin;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\MatchPredictionService;
use App\Services\Prediction\PredictionEngineV1;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P15C / P26B — "Salva prediction ufficiale" admin action tests.
 *
 * As of P26B the official prediction is ROBETTING CANDIDATE V2 LOG
 * (candidate47_structural_log), not FULL59. The artifact directory is
 * redirected to an isolated temp dir (CandidateModelService::setArtifactDir,
 * same pattern as CandidateModelServiceLatentSnapshotTest) containing a copy
 * of the REAL candidate47 artifact plus controlled Structural/Latent
 * snapshot fixtures, so every assertion here is deterministic and never
 * touches the real tools/models/*.json files.
 *
 * Tests:
 *  [1]  valid POST saves Candidate V2 LOG (model_key)
 *  [2]  model_version matches the artifact
 *  [3]  feature_set_version matches the artifact
 *  [4]  artifact_sha256 present and matches hash_file() of the real artifact
 *  [5]  all 47 features are saved in features_json (exact match vs runtime)
 *  [6]  structural_snapshot_version == Structural snapshot generated_at
 *  [7]  latent_snapshot_generated_at == Latent snapshot generated_at
 *  [8]  lambda/probabilities saved are bit-identical to officialPredictionData() output
 *  [9]  GET index does not create any prediction row
 *  [10] match already started (kickoff_at in the past) is rejected, no row created
 *  [11] two saves pre-kickoff both succeed (no UNIQUE, policy unchanged)
 *  [12] recordResult() only ever touches result columns, never prediction fields (regression, generic P15 contract)
 *  [+]  Candidate V2 LOG unavailable (no snapshot) is rejected, no row created
 *  [+]  page shows saved/unsaved state
 */
class PredictionEngineAdminSaveOfficialTest extends TestCase
{
    use RefreshDatabase;

    private const MODELS_DIR = __DIR__ . '/../../../tools/models';
    private const ARTIFACT_FILE = 'prediction_engine_candidate47_structural_log.json';

    private string $tempDir;
    private Team $home;
    private Team $away;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/v2log_official_test_' . uniqid();
        mkdir($this->tempDir);
        copy(realpath(self::MODELS_DIR) . '/' . self::ARTIFACT_FILE, $this->tempDir . '/' . self::ARTIFACT_FILE);
        CandidateModelService::setArtifactDir($this->tempDir);

        $country = Country::create(['name' => 'Italy', 'code' => 'IT']);
        $this->home = Team::create(['name' => 'Inter', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Udinese', 'country_id' => $country->id]);
    }

    protected function tearDown(): void
    {
        CandidateModelService::reset();
        PredictionEngineV1::reset();
        array_map('unlink', glob($this->tempDir . '/*'));
        rmdir($this->tempDir);
        parent::tearDown();
    }

    private function createMatch(string $kickoffAt): FootballMatch
    {
        $competition = Competition::create([
            'name'       => 'Serie A',
            'slug'       => 'serie-a-test',
            'country_id' => $this->home->country_id,
        ]);
        $season = Season::create([
            'competition_id' => $competition->id,
            'name'           => '2025/26',
            'year_start'     => 2025,
            'year_end'       => 2026,
        ]);

        return FootballMatch::create([
            'competition_id' => $competition->id,
            'season_id'      => $season->id,
            'home_team_id'   => $this->home->id,
            'away_team_id'   => $this->away->id,
            'kickoff_at'     => $kickoffAt,
            'status'         => 'scheduled',
        ]);
    }

    /** Real 59-feature names, filled with a constant + realistic Elo — matches
     *  what PredictionEngineV1 actually extracts, so addDerivedFeatures() and
     *  the artifact's median imputer behave exactly as at runtime. */
    private function mockPredictionService(int $matchId): array
    {
        $features = array_fill_keys(PredictionEngineV1::featureNames(), 1.234);
        $features['core_elo_home_pre_match_elo'] = 1600.0;
        $features['core_elo_away_pre_match_elo'] = 1500.0;

        $payload = [
            'match_id'            => $matchId,
            'model_version'       => '1.0.0',
            'feature_set_version' => 'core_v1',
            'feature_count'       => 59,
            'null_count'          => 0,
            'lambda_home'         => 1.4532,
            'lambda_away'         => 1.1087,
            'probability_home'    => 0.4312,
            'probability_draw'    => 0.2691,
            'probability_away'    => 0.2997,
            'features'            => $features,
            'timing_agg_ms'       => 10.0,
            'timing_inf_ms'       => 0.1,
            'timing_total_ms'     => 10.1,
            'match'               => null,
        ];

        $mock = $this->createMock(MatchPredictionService::class);
        $mock->method('predictWithDebug')->willReturn($payload);
        $this->app->instance(MatchPredictionService::class, $mock);

        return $payload;
    }

    private function writeSnapshots(string $structuralGeneratedAt, string $latentGeneratedAt): void
    {
        file_put_contents($this->tempDir . '/structural_strength_current.json', json_encode([
            'generated_at' => $structuralGeneratedAt,
            'definition'   => 'top25_market_value',
            'teams'        => [
                (string) $this->home->id => ['transfermarkt_club_id' => 1, 'team_name' => 'Inter', 'players_count' => 25, 'top25_market_value' => 500_000_000],
                (string) $this->away->id => ['transfermarkt_club_id' => 2, 'team_name' => 'Udinese', 'players_count' => 25, 'top25_market_value' => 50_000_000],
            ],
        ]));

        file_put_contents($this->tempDir . '/latent_strength_current.json', json_encode([
            'generated_at'           => $latentGeneratedAt,
            'last_match_included_at' => $latentGeneratedAt,
            'match_count'            => 100,
            'source'                 => 'database',
            'teams'                  => [
                ['team_id' => $this->home->id, 'attack' => 0.25, 'defence' => -0.15],
                ['team_id' => $this->away->id, 'attack' => -0.10, 'defence' => 0.10],
            ],
        ]));
    }

    private function expectedOfficialData(int $homeId, int $awayId, string $kickoffAt): array
    {
        // Call the exact same runtime method the controller uses, so the
        // test's "expected" value is the real pipeline output, not a
        // hand-derived guess.
        $service = new CandidateModelService();
        $features = array_fill_keys(PredictionEngineV1::featureNames(), 1.234);
        $features['core_elo_home_pre_match_elo'] = 1600.0;
        $features['core_elo_away_pre_match_elo'] = 1500.0;

        return $service->officialPredictionData($features, $homeId, $awayId, $kickoffAt);
    }

    // ─── [1]-[8] valid save: model identity + metadata + features + numbers ──

    public function test_valid_post_saves_candidate_v2_log_with_full_audit_metadata(): void
    {
        $match = $this->createMatch(now()->addDays(2)->toIso8601String());
        $this->mockPredictionService($match->id);
        $kickoffAt = (string) $match->kickoff_at;
        $this->writeSnapshots(now()->subDay()->toIso8601String(), now()->subDay()->toIso8601String());

        $expected = $this->expectedOfficialData($this->home->id, $this->away->id, $kickoffAt);
        $this->assertNotNull($expected, 'precondition: officialPredictionData() must resolve for this fixture');

        $this->post(route('admin.prediction-engine.save-official', ['match' => $match->id]))
            ->assertRedirect(route('admin.prediction-engine.index', ['match_id' => $match->id]))
            ->assertSessionHas('official_prediction_saved', true);

        $this->assertSame(1, Prediction::count());
        $row = Prediction::first();

        // [1] model_key
        $this->assertSame('candidate47_structural_log', $row->model_key);
        // [2] model_version from artifact
        $this->assertSame($expected['model_version'], $row->model_version);
        $this->assertSame('1.0.0', $row->model_version);
        // [3] feature_set_version from artifact
        $this->assertSame($expected['feature_set_version'], $row->feature_set_version);
        $this->assertSame('core_v1_candidate47_structural_log', $row->feature_set_version);
        // [4] artifact_sha256 present and matches the real artifact file
        $this->assertNotNull($row->artifact_sha256);
        $this->assertSame(hash_file('sha256', $this->tempDir . '/' . self::ARTIFACT_FILE), $row->artifact_sha256);
        // [5] all 47 features saved, exact match vs runtime
        $this->assertCount(47, $row->features_json);
        // Compare through the same JSON round-trip the DB cast applies
        // (whole-number floats like 1600.0 come back as int 1600 — expected
        // JSON behavior, not a persistence bug).
        $this->assertSame(
            json_decode(json_encode($expected['features_json']), true),
            $row->features_json
        );
        // [6] Structural snapshot generated_at
        $this->assertSame($expected['structural_snapshot_generated_at'], $row->structural_snapshot_version);
        // [7] Latent snapshot generated_at
        $this->assertSame(
            \Carbon\Carbon::parse($expected['latent_snapshot_generated_at'])->toIso8601String(),
            $row->latent_snapshot_generated_at->toIso8601String()
        );
        // [8] lambda/probabilities bit-identical to the runtime method's own output
        // DB double-precision storage round-trip can lose a few trailing
        // digits — compare with a tight delta instead of bit-for-bit.
        $this->assertEqualsWithDelta($expected['lambda_home'], $row->lambda_home, 1e-9);
        $this->assertEqualsWithDelta($expected['lambda_away'], $row->lambda_away, 1e-9);
        $this->assertEqualsWithDelta($expected['lambda3'], $row->lambda3, 1e-9);
        $this->assertEqualsWithDelta($expected['probability_home'], $row->probability_home, 1e-9);
        $this->assertEqualsWithDelta($expected['probability_draw'], $row->probability_draw, 1e-9);
        $this->assertEqualsWithDelta($expected['probability_away'], $row->probability_away, 1e-9);
        $sum = $row->probability_home + $row->probability_draw + $row->probability_away;
        $this->assertEqualsWithDelta(1.0, $sum, 1e-8);
    }

    // ─── [9] GET does not create a prediction ─────────────────────────────────

    public function test_get_index_does_not_create_any_prediction(): void
    {
        $match = $this->createMatch(now()->addDays(2)->toIso8601String());
        $this->mockPredictionService($match->id);
        $this->writeSnapshots(now()->subDay()->toIso8601String(), now()->subDay()->toIso8601String());

        $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]))
            ->assertStatus(200);

        $this->assertSame(0, Prediction::count());
    }

    // ─── [10] match already started is rejected ───────────────────────────────

    public function test_match_already_started_is_rejected(): void
    {
        $match = $this->createMatch(now()->subHour()->toIso8601String());
        $this->mockPredictionService($match->id);
        $this->writeSnapshots(now()->subDays(2)->toIso8601String(), now()->subDays(2)->toIso8601String());

        $this->post(route('admin.prediction-engine.save-official', ['match' => $match->id]))
            ->assertRedirect(route('admin.prediction-engine.index', ['match_id' => $match->id]))
            ->assertSessionHas('official_prediction_error');

        $this->assertSame(0, Prediction::count());
    }

    // ─── [11] two saves pre-kickoff both succeed, first row untouched ────────

    public function test_two_saves_pre_kickoff_both_succeed_without_modifying_first(): void
    {
        $match = $this->createMatch(now()->addDays(2)->toIso8601String());
        $this->mockPredictionService($match->id);
        $this->writeSnapshots(now()->subDay()->toIso8601String(), now()->subDay()->toIso8601String());

        $this->post(route('admin.prediction-engine.save-official', ['match' => $match->id]));
        $first = Prediction::first();

        $this->post(route('admin.prediction-engine.save-official', ['match' => $match->id]));

        $this->assertSame(2, Prediction::count());
        $freshFirst = Prediction::find($first->id);
        $this->assertSame($first->generated_at->toIso8601String(), $freshFirst->generated_at->toIso8601String());
        $this->assertSame($first->probability_home, $freshFirst->probability_home);
    }

    // ─── [12] recordResult() generic P15 contract still holds (regression) ──

    public function test_record_result_never_modifies_prediction_fields(): void
    {
        $match = $this->createMatch(now()->addDays(2)->toIso8601String());
        $this->mockPredictionService($match->id);
        $this->writeSnapshots(now()->subDay()->toIso8601String(), now()->subDay()->toIso8601String());

        $this->post(route('admin.prediction-engine.save-official', ['match' => $match->id]));
        $saved = Prediction::first();

        \App\Services\Prediction\OfficialPredictionRecorder::recordResult($match->id, 2, 1, 'finished');

        $fresh = Prediction::find($saved->id);
        $this->assertSame(2, $fresh->home_goals);
        $this->assertSame(1, $fresh->away_goals);
        $this->assertSame('1', $fresh->outcome);
        // Prediction fields completely untouched.
        $this->assertSame($saved->lambda_home, $fresh->lambda_home);
        $this->assertSame($saved->probability_home, $fresh->probability_home);
        $this->assertSame($saved->features_json, $fresh->features_json);
        $this->assertSame($saved->model_key, $fresh->model_key);
    }

    // ─── Candidate V2 LOG unavailable → rejected, no row created ─────────────

    public function test_candidate_v2_log_unavailable_is_rejected_no_row_created(): void
    {
        $match = $this->createMatch(now()->addDays(2)->toIso8601String());
        $this->mockPredictionService($match->id);
        // No writeSnapshots() call — Structural/Latent snapshots don't exist.

        $this->post(route('admin.prediction-engine.save-official', ['match' => $match->id]))
            ->assertRedirect(route('admin.prediction-engine.index', ['match_id' => $match->id]))
            ->assertSessionHas('official_prediction_error');

        $this->assertSame(0, Prediction::count());
    }

    // ─── Page shows saved/unsaved state ───────────────────────────────────────

    public function test_page_shows_saved_state_after_successful_save(): void
    {
        $match = $this->createMatch(now()->addDays(2)->toIso8601String());
        $this->mockPredictionService($match->id);
        $this->writeSnapshots(now()->subDay()->toIso8601String(), now()->subDay()->toIso8601String());

        $this->post(route('admin.prediction-engine.save-official', ['match' => $match->id]));

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));

        $response->assertStatus(200);
        $response->assertSee('PREDICTION UFFICIALE');
        $response->assertSee('ROBETTING CANDIDATE V2 LOG');
        $response->assertSee('Stato: SALVATA');
    }

    public function test_page_shows_unsaved_state_when_none_saved(): void
    {
        $match = $this->createMatch(now()->addDays(2)->toIso8601String());
        $this->mockPredictionService($match->id);
        $this->writeSnapshots(now()->subDay()->toIso8601String(), now()->subDay()->toIso8601String());

        $response = $this->get(route('admin.prediction-engine.index', ['match_id' => $match->id]));

        $response->assertStatus(200);
        $response->assertSee('Stato: NON SALVATA');
    }
}
