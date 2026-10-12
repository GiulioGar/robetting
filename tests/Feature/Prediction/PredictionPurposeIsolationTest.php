<?php

namespace Tests\Feature\Prediction;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\OfficialLearningDatasetExporter;
use App\Services\Prediction\OfficialPredictionResultCaptureService;
use App\Services\Prediction\PredictionEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Struttura portale — Step 1A.3: `predictions.purpose` isolation.
 *
 * Verifies ONLY the schema/isolation boundary introduced in this step —
 * no public_latest writer exists yet (that is a future step). Every test
 * here creates public_latest rows DIRECTLY (bypassing any recorder) purely
 * to prove the Learning Loop services ignore them, and that default/legacy
 * (purpose-less at insert time) rows keep behaving exactly as OFFICIAL.
 */
class PredictionPurposeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL_KEY = 'candidate47_structural_log';

    private Team $home;
    private Team $away;
    private Competition $competition;
    private Season $season;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::create(['name' => 'Italy', 'code' => 'IT']);
        $this->home = Team::create(['name' => 'Inter', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Udinese', 'country_id' => $country->id]);
        $this->competition = Competition::create(['name' => 'Serie A', 'slug' => 'serie-a-test', 'country_id' => $country->id]);
        $this->season = Season::create([
            'competition_id' => $this->competition->id, 'name' => '2025/26', 'year_start' => 2025, 'year_end' => 2026,
        ]);
    }

    private function createMatch(string $status, ?int $hg = null, ?int $ag = null, string $kickoffAt = '2026-01-01 18:00:00'): FootballMatch
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

    /** @param array<string, mixed> $overrides */
    private function createPrediction(FootballMatch $match, array $overrides = []): Prediction
    {
        return Prediction::create(array_merge([
            'match_id'             => $match->id,
            'model_key'            => self::MODEL_KEY,
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
            'features_json'        => ['a' => 1.0],
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

    // 1. Righe esistenti/default -> purpose=official
    public function test_default_purpose_is_official_without_specifying_it(): void
    {
        $match = $this->createMatch('scheduled');
        $prediction = $this->createPrediction($match); // no 'purpose' override

        $this->assertSame(Prediction::PURPOSE_OFFICIAL, $prediction->fresh()->purpose);
        $this->assertSame('official', $prediction->fresh()->purpose);
    }

    // 2. PredictionEvaluationService ignora public_latest
    public function test_evaluation_service_ignores_public_latest_rows(): void
    {
        $officialMatch = $this->createMatch('finished', 1, 0);
        $official = $this->createPrediction($officialMatch, ['purpose' => Prediction::PURPOSE_OFFICIAL]);
        $this->resolve($official, 1, 0);

        $publicMatch = $this->createMatch('finished', 2, 2, '2026-01-02 18:00:00');
        $public = $this->createPrediction($publicMatch, [
            'purpose'          => Prediction::PURPOSE_PUBLIC_LATEST,
            'probability_home' => 0.1,
            'probability_draw' => 0.1,
            'probability_away' => 0.8,
        ]);
        $this->resolve($public, 2, 2);

        $report = (new PredictionEvaluationService())->evaluate(self::MODEL_KEY, '1.0.0')[0];

        $this->assertSame(1, $report['dataset']['total']);
        $this->assertSame(1, $report['metrics']['n']);
    }

    // 3. OfficialLearningDatasetExporter ignora public_latest
    public function test_learning_dataset_exporter_ignores_public_latest_rows(): void
    {
        $officialMatch = $this->createMatch('finished', 1, 0);
        $official = $this->createPrediction($officialMatch, ['purpose' => Prediction::PURPOSE_OFFICIAL]);
        $this->resolve($official, 1, 0);

        $publicMatch = $this->createMatch('finished', 0, 0, '2026-01-02 18:00:00');
        $public = $this->createPrediction($publicMatch, ['purpose' => Prediction::PURPOSE_PUBLIC_LATEST]);
        $this->resolve($public, 0, 0);

        $tempDir = sys_get_temp_dir() . '/purpose_isolation_test_' . uniqid();
        mkdir($tempDir);
        file_put_contents($tempDir . '/prediction_engine_' . self::MODEL_KEY . '.json', json_encode([
            'model_version'       => '1.0.0',
            'feature_set_version' => 'core_v1_candidate47_structural_log',
            'features'            => ['a'],
        ]));

        $exporter = new OfficialLearningDatasetExporter();
        OfficialLearningDatasetExporter::setArtifactDir($tempDir);

        try {
            $result = $exporter->export(self::MODEL_KEY, '1.0.0');
        } finally {
            OfficialLearningDatasetExporter::resetArtifactDir();
            @unlink($tempDir . '/prediction_engine_' . self::MODEL_KEY . '.json');
            @rmdir($tempDir);
        }

        $this->assertSame(1, $result['summary']['total']);
    }

    // 4. OfficialPredictionResultCaptureService ignora public_latest
    public function test_result_capture_service_ignores_public_latest_rows(): void
    {
        $match = $this->createMatch('finished', 3, 1);
        $public = $this->createPrediction($match, ['purpose' => Prediction::PURPOSE_PUBLIC_LATEST]);

        $service = new OfficialPredictionResultCaptureService();

        $this->assertSame([], $service->matchIdsWithPredictions());

        $outcome = $service->processMatch($match->id, dryRun: false);
        // No OFFICIAL row exists for this match at all -> nothing to resolve.
        $this->assertSame('ALREADY_RECORDED', $outcome['outcome']);
        $this->assertSame(0, $outcome['predictions_affected']);

        // The public_latest row must stay completely untouched.
        $this->assertNull($public->fresh()->home_goals);
    }

    // 4b. Un match con ENTRAMBE le righe (official + public_latest): la cattura
    // risultato deve risolvere solo quella official, mai quella public_latest.
    public function test_result_capture_resolves_official_only_when_both_purposes_exist_for_same_match(): void
    {
        $match = $this->createMatch('finished', 2, 0);
        $official = $this->createPrediction($match, ['purpose' => Prediction::PURPOSE_OFFICIAL]);
        $public = $this->createPrediction($match, [
            'purpose'       => Prediction::PURPOSE_PUBLIC_LATEST,
            'generated_at'  => now()->subHours(1),
        ]);

        $service = new OfficialPredictionResultCaptureService();
        $outcome = $service->processMatch($match->id, dryRun: false);

        $this->assertSame('RECORDED', $outcome['outcome']);
        $this->assertSame(1, $outcome['predictions_affected']);

        $this->assertSame(2, $official->fresh()->home_goals);
        $this->assertNull($public->fresh()->home_goals);
    }

    // 5. Comportamento OFFICIAL esistente invariato (stesso match_key/version scoping di prima)
    public function test_existing_official_only_behavior_is_unchanged(): void
    {
        $match = $this->createMatch('finished', 1, 1);
        $prediction = $this->createPrediction($match); // default purpose, like every pre-existing row
        $this->resolve($prediction, 1, 1);

        $report = (new PredictionEvaluationService())->evaluate(self::MODEL_KEY, '1.0.0')[0];

        $this->assertSame(1, $report['dataset']['total']);
        $this->assertSame(1, $report['dataset']['resolved']);
        $this->assertSame(1, $report['metrics']['n']);
        $this->assertIsFloat($report['metrics']['log_loss']);
    }
}
