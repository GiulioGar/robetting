<?php

namespace Tests\Feature\Prediction;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\PreMatchFeatureAggregator;
use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\MatchPredictionService;
use App\Services\Prediction\OfficialPredictionCaptureService;
use App\Services\Prediction\PredictionEngineV1;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature routing per model family + Candidate47 Team History versioning:
 *  - Candidate47 Team History  -> Recent team_history + Elo extended, model_version 1.1.0
 *  - FULL59 / C40 / C44 / NO_E9*  -> legacy semantics they were trained with
 *  - official capture records model_key + the new model_version; old rows untouched
 */
class Candidate47TeamHistoryRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const LATENT     = 'tools/models/latent_strength_champions_anchored.json';
    private const STRUCTURAL = 'tools/models/structural_strength_champions_extended.json';

    protected function setUp(): void
    {
        parent::setUp();
        CandidateModelService::reset();
    }

    protected function tearDown(): void
    {
        CandidateModelService::reset();
        parent::tearDown();
    }

    private function recordingService(array &$calls): MatchPredictionService
    {
        return new class($calls) extends MatchPredictionService {
            public function __construct(private array &$calls) {}

            protected function buildFlatSnapshot(
                FootballMatch $match,
                string $recentWindowMode = PreMatchFeatureAggregator::RECENT_WINDOW_LEGACY,
                string $eloMode = PreMatchFeatureAggregator::ELO_MODE_LEGACY,
            ): array {
                $this->calls[] = [$recentWindowMode, $eloMode];
                $marker = $recentWindowMode === PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY ? 2.0 : 1.0;

                return array_fill_keys(PredictionEngineV1::featureNames(), $marker);
            }
        };
    }

    private function napoliBodoMatch(): FootballMatch
    {
        $country     = Country::create(['name' => 'Italy-R', 'code' => 'IR']);
        $competition = Competition::create(['name' => 'UEFA Champions League R', 'slug' => 'ucl-r-' . uniqid(), 'country_id' => $country->id]);
        $season      = Season::create(['competition_id' => $competition->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $home = new Team(['name' => 'Napoli', 'country_id' => $country->id]);
        $home->id = 278;
        $home->save();
        $away = new Team(['name' => 'Bodo/Glimt', 'country_id' => $country->id]);
        $away->id = 414;
        $away->save();

        return FootballMatch::create([
            'competition_id' => $competition->id, 'season_id' => $season->id,
            'home_team_id' => 278, 'away_team_id' => 414,
            'kickoff_at' => Carbon::now()->addDays(3), 'status' => 'scheduled',
        ]);
    }

    private function champions(): CandidateModelService
    {
        return new CandidateModelService(null, base_path(self::LATENT), base_path(self::STRUCTURAL));
    }

    public function test_feature_routing_legacy_for_default_and_team_history_for_candidate47(): void
    {
        $calls = [];
        $svc   = $this->recordingService($calls);
        $match = $this->napoliBodoMatch();

        $out = $svc->predictWithDebug($match);

        $this->assertContains(['legacy', 'legacy'], $calls);
        $this->assertContains(['team_history', 'extended'], $calls);
        $this->assertSame(1.0, $out['features'][PredictionEngineV1::featureNames()[0]]);              // legacy (FULL59/C40/C44)
        $this->assertSame(2.0, $out['features_candidate47'][PredictionEngineV1::featureNames()[0]]);  // Team History
    }

    public function test_explicit_team_history_request_does_not_aggregate_twice(): void
    {
        $calls = [];
        $svc   = $this->recordingService($calls);

        $out = $svc->predictWithDebug($this->napoliBodoMatch(), PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY, PreMatchFeatureAggregator::ELO_MODE_EXTENDED);

        $this->assertCount(1, $calls);
        $this->assertSame($out['features'], $out['features_candidate47']);
    }

    public function test_candidate47_artifact_is_versioned_1_1_0_and_distinct_from_legacy(): void
    {
        $new    = json_decode(file_get_contents(base_path('tools/models/prediction_engine_candidate47_team_history_recent.json')), true);
        $legacy = json_decode(file_get_contents(base_path('tools/models/prediction_engine_candidate47_structural_log.json')), true);

        $this->assertSame('1.1.0', $new['model_version']);
        $this->assertSame('1.0.0', $legacy['model_version']);
        $this->assertSame($legacy['features'], $new['features']);
    }

    public function test_official_prediction_data_uses_new_artifact_and_version(): void
    {
        $match = $this->napoliBodoMatch();
        $data  = $this->champions()->officialPredictionData(
            array_fill_keys(PredictionEngineV1::featureNames(), 1.0), 278, 414, (string) $match->kickoff_at
        );

        $this->assertNotNull($data);
        $this->assertSame('candidate47_structural_log', $data['model_key']);
        $this->assertSame('1.1.0', $data['model_version']);
        $this->assertStringEndsWith('prediction_engine_candidate47_team_history_recent.json', $data['artifact_path']);
    }

    public function test_compare_feeds_candidate47_the_team_history_vector_and_legacy_models_the_legacy_one(): void
    {
        $match  = $this->napoliBodoMatch();
        $legacy = array_fill_keys(PredictionEngineV1::featureNames(), 1.0);
        $th     = array_fill_keys(PredictionEngineV1::featureNames(), 2.0);
        $svc    = $this->champions();
        $args   = [278, 414, (string) $match->kickoff_at];

        $routed   = $svc->compare($legacy, ...$args, ...[$th]);
        $legacyOnly = $svc->compare($legacy, ...$args);
        $thOnly   = $svc->compare($th, ...$args);

        // Legacy-family models ignore the Team History vector...
        $this->assertSame($legacyOnly['full59'], $routed['full59']);
        $this->assertSame($legacyOnly['candidate40'], $routed['candidate40']);
        // ...Candidate47 consumes it (same result as feeding the TH vector directly).
        $this->assertSame($thOnly['candidate47_structural_log'], $routed['candidate47_structural_log']);
        $this->assertNotSame($legacyOnly['candidate47_structural_log'], $routed['candidate47_structural_log']);
    }

    public function test_official_capture_records_new_version_and_leaves_old_rows_intact(): void
    {
        $match = $this->napoliBodoMatch();

        $old = Prediction::create([
            'match_id' => $match->id, 'model_key' => 'candidate47_structural_log', 'model_version' => '1.0.0',
            'feature_set_version' => 'core_v1_candidate47_structural_log',
            'generated_at' => now()->subDays(1), 'kickoff_at' => $match->kickoff_at,
            'lambda_home' => 1.4, 'lambda_away' => 1.1, 'lambda3' => 0.15,
            'probability_home' => 0.45, 'probability_draw' => 0.27, 'probability_away' => 0.28,
            'features_json' => ['a' => 1.0],
        ]);
        $oldSnapshot = $old->fresh()->toArray();

        $calls = [];
        $capture = new OfficialPredictionCaptureService($this->recordingService($calls), $this->champions());

        $this->assertSame('CAPTURED', $capture->capture($match, false)['outcome']);

        $rows = Prediction::where('match_id', $match->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($oldSnapshot, $rows[0]->toArray());
        $this->assertSame('candidate47_structural_log', $rows[1]->model_key);
        $this->assertSame('1.1.0', $rows[1]->model_version);
        $this->assertSame(
            hash_file('sha256', base_path('tools/models/prediction_engine_candidate47_team_history_recent.json')),
            $rows[1]->artifact_sha256
        );
    }
}
