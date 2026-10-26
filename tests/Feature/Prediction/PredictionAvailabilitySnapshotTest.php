<?php

namespace Tests\Feature\Prediction;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\Player;
use App\Models\PlayerAbsence;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\AvailabilitySnapshotBuilder;
use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\MatchPredictionService;
use App\Services\Prediction\OfficialPredictionCaptureService;
use App\Services\Prediction\PredictionEngineV1;
use App\Services\Prediction\PublicPredictionRefreshService;
use Carbon\CarbonImmutable;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * predictions.availability_snapshot_json: Availability frozen WITH a new
 * prediction (capture / admin save / public refresh), never updated later,
 * never mixed into features_json, never affecting probabilities.
 */
class PredictionAvailabilitySnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const MODELS_DIR = __DIR__ . '/../../../tools/models';
    private const ARTIFACT_FILE = 'prediction_engine_candidate47_structural_log.json';
    private const CORE_COMPETITION_ID = 15;

    private string $tempDir;
    private DataSource $ds;
    private Team $home;
    private Team $away;
    private Competition $competition;
    private Season $season;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        $this->tempDir = sys_get_temp_dir() . '/avail_snapshot_test_' . uniqid();
        mkdir($this->tempDir);
        copy(realpath(self::MODELS_DIR) . '/' . self::ARTIFACT_FILE, $this->tempDir . '/' . self::ARTIFACT_FILE);
        CandidateModelService::setArtifactDir($this->tempDir);

        $country = Country::create(['name' => 'Italy', 'code' => 'IT']);
        $this->home = Team::create(['name' => 'Inter', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Udinese', 'country_id' => $country->id]);

        $this->competition = new Competition(['name' => 'Serie A', 'slug' => 'serie-a-test', 'country_id' => $country->id]);
        $this->competition->id = self::CORE_COMPETITION_ID;
        $this->competition->exists = false;
        $this->competition->save();
        $this->season = Season::create(['competition_id' => $this->competition->id, 'name' => '2025/26', 'year_start' => 2025, 'year_end' => 2026]);

        $this->mockPredictionService();
        $this->writeSnapshots();
    }

    protected function tearDown(): void
    {
        CandidateModelService::reset();
        PredictionEngineV1::reset();
        array_map('unlink', glob($this->tempDir . '/*'));
        rmdir($this->tempDir);
        parent::tearDown();
    }

    private function match(?\DateTimeInterface $fetchedAt): FootballMatch
    {
        $m = FootballMatch::create([
            'competition_id' => $this->competition->id, 'season_id' => $this->season->id,
            'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
            'kickoff_at' => now()->addDays(2), 'status' => 'scheduled',
        ]);
        $m->forceFill(['injuries_fetched_at' => $fetchedAt])->save();

        return $m;
    }

    private function absence(FootballMatch $m, Team $team, string $name, string $reason = 'Knee Injury'): Player
    {
        $p = Player::create(['name' => $name]);
        PlayerAbsence::create([
            'match_id' => $m->id, 'player_id' => $p->id, 'team_id' => $team->id, 'data_source_id' => $this->ds->id,
            'absence_type' => 'Missing Fixture', 'reason' => $reason,
        ]);

        return $p;
    }

    private function features(): array
    {
        $f = array_fill_keys(PredictionEngineV1::featureNames(), 1.234);
        $f['core_elo_home_pre_match_elo'] = 1600.0;
        $f['core_elo_away_pre_match_elo'] = 1500.0;

        return $f;
    }

    private function mockPredictionService(): void
    {
        $mock = $this->createMock(MatchPredictionService::class);
        $mock->method('predictWithDebug')->willReturn([
            'match_id' => null, 'model_version' => '1.0.0', 'feature_set_version' => 'core_v1',
            'lambda_home' => 1.4, 'lambda_away' => 1.1, 'probability_home' => 0.43, 'probability_draw' => 0.27, 'probability_away' => 0.30,
            'features' => $this->features(), 'features_candidate47' => $this->features(), 'match' => null,
        ]);
        $this->app->instance(MatchPredictionService::class, $mock);
    }

    private function writeSnapshots(): void
    {
        file_put_contents($this->tempDir . '/structural_strength_current.json', json_encode([
            'generated_at' => now()->subDay()->toIso8601String(), 'definition' => 'top25_market_value',
            'teams' => [
                (string) $this->home->id => ['transfermarkt_club_id' => 1, 'team_name' => 'Inter', 'players_count' => 25, 'top25_market_value' => 500_000_000],
                (string) $this->away->id => ['transfermarkt_club_id' => 2, 'team_name' => 'Udinese', 'players_count' => 25, 'top25_market_value' => 50_000_000],
            ],
        ]));
        file_put_contents($this->tempDir . '/latent_strength_current.json', json_encode([
            'generated_at' => now()->subDay()->toIso8601String(), 'last_match_included_at' => now()->subDay()->toIso8601String(),
            'match_count' => 100, 'source' => 'database',
            'teams' => [
                ['team_id' => $this->home->id, 'attack' => 0.25, 'defence' => -0.15],
                ['team_id' => $this->away->id, 'attack' => -0.10, 'defence' => 0.10],
            ],
        ]));
    }

    private function capture(FootballMatch $m): Prediction
    {
        $this->assertSame('CAPTURED', app(OfficialPredictionCaptureService::class)->capture($m->fresh(), false)['outcome']);

        return Prediction::where('match_id', $m->id)->latest('id')->firstOrFail();
    }

    public function test_covered_capture_stores_home_away_snapshot_without_touching_model_outputs(): void
    {
        $m = $this->match(now()->subHour());
        $hp = $this->absence($m, $this->home, 'Home Absent');
        $ap = $this->absence($m, $this->away, 'Away Absent', 'Suspended');

        $row = $this->capture($m);
        $snap = $row->availability_snapshot_json;

        $this->assertSame(AvailabilitySnapshotBuilder::SCHEMA_VERSION, $snap['schema_version']);
        $this->assertNotNull($snap['captured_at']);
        $this->assertSame($m->id, $snap['match_id']);
        $this->assertSame($this->home->id, $snap['home']['team_id']);
        $this->assertSame($this->away->id, $snap['away']['team_id']);
        $this->assertTrue($snap['home']['injuries_covered']);
        $this->assertNotNull($snap['home']['injuries_fetched_at']);
        $this->assertSame(1, $snap['home']['forced_absent_count']);
        $this->assertSame([$hp->id], array_column($snap['home']['absentees'], 'player_id'));
        $this->assertSame([$ap->id], array_column($snap['away']['absentees'], 'player_id'));
        $this->assertSame('Knee Injury', $snap['home']['absentees'][0]['reason']);
        $this->assertSame('Suspended', $snap['away']['absentees'][0]['reason']);
        foreach (['available_minutes_share', 'market_value_share_top25', 'player_absence_weight', 'u_null_cause', 'v_null_cause'] as $k) {
            $this->assertArrayHasKey($k, $snap['home']['absentees'][0]);
        }
        foreach (['weighted_absence', 'known_weighted_absence', 'weighted_player_count', 'unweighted_player_count', 'is_complete'] as $k) {
            $this->assertArrayHasKey($k, $snap['home']);
        }

        // Model outputs identical to the pure Candidate47 pipeline; features_json is exactly the artifact vector.
        $expected = (new CandidateModelService())->officialPredictionData($this->features(), $this->home->id, $this->away->id, (string) $m->kickoff_at);
        $this->assertEqualsWithDelta($expected["lambda_home"], $row->lambda_home, 1e-9);
        $this->assertEqualsWithDelta($expected["lambda_away"], $row->lambda_away, 1e-9);
        $this->assertEqualsWithDelta($expected["probability_home"], $row->probability_home, 1e-9);
        $this->assertEqualsWithDelta($expected["probability_draw"], $row->probability_draw, 1e-9);
        $this->assertEqualsWithDelta($expected["probability_away"], $row->probability_away, 1e-9);
        $this->assertSame($expected['model_version'], $row->model_version);
        $artifact = json_decode(file_get_contents($this->tempDir . '/' . self::ARTIFACT_FILE), true);
        $this->assertSame($artifact['features'], array_keys($row->features_json));
        $this->assertEquals($expected["features_json"], $row->features_json);
    }

    public function test_uncovered_capture_stores_explicit_not_covered_state(): void
    {
        $m = $this->match(null);

        $snap = $this->capture($m)->availability_snapshot_json;

        foreach (['home', 'away'] as $side) {
            $this->assertFalse($snap[$side]['injuries_covered']);
            $this->assertNull($snap[$side]['injuries_fetched_at']);
            $this->assertNull($snap[$side]['forced_absent_count']);
            $this->assertNull($snap[$side]['weighted_absence']);
            $this->assertNull($snap[$side]['is_complete']);
            $this->assertSame([], $snap[$side]['absentees']);
        }
    }

    public function test_later_injury_changes_never_alter_the_stored_snapshot(): void
    {
        $m = $this->match(now()->subHours(2));
        $row = $this->capture($m);
        $before = $row->fresh()->availability_snapshot_json;
        $this->assertSame(0, $before['home']['forced_absent_count']);

        // Injuries move on after the prediction was saved.
        $this->absence($m, $this->home, 'Late Injury');
        $m->forceFill(['injuries_fetched_at' => now()])->save();
        $this->assertSame(1, \App\Services\Analytics\TeamAvailabilityCalculator::calculateForMatch($m->fresh(), $this->home->id)['forced_absent_count']);

        $this->assertSame($before, $row->fresh()->availability_snapshot_json);
    }

    public function test_existing_predictions_stay_null_and_are_not_backfilled(): void
    {
        $m = $this->match(now()->subHour());
        $old = Prediction::create([
            'match_id' => $m->id, 'model_key' => 'candidate47_structural_log', 'model_version' => '1.0.0',
            'feature_set_version' => 'core_v1_candidate47_structural_log',
            'generated_at' => now()->subDay(), 'kickoff_at' => $m->kickoff_at,
            'lambda_home' => 1.4, 'lambda_away' => 1.1, 'lambda3' => 0.15,
            'probability_home' => 0.45, 'probability_draw' => 0.27, 'probability_away' => 0.28,
            'features_json' => ['a' => 1.0],
        ]);
        $this->assertNull($old->fresh()->availability_snapshot_json);

        $new = $this->capture($m);

        $this->assertNotSame($old->id, $new->id);
        $this->assertNotNull($new->availability_snapshot_json);
        $this->assertNull($old->fresh()->availability_snapshot_json);
    }

    public function test_admin_save_official_stores_snapshot(): void
    {
        $m = $this->match(now()->subHour());
        $this->absence($m, $this->home, 'Admin Home Absent');

        $this->post(route('admin.prediction-engine.save-official', ['match' => $m->id]))
            ->assertSessionHas('official_prediction_saved', true);

        $snap = Prediction::where('match_id', $m->id)->firstOrFail()->availability_snapshot_json;
        $this->assertSame($this->home->id, $snap['home']['team_id']);
        $this->assertSame(1, $snap['home']['forced_absent_count']);
        $this->assertSame(0, $snap['away']['forced_absent_count']);
    }

    public function test_public_refresh_stores_snapshot_on_the_public_latest_row(): void
    {
        $m = $this->match(now()->subHour());
        $this->absence($m, $this->away, 'Public Away Absent');

        $counts = app(PublicPredictionRefreshService::class)->refresh(CarbonImmutable::now(), 7);
        $this->assertSame(1, $counts['created']);

        $row = Prediction::where('match_id', $m->id)->where('purpose', Prediction::PURPOSE_PUBLIC_LATEST)->firstOrFail();
        $this->assertSame(1, $row->availability_snapshot_json['away']['forced_absent_count']);
        $this->assertSame(0, $row->availability_snapshot_json['home']['forced_absent_count']);
    }
}
