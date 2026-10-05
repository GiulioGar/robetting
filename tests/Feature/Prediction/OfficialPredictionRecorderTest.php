<?php

namespace Tests\Feature\Prediction;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\OfficialPredictionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * P15B — OfficialPredictionRecorder tests.
 *
 * Tests:
 *  [A] valid prediction is saved with all fields
 *  [B] features_json round-trips exactly
 *  [C] artifact_sha256 is computed from artifact_path when not given directly
 *  [D] generated_at >= kickoff_at is rejected
 *  [E] several predictions for the same match (different model_key) are allowed
 *  [F] recordResult() only fills result columns, leaves prediction fields untouched
 *  [G] recordResult() never overwrites an already-resolved row
 */
class OfficialPredictionRecorderTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::create(['name' => 'TestCo', 'football_code' => 'TC']);
        $comp    = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Test League',
            'slug'       => 'test-league',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $season = Season::create([
            'competition_id' => $comp->id,
            'name'           => '9999/00',
            'year_start'     => 9999,
            'year_end'       => 10000,
            'is_current'     => false,
        ]);
        $home = Team::create(['name' => 'HomeFC', 'type' => 'club', 'is_active' => true]);
        $away = Team::create(['name' => 'AwayFC', 'type' => 'club', 'is_active' => true]);

        $this->match = FootballMatch::create([
            'competition_id' => $comp->id,
            'season_id'      => $season->id,
            'home_team_id'   => $home->id,
            'away_team_id'   => $away->id,
            'kickoff_at'     => '9999-10-01 20:45:00',
            'status'         => 'scheduled',
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'match_id'             => $this->match->id,
            'model_key'            => 'candidate40_robust_bp',
            'model_version'        => '1.0.0',
            'feature_set_version'  => 'core_v1_candidate40_robust_bp',
            'generated_at'         => '9999-09-30 12:00:00',
            'kickoff_at'           => '9999-10-01 20:45:00',
            'lambda_home'          => 1.42,
            'lambda_away'          => 1.05,
            'lambda3'              => 0.15,
            'probability_home'     => 0.45,
            'probability_draw'     => 0.27,
            'probability_away'     => 0.28,
            'features_json'        => ['core_elo_home_pre_match_elo' => 1600.0, 'core_elo_away_pre_match_elo' => 1550.0],
        ], $overrides);
    }

    // ─── [A] valid prediction saved ───────────────────────────────────────────

    public function test_valid_prediction_is_saved(): void
    {
        $prediction = OfficialPredictionRecorder::record($this->validPayload());

        $this->assertDatabaseHas('predictions', [
            'id'                  => $prediction->id,
            'match_id'            => $this->match->id,
            'model_key'           => 'candidate40_robust_bp',
            'model_version'       => '1.0.0',
            'feature_set_version' => 'core_v1_candidate40_robust_bp',
        ]);
        $this->assertSame(0.45, $prediction->probability_home);
        $this->assertNull($prediction->home_goals);
        $this->assertNull($prediction->outcome);
    }

    // ─── [B] features_json round-trips ────────────────────────────────────────

    public function test_features_json_round_trips_exactly(): void
    {
        $features   = ['a' => 1.23, 'b' => null, 'c' => -4.5];
        $prediction = OfficialPredictionRecorder::record($this->validPayload(['features_json' => $features]));

        $fresh = Prediction::find($prediction->id);
        $this->assertSame($features, $fresh->features_json);
    }

    // ─── [C] artifact_sha256 computed from artifact_path ──────────────────────

    public function test_artifact_sha256_computed_from_artifact_path(): void
    {
        $path = base_path('tools/models/prediction_engine_candidate40_robust_bp.json');
        $this->assertFileExists($path);

        $prediction = OfficialPredictionRecorder::record($this->validPayload(['artifact_path' => $path]));

        $this->assertSame(hash_file('sha256', $path), $prediction->artifact_sha256);
    }

    // ─── [D] generated_at >= kickoff_at rejected ──────────────────────────────

    public function test_generated_at_after_kickoff_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OfficialPredictionRecorder::record($this->validPayload([
            'generated_at' => '9999-10-01 21:00:00',
            'kickoff_at'   => '9999-10-01 20:45:00',
        ]));
    }

    public function test_generated_at_equal_to_kickoff_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OfficialPredictionRecorder::record($this->validPayload([
            'generated_at' => '9999-10-01 20:45:00',
            'kickoff_at'   => '9999-10-01 20:45:00',
        ]));
    }

    public function test_probabilities_not_summing_to_one_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OfficialPredictionRecorder::record($this->validPayload([
            'probability_home' => 0.5,
            'probability_draw' => 0.5,
            'probability_away' => 0.5,
        ]));
    }

    // ─── [E] several predictions per match allowed ────────────────────────────

    public function test_multiple_predictions_for_same_match_are_allowed(): void
    {
        OfficialPredictionRecorder::record($this->validPayload(['model_key' => 'full59']));
        OfficialPredictionRecorder::record($this->validPayload(['model_key' => 'candidate40_robust_bp']));

        $this->assertSame(2, Prediction::where('match_id', $this->match->id)->count());
    }

    // ─── [F] recordResult() only fills result columns ─────────────────────────

    public function test_record_result_fills_only_result_columns(): void
    {
        $prediction = OfficialPredictionRecorder::record($this->validPayload());

        $updated = OfficialPredictionRecorder::recordResult($this->match->id, 2, 1, 'finished');

        $this->assertSame(1, $updated);

        $fresh = Prediction::find($prediction->id);
        $this->assertSame(2, $fresh->home_goals);
        $this->assertSame(1, $fresh->away_goals);
        $this->assertSame('1', $fresh->outcome);
        $this->assertSame('finished', $fresh->match_status_at_result);
        $this->assertNotNull($fresh->result_recorded_at);

        // Prediction fields must remain untouched.
        $this->assertSame($prediction->lambda_home, $fresh->lambda_home);
        $this->assertSame($prediction->probability_home, $fresh->probability_home);
        $this->assertSame($prediction->generated_at->toIso8601String(), $fresh->generated_at->toIso8601String());
        $this->assertSame($prediction->kickoff_at->toIso8601String(), $fresh->kickoff_at->toIso8601String());
    }

    // ─── [G] recordResult() never overwrites an already-resolved row ─────────

    public function test_record_result_does_not_overwrite_already_resolved_row(): void
    {
        $prediction = OfficialPredictionRecorder::record($this->validPayload());
        OfficialPredictionRecorder::recordResult($this->match->id, 2, 1, 'finished');

        $updatedAgain = OfficialPredictionRecorder::recordResult($this->match->id, 0, 0, 'finished');
        $this->assertSame(0, $updatedAgain);

        $fresh = Prediction::find($prediction->id);
        $this->assertSame(2, $fresh->home_goals);
        $this->assertSame(1, $fresh->away_goals);
        $this->assertSame('1', $fresh->outcome);
    }
}
