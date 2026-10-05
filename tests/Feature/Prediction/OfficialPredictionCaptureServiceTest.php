<?php

namespace Tests\Feature\Prediction;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\MatchPredictionService;
use App\Services\Prediction\OfficialPredictionCaptureService;
use App\Services\Prediction\PredictionEngineV1;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P27B — OfficialPredictionCaptureService + robetting:capture-official-predictions.
 *
 * Isolated artifact dir with a real copy of the candidate47 artifact +
 * controlled Structural/Latent snapshot fixtures (same pattern as
 * PredictionEngineAdminSaveOfficialTest), so every assertion is
 * deterministic and never touches the real tools/models/*.json files.
 */
class OfficialPredictionCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    private const MODELS_DIR = __DIR__ . '/../../../tools/models';
    private const ARTIFACT_FILE = 'prediction_engine_candidate47_structural_log.json';
    private const CORE_COMPETITION_ID = 15; // Serie A, a real core id per CORE_COMPETITION_IDS

    private string $tempDir;
    private Team $home;
    private Team $away;
    private Competition $competition;
    private Season $season;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/capture_test_' . uniqid();
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

        $this->season = Season::create([
            'competition_id' => $this->competition->id,
            'name'           => '2025/26',
            'year_start'     => 2025,
            'year_end'       => 2026,
        ]);
    }

    protected function tearDown(): void
    {
        CandidateModelService::reset();
        PredictionEngineV1::reset();
        array_map('unlink', glob($this->tempDir . '/*'));
        rmdir($this->tempDir);
        parent::tearDown();
    }

    private function createMatch(string $kickoffAt, ?Team $home = null, ?Team $away = null): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => ($home ?? $this->home)->id,
            'away_team_id'   => ($away ?? $this->away)->id,
            'kickoff_at'     => $kickoffAt,
            'status'         => 'scheduled',
        ]);
    }

    private function mockPredictionService(?\Closure $perMatch = null): void
    {
        $features = array_fill_keys(PredictionEngineV1::featureNames(), 1.234);
        $features['core_elo_home_pre_match_elo'] = 1600.0;
        $features['core_elo_away_pre_match_elo'] = 1500.0;

        $mock = $this->createMock(MatchPredictionService::class);
        if ($perMatch === null) {
            $mock->method('predictWithDebug')->willReturn([
                'match_id' => null, 'model_version' => '1.0.0', 'feature_set_version' => 'core_v1',
                'features' => $features, 'match' => null,
            ]);
        } else {
            $mock->method('predictWithDebug')->willReturnCallback(function (FootballMatch $match) use ($perMatch, $features) {
                return $perMatch($match, $features);
            });
        }
        $this->app->instance(MatchPredictionService::class, $mock);
    }

    private function writeSnapshots(array $extraTeams = []): void
    {
        $structuralTeams = [
            (string) $this->home->id => ['transfermarkt_club_id' => 1, 'team_name' => 'Inter', 'players_count' => 25, 'top25_market_value' => 500_000_000],
            (string) $this->away->id => ['transfermarkt_club_id' => 2, 'team_name' => 'Udinese', 'players_count' => 25, 'top25_market_value' => 50_000_000],
        ];
        $latentTeams = [
            ['team_id' => $this->home->id, 'attack' => 0.25, 'defence' => -0.15],
            ['team_id' => $this->away->id, 'attack' => -0.10, 'defence' => 0.10],
        ];
        foreach ($extraTeams as $t) {
            $structuralTeams[(string) $t->id] = ['transfermarkt_club_id' => $t->id, 'team_name' => $t->name, 'players_count' => 25, 'top25_market_value' => 100_000_000];
            $latentTeams[] = ['team_id' => $t->id, 'attack' => 0.0, 'defence' => 0.0];
        }

        file_put_contents($this->tempDir . '/structural_strength_current.json', json_encode([
            'generated_at' => now()->subDay()->toIso8601String(),
            'definition'   => 'top25_market_value',
            'teams'        => $structuralTeams,
        ]));
        file_put_contents($this->tempDir . '/latent_strength_current.json', json_encode([
            'generated_at'           => now()->subDay()->toIso8601String(),
            'last_match_included_at' => now()->subDay()->toIso8601String(),
            'match_count'            => 100,
            'source'                 => 'database',
            'teams'                  => $latentTeams,
        ]));
    }

    private function runCommand(int $withinHours, bool $dryRun = false, int $safetyMargin = 30): \Illuminate\Testing\PendingCommand
    {
        $params = ['--within-hours' => $withinHours, '--safety-margin-minutes' => $safetyMargin];
        if ($dryRun) {
            $params['--dry-run'] = true;
        }
        return $this->artisan('robetting:capture-official-predictions', $params);
    }

    // 1. match dentro finestra -> prediction creata
    public function test_match_inside_window_is_captured(): void
    {
        $match = $this->createMatch(now()->addHours(10)->toIso8601String());
        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand(24)->assertExitCode(0);

        $this->assertSame(1, Prediction::count());
        $this->assertSame(OfficialPredictionCaptureService::MODEL_KEY, Prediction::first()->model_key);
    }

    // 2. match fuori finestra -> ignorato
    public function test_match_outside_window_is_ignored(): void
    {
        $this->createMatch(now()->addHours(48)->toIso8601String());
        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand(24)->assertExitCode(0);

        $this->assertSame(0, Prediction::count());
    }

    // 3. kickoff passato -> ignorato
    public function test_past_kickoff_is_ignored(): void
    {
        $this->createMatch(now()->subHour()->toIso8601String());
        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand(24)->assertExitCode(0);

        $this->assertSame(0, Prediction::count());
    }

    // 4. < safety margin -> ignorato
    public function test_match_inside_safety_margin_is_ignored(): void
    {
        $this->createMatch(now()->addMinutes(10)->toIso8601String());
        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand(24, false, 30)->assertExitCode(0);

        $this->assertSame(0, Prediction::count());
    }

    // 5. stesso match/model/kickoff già salvato -> nessun duplicato
    public function test_already_captured_same_kickoff_is_not_duplicated(): void
    {
        $match = $this->createMatch(now()->addHours(10)->toIso8601String());
        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand(24);
        $this->assertSame(1, Prediction::count());

        $this->runCommand(24);
        $this->assertSame(1, Prediction::count());
    }

    // 6. kickoff modificato -> nuova prediction consentita
    // 7. seconda esecuzione sul nuovo kickoff -> nessun ulteriore duplicato
    public function test_kickoff_changed_allows_new_capture_then_becomes_idempotent(): void
    {
        $match = $this->createMatch(now()->addHours(10)->toIso8601String());
        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand(24);
        $this->assertSame(1, Prediction::count());
        $firstKickoff = Prediction::first()->kickoff_at->toIso8601String();

        // Kickoff postponed.
        $match->update(['kickoff_at' => now()->addHours(15)->toIso8601String()]);

        $this->runCommand(24); // [6] new capture allowed
        $this->assertSame(2, Prediction::count());
        $kickoffs = Prediction::pluck('kickoff_at')->map(fn ($k) => $k->toIso8601String())->all();
        $this->assertContains($firstKickoff, $kickoffs);
        $this->assertNotSame($kickoffs[0], $kickoffs[1]);

        $this->runCommand(24); // [7] idempotent again on the new kickoff
        $this->assertSame(2, Prediction::count());
    }

    // 8. officialPredictionData unavailable -> nessuna riga
    public function test_model_unavailable_creates_no_row(): void
    {
        $this->createMatch(now()->addHours(10)->toIso8601String());
        $this->mockPredictionService();
        // No writeSnapshots() — Structural/Latent snapshots don't exist.

        $this->runCommand(24)->assertExitCode(0);

        $this->assertSame(0, Prediction::count());
    }

    // 9. dry-run -> zero scritture
    public function test_dry_run_never_writes(): void
    {
        $this->createMatch(now()->addHours(10)->toIso8601String());
        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand(24, dryRun: true)
            ->expectsOutputToContain('WOULD_CAPTURE')
            ->assertExitCode(0);

        $this->assertSame(0, Prediction::count());
    }

    // 10. due match eleggibili -> entrambi catturati
    public function test_two_eligible_matches_are_both_captured(): void
    {
        $home2 = Team::create(['name' => 'Napoli', 'country_id' => $this->home->country_id]);
        $away2 = Team::create(['name' => 'Lazio', 'country_id' => $this->home->country_id]);

        $this->createMatch(now()->addHours(5)->toIso8601String());
        $this->createMatch(now()->addHours(15)->toIso8601String(), $home2, $away2);

        $this->mockPredictionService();
        $this->writeSnapshots([$home2, $away2]);

        $this->runCommand(24)->assertExitCode(0);

        $this->assertSame(2, Prediction::count());
    }

    // 11. errore su uno -> l'altro continua
    public function test_error_on_one_match_does_not_block_the_other(): void
    {
        $home2 = Team::create(['name' => 'Napoli', 'country_id' => $this->home->country_id]);
        $away2 = Team::create(['name' => 'Lazio', 'country_id' => $this->home->country_id]);

        $failing = $this->createMatch(now()->addHours(5)->toIso8601String());
        $ok = $this->createMatch(now()->addHours(15)->toIso8601String(), $home2, $away2);

        $features = array_fill_keys(PredictionEngineV1::featureNames(), 1.234);
        $features['core_elo_home_pre_match_elo'] = 1600.0;
        $features['core_elo_away_pre_match_elo'] = 1500.0;

        $this->mockPredictionService(function (FootballMatch $match) use ($failing, $features) {
            if ($match->id === $failing->id) {
                throw new \RuntimeException('simulated feature extraction failure');
            }
            return ['match_id' => $match->id, 'model_version' => '1.0.0', 'feature_set_version' => 'core_v1', 'features' => $features, 'match' => null];
        });
        $this->writeSnapshots([$home2, $away2]);

        $this->runCommand(24)
            ->expectsOutputToContain('ERROR')
            ->assertExitCode(0);

        $this->assertSame(1, Prediction::count());
        $this->assertSame($ok->id, Prediction::first()->match_id);
    }

    // 12. model_key / 13. generated_at < kickoff_at / 14. 47 feature
    public function test_captured_row_has_correct_model_key_timing_and_feature_count(): void
    {
        $match = $this->createMatch(now()->addHours(10)->toIso8601String());
        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand(24);

        $row = Prediction::first();
        $this->assertSame('candidate47_structural_log', $row->model_key);
        $this->assertTrue($row->generated_at->lt($row->kickoff_at));
        $this->assertCount(47, $row->features_json);
    }
}
