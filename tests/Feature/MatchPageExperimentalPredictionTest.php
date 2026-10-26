<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\MatchPredictionService;
use App\Services\Prediction\PublicMatchPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Step C-DEBUG-EXPERIMENTAL — ?debug_predict=1 Champions visual-test profile.
 *
 *  A. no query string (experimental=false, default)  -> production behaviour unchanged
 *  B. experimental=true                               -> Team History Recent + Elo Extended +
 *                                                         the 3 experimental artifacts
 *  C. Napoli(278)/Bodo-Glimt(414)-shaped match reaches Candidate47 when experimental
 *     (both teams are present in the REAL latent/structural experimental snapshots)
 *  D. a missing experimental artifact -> clear diagnostic error, NEVER a silent
 *     fallback to the production artifacts
 *  E. no row is ever written to `predictions` (no persistence either way)
 */
class MatchPageExperimentalPredictionTest extends TestCase
{
    use RefreshDatabase;

    private const FULL59 = [
        'lambda_home'      => 1.6774,
        'lambda_away'      => 1.3710,
        'probability_home' => 0.4483,
        'probability_draw' => 0.2381,
        'probability_away' => 0.3136,
    ];

    private function createMatch(int $homeId, int $awayId, string $homeName, string $awayName): FootballMatch
    {
        $country     = Country::create(['name' => 'Italy-Exp', 'code' => 'IE']);
        $competition = Competition::create([
            'name'       => 'UEFA Champions League Exp',
            'slug'       => 'uefa-champions-league-exp-' . uniqid(),
            'country_id' => $country->id,
        ]);
        $season = Season::create([
            'competition_id' => $competition->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
        ]);
        // 'id' is intentionally NOT in Team::$fillable — force it explicitly
        // so these fixtures match the REAL team_ids (278/414) present in the
        // actual experimental snapshot files.
        $home = new Team(['name' => $homeName, 'country_id' => $country->id]);
        $home->id = $homeId;
        $home->save();
        $away = new Team(['name' => $awayName, 'country_id' => $country->id]);
        $away->id = $awayId;
        $away->save();

        return FootballMatch::create([
            'competition_id' => $competition->id,
            'season_id'      => $season->id,
            'home_team_id'   => $home->id,
            'away_team_id'   => $away->id,
            'kickoff_at'     => now()->addDays(3),
            'status'         => 'scheduled',
        ]);
    }

    /**
     * Step C-DEBUG-EXPERIMENTAL tests exercise a REAL (non-mocked)
     * CandidateModelService (predictExperimental() constructs it directly,
     * bypassing the container) — so, unlike MatchPagePublicPredictionTest's
     * generic "core_feature_N" placeholders, the mocked 59 features here
     * must use the REAL PredictionEngineV1 feature names or
     * compare()'s own unconditional `PredictionEngineV1::predict($features59)`
     * call throws before ever reaching the candidate47 branch.
     */
    private function mockBase(): void
    {
        $predMock = $this->createMock(MatchPredictionService::class);
        $realFeatureNames = \App\Services\Prediction\PredictionEngineV1::featureNames();
        $predMock->method('predictWithDebug')->willReturn(array_merge(self::FULL59, [
            'match_id'            => 1,
            'model_version'       => '1.0.0',
            'feature_set_version' => 'core_v1',
            'feature_count'       => count($realFeatureNames),
            'null_count'          => 0,
            'features'            => array_fill_keys($realFeatureNames, 1.0),
            'timing_agg_ms'       => 1.0,
            'timing_inf_ms'       => 0.1,
            'timing_total_ms'     => 1.1,
        ]));
        $this->app->instance(MatchPredictionService::class, $predMock);
    }

    // ── [A] default (no experimental) behaviour unchanged ───────────────────────

    public function test_A_default_no_experimental_flag_behaves_as_production(): void
    {
        $this->mockBase();
        $match = $this->createMatch(900001, 900002, 'Test Home', 'Test Away');

        $pp = app(PublicMatchPredictionService::class)->predict($match, false);

        $this->assertNull($pp['experimental_profile']);
        $this->assertSame(PublicMatchPredictionService::MODEL_FULL59, $pp['model_used']);
    }

    // ── [B] experimental=true uses Team History + Elo Extended + the 3 artifacts ──

    public function test_B_experimental_true_reports_team_history_and_elo_extended_profile(): void
    {
        $this->mockBase();
        // Team ids deliberately absent from the real experimental snapshots —
        // isolates the PROFILE reporting from candidate47 availability.
        $match = $this->createMatch(900003, 900004, 'Test Home 2', 'Test Away 2');

        $pp = app(PublicMatchPredictionService::class)->predict($match, true);

        $this->assertNotNull($pp['experimental_profile']);
        $this->assertSame('team_history', $pp['experimental_profile']['recent_window_mode']);
        $this->assertSame('extended', $pp['experimental_profile']['elo_mode']);
        $this->assertSame('prediction_engine_candidate47_team_history_recent.json', $pp['experimental_profile']['cand47_artifact']);
        $this->assertSame('latent_strength_champions_anchored.json', $pp['experimental_profile']['latent_snapshot']);
        $this->assertSame('structural_strength_champions_extended.json', $pp['experimental_profile']['structural_snapshot']);
    }

    // ── [C] Napoli/Bodo-Glimt-shaped match reaches Candidate47 when experimental ──

    public function test_C_napoli_bodo_shaped_match_can_reach_candidate47_experimental(): void
    {
        $this->mockBase();
        // Real team_ids confirmed present in BOTH experimental snapshots
        // (tools/models/latent_strength_champions_anchored.json and
        // tools/models/structural_strength_champions_extended.json), with
        // generated_at strictly before this match's future kickoff.
        $match = $this->createMatch(278, 414, 'Napoli', 'Bodo/Glimt');

        $pp = app(PublicMatchPredictionService::class)->predict($match, true);

        $this->assertSame(PublicMatchPredictionService::MODEL_CANDIDATE_V2, $pp['model_used']);
        $this->assertFalse($pp['fallback_used']);
    }

    // ── [D] missing experimental artifact -> clear error, never silent production fallback ──

    public function test_D_missing_experimental_artifact_reports_clear_error(): void
    {
        $this->mockBase();
        $match = $this->createMatch(278, 414, 'Napoli', 'Bodo/Glimt');

        $path = base_path('tools/models/latent_strength_champions_anchored.json');
        $backup = $path . '.test_backup';
        $this->assertTrue(File::exists($path), 'Precondition: experimental artifact must exist before this test.');

        File::move($path, $backup);
        try {
            $pp = app(PublicMatchPredictionService::class)->predict($match, true);

            $this->assertSame(PublicMatchPredictionService::MODEL_FULL59, $pp['model_used']);
            $this->assertTrue($pp['fallback_used']);
            $this->assertArrayHasKey('experimental_artifacts_missing', $pp['fallback_reason']);
            $this->assertContains('latent_strength_champions_anchored.json', $pp['fallback_reason']['experimental_artifacts_missing']);
        } finally {
            File::move($backup, $path);
        }

        $this->assertTrue(File::exists($path), 'Artifact must be restored after the test regardless of outcome.');
    }

    // ── [E] no persistence either way ────────────────────────────────────────────

    public function test_E_no_prediction_rows_written(): void
    {
        $this->mockBase();
        $match = $this->createMatch(278, 414, 'Napoli', 'Bodo/Glimt');

        app(PublicMatchPredictionService::class)->predict($match, true);
        app(PublicMatchPredictionService::class)->predict($match, false);

        $this->assertSame(0, Prediction::count());
    }
}
