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
use App\Services\Prediction\PredictionEngineV1;
use App\Services\Prediction\PublicPredictionRefreshService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 1A.4 — PublicPredictionRefreshService + robetting:refresh-public-predictions.
 *
 * Same isolated-artifact-dir pattern as OfficialPredictionCaptureServiceTest
 * (real candidate47 artifact copy + controlled Structural/Latent snapshot
 * fixtures), so every assertion is deterministic and never touches the real
 * tools/models/*.json files. No API calls anywhere in this suite.
 */
class PublicPredictionRefreshServiceTest extends TestCase
{
    use RefreshDatabase;

    private const MODELS_DIR = __DIR__ . '/../../../tools/models';
    private const ARTIFACT_FILE = 'prediction_engine_candidate47_structural_log.json';
    private const CORE_COMPETITION_ID = 15; // Serie A, a real core id per OfficialPredictionCaptureService::CORE_COMPETITION_IDS
    private const MODEL_KEY = 'candidate47_structural_log';

    private string $tempDir;
    private Team $home;
    private Team $away;
    private Competition $competition;
    private Season $season;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/public_refresh_test_' . uniqid();
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
        $this->unfreezeTime();
        array_map('unlink', glob($this->tempDir . '/*'));
        rmdir($this->tempDir);
        parent::tearDown();
    }

    /**
     * Freezes BOTH `now()` (Illuminate\Support\Carbon, governs createMatch()'s
     * kickoff_at) and CarbonImmutable::now() (what the command/service
     * actually use) to the exact same instant — they are separate classes
     * with independent testNow state, so freezing only one leaves the other
     * on real wall-clock time and desyncs "kickoff in the future" from the
     * command's own notion of "now".
     */
    private function freezeAt(string $dateTime): void
    {
        $frozen = Carbon::parse($dateTime, 'UTC');
        Carbon::setTestNow($frozen);
        CarbonImmutable::setTestNow($frozen);
    }

    private function unfreezeTime(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
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

    private function runCommand(int $days = 30): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('robetting:refresh-public-predictions', ['--days' => $days]);
    }

    // 1. nuova public_latest -> CREATE
    public function test_new_match_creates_a_public_latest_row(): void
    {
        $this->createMatch(now()->addDays(5)->toIso8601String());
        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand()->assertExitCode(0);

        $this->assertSame(1, Prediction::count());
        $row = Prediction::first();
        $this->assertSame(Prediction::PURPOSE_PUBLIC_LATEST, $row->purpose);
        $this->assertSame(self::MODEL_KEY, $row->model_key);
    }

    // 2. refresh il giorno successivo -> UPDATE della stessa riga, non una seconda
    public function test_next_day_refresh_updates_the_same_row(): void
    {
        $this->freezeAt('2026-06-01 08:00:00');
        $match = $this->createMatch(now()->addDays(5)->toIso8601String());
        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand();
        $this->assertSame(1, Prediction::count());
        $firstId = Prediction::first()->id;
        $firstGeneratedAt = Prediction::first()->generated_at->toIso8601String();

        $this->freezeAt('2026-06-02 08:00:00');
        $this->runCommand();

        $this->assertSame(1, Prediction::count()); // still one row, not two
        $fresh = Prediction::first();
        $this->assertSame($firstId, $fresh->id);
        $this->assertNotSame($firstGeneratedAt, $fresh->generated_at->toIso8601String());
    }

    // 3. seconda esecuzione nello stesso giorno -> SKIP
    public function test_same_day_second_run_is_skipped(): void
    {
        $this->freezeAt('2026-06-01 08:00:00');
        $this->createMatch(now()->addDays(5)->toIso8601String());
        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand();
        $generatedAtFirstRun = Prediction::first()->generated_at->toIso8601String();

        $this->freezeAt('2026-06-01 14:00:00'); // later, same Rome day
        $this->runCommand()->expectsOutputToContain('skipped_today:  1');

        $this->assertSame(1, Prediction::count());
        $this->assertSame($generatedAtFirstRun, Prediction::first()->generated_at->toIso8601String());
    }

    // 4. due match: uno già aggiornato oggi, uno no -> solo il secondo processato
    public function test_only_the_stale_match_is_processed_when_one_is_already_fresh_today(): void
    {
        $this->freezeAt('2026-06-01 08:00:00');

        $home2 = Team::create(['name' => 'Napoli', 'country_id' => $this->home->country_id]);
        $away2 = Team::create(['name' => 'Lazio', 'country_id' => $this->home->country_id]);

        $matchA = $this->createMatch(now()->addDays(5)->toIso8601String());
        $matchB = $this->createMatch(now()->addDays(6)->toIso8601String(), $home2, $away2);

        $this->mockPredictionService();
        $this->writeSnapshots([$home2, $away2]);

        // Pre-seed only match A as already refreshed today.
        Prediction::create([
            'match_id' => $matchA->id, 'model_key' => self::MODEL_KEY, 'purpose' => Prediction::PURPOSE_PUBLIC_LATEST,
            'model_version' => '1.0.0', 'feature_set_version' => 'core_v1', 'generated_at' => now(),
            'kickoff_at' => $matchA->kickoff_at, 'lambda_home' => 1.5, 'lambda_away' => 1.1,
            'probability_home' => 0.5, 'probability_draw' => 0.3, 'probability_away' => 0.2, 'features_json' => ['a' => 1.0],
        ]);

        $this->runCommand()
            ->expectsOutputToContain('eligible:       2')
            ->expectsOutputToContain('skipped_today:  1');

        $this->assertSame(2, Prediction::count());
        $this->assertSame(1, Prediction::where('match_id', $matchB->id)->count());
    }

    // 5. presenza di una official dello stesso match/model_key -> resta intatta
    public function test_existing_official_row_is_left_untouched(): void
    {
        $match = $this->createMatch(now()->addDays(5)->toIso8601String());
        $official = Prediction::create([
            'match_id' => $match->id, 'model_key' => self::MODEL_KEY, 'purpose' => Prediction::PURPOSE_OFFICIAL,
            'model_version' => '1.0.0', 'feature_set_version' => 'core_v1', 'generated_at' => now()->subHours(3),
            'kickoff_at' => $match->kickoff_at, 'lambda_home' => 9.9, 'lambda_away' => 9.9,
            'probability_home' => 0.9, 'probability_draw' => 0.05, 'probability_away' => 0.05, 'features_json' => ['a' => 1.0],
        ]);

        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand();

        $this->assertSame(2, Prediction::count());
        $this->assertSame(1, Prediction::where('purpose', Prediction::PURPOSE_OFFICIAL)->count());
        $this->assertSame(1, Prediction::where('purpose', Prediction::PURPOSE_PUBLIC_LATEST)->count());

        $official->refresh();
        $this->assertSame(0.9, $official->probability_home); // untouched
    }

    // 6. public_latest esistente -> non impedisce future official (copertura di non-regressione, già garantita dallo Step 1A.3b)
    public function test_existing_public_latest_does_not_block_a_future_official_capture(): void
    {
        $match = $this->createMatch(now()->addDays(5)->toIso8601String());
        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand();
        $this->assertSame(1, Prediction::where('purpose', Prediction::PURPOSE_PUBLIC_LATEST)->count());

        $this->artisan('robetting:capture-official-predictions', ['--within-hours' => 24 * 10])->assertExitCode(0);

        $this->assertSame(1, Prediction::where('purpose', Prediction::PURPOSE_OFFICIAL)->count());
        $this->assertSame(2, Prediction::count());
    }

    // 7. Candidate47 unavailable -> nessun fallback FULL59 / nessuna riga fasulla
    public function test_candidate47_unavailable_creates_no_row(): void
    {
        $this->createMatch(now()->addDays(5)->toIso8601String());
        $this->mockPredictionService();
        // No writeSnapshots() — Structural/Latent snapshots don't exist -> unavailable.

        $this->runCommand()
            ->expectsOutputToContain('unavailable:    1')
            ->assertExitCode(0);

        $this->assertSame(0, Prediction::count());
    }

    // 8. match oltre l'orizzonte -> ignorato
    public function test_match_beyond_horizon_is_ignored(): void
    {
        $this->createMatch(now()->addDays(45)->toIso8601String());
        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand(30)
            ->expectsOutputToContain('eligible:       0')
            ->assertExitCode(0);

        $this->assertSame(0, Prediction::count());
    }

    // 9. match non scheduled -> ignorato
    public function test_non_scheduled_match_is_ignored(): void
    {
        $match = $this->createMatch(now()->addDays(5)->toIso8601String());
        $match->update(['status' => 'postponed']);
        $this->mockPredictionService();
        $this->writeSnapshots();

        $this->runCommand()
            ->expectsOutputToContain('eligible:       0')
            ->assertExitCode(0);

        $this->assertSame(0, Prediction::count());
    }

    // 10. errore su un match -> batch continua
    public function test_error_on_one_match_does_not_block_the_other(): void
    {
        $home2 = Team::create(['name' => 'Napoli', 'country_id' => $this->home->country_id]);
        $away2 = Team::create(['name' => 'Lazio', 'country_id' => $this->home->country_id]);

        $failing = $this->createMatch(now()->addDays(5)->toIso8601String());
        $ok = $this->createMatch(now()->addDays(6)->toIso8601String(), $home2, $away2);

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

        $this->runCommand()
            ->expectsOutputToContain('errors:         1')
            ->assertExitCode(0);

        $this->assertSame(1, Prediction::count());
        $this->assertSame(1, Prediction::where('match_id', $ok->id)->count());
        $this->assertSame(0, Prediction::where('match_id', $failing->id)->count());
    }
}
