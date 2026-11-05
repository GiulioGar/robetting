<?php

namespace Tests\Feature\Prediction;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\PreMatchFeatureAggregator;
use App\Services\Prediction\MatchPredictionService;
use App\Services\Prediction\PredictionEngineV1;
use App\Services\Prediction\RecentExperimentCaptureService as Svc;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prospective Candidate47 Team History vs RECENT_ALL pair on UEFA fixtures: same artifact/snapshots/timestamp, only the Recent
 * window differs, append-only, idempotent, isolated from the official rows. The feature extraction is stubbed (the real
 * artifact + Latent/Structural snapshots of the public UEFA profile are used; AC Milan=276 and Ferencvarosi=484 are in them).
 */
class RecentExperimentCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    private Competition $uel;
    private Season $season;
    private Team $home;
    private Team $away;
    private bool $leak = false;

    protected function setUp(): void
    {
        parent::setUp();
        $country = Country::create(['name' => 'Europe', 'football_code' => 'EU']);
        $this->uel    = Competition::create(['country_id' => $country->id, 'name' => 'UEFA Europa League', 'slug' => 'uefa-europa-league', 'format' => 'cup', 'is_active' => true]);
        $this->season = Season::create(['competition_id' => $this->uel->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $this->home = Team::forceCreate(['id' => 276, 'name' => 'AC Milan', 'type' => 'club', 'is_active' => true]);
        $this->away = Team::forceCreate(['id' => 484, 'name' => 'Ferencvarosi TC', 'type' => 'club', 'is_active' => true]);
    }

    private function match(string $kickoff, string $status = 'scheduled', ?Competition $c = null, ?Team $h = null, ?Team $a = null): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => ($c ?? $this->uel)->id, 'season_id' => $this->season->id, 'home_team_id' => ($h ?? $this->home)->id,
            'away_team_id' => ($a ?? $this->away)->id, 'kickoff_at' => Carbon::parse($kickoff, 'UTC'), 'status' => $status,
        ]);
    }

    /** Stub of the feature extraction: every base feature = 1.0; the Recent block differs per window mode. */
    private function service(): Svc
    {
        $stub = $this->createMock(MatchPredictionService::class);
        $stub->method('predictWithDebug')->willReturnCallback(function ($m, $recent, $elo) {
            $f = array_fill_keys(PredictionEngineV1::featureNames(), 1.0);
            foreach ($f as $k => $_) {
                if (str_starts_with($k, 'core_recent_')) {
                    $f[$k] = $recent === PreMatchFeatureAggregator::RECENT_WINDOW_ALL ? 2.2 : 0.6;
                }
            }
            if ($this->leak && $recent === PreMatchFeatureAggregator::RECENT_WINDOW_ALL) {
                $f['core_elo_home_pre_match_elo'] = 1700.0;    // a NON-Recent feature differs
            }

            return ['features' => $f, 'features_candidate47' => $f];
        });

        return new Svc($stub);
    }

    public function test_records_two_variants_with_same_artifact_snapshots_and_timestamp(): void
    {
        $m = $this->match('2099-12-01 20:00:00');
        $now = Carbon::parse('2026-11-06 10:00:00', 'UTC');

        $r = $this->service()->capture($m, $now);

        $this->assertSame('CAPTURED', $r['outcome'], json_encode($r));
        $rows = Prediction::where('purpose', Prediction::PURPOSE_RECENT_EXPERIMENT)->orderBy('model_key')->get()->keyBy('model_key');
        $this->assertCount(2, $rows);
        $th = $rows[Svc::MODEL_KEY_TEAM_HISTORY];
        $all = $rows[Svc::MODEL_KEY_RECENT_ALL];
        foreach (['model_version', 'feature_set_version', 'artifact_sha256', 'structural_snapshot_version'] as $k) {
            $this->assertSame($th->$k, $all->$k, $k);
        }
        $this->assertNotNull($th->artifact_sha256);
        $this->assertSame($now->format('Y-m-d H:i:s'), $th->generated_at->format('Y-m-d H:i:s'));
        $this->assertTrue($th->generated_at->eq($all->generated_at));
        $this->assertTrue($th->generated_at->lt($th->kickoff_at));
        $this->assertEqualsWithDelta(1.0, $th->probability_home + $th->probability_draw + $th->probability_away, 1e-6);
        $this->assertNotEquals($th->probability_home, $all->probability_home);          // the window does move the prediction
        // only the Recent block differs between the stored feature vectors
        $strip = fn (array $f) => array_filter($f, fn ($k) => ! str_starts_with($k, 'core_recent_') && $k !== '_experiment', ARRAY_FILTER_USE_KEY);
        $this->assertSame($strip($th->features_json), $strip($all->features_json));

        $meta = $th->features_json['_experiment'];
        $this->assertSame('team_history', $meta['variant']);
        $this->assertSame('recent_all', $all->features_json['_experiment']['variant']);
        $this->assertSame($now->format('Y-m-d H:i:s'), $meta['reference_time_utc']);
        $this->assertGreaterThan(0, $meta['structural']['top25_home']);
        $this->assertGreaterThan(0, $meta['structural']['top25_away']);
        $this->assertEqualsWithDelta(log($meta['structural']['top25_home'] / $meta['structural']['top25_away']), $meta['structural']['log_ratio_gap'], 1e-3);
        $this->assertArrayHasKey('matches_with_shot_stats', $meta['recent_windows']['recent_all']['away']);   // coverage reported
        $this->assertArrayHasKey('matches_with_results', $meta['recent_windows']['team_history']['home']);
    }

    public function test_is_idempotent_and_leaves_official_rows_alone(): void
    {
        $m = $this->match('2099-12-01 20:00:00');
        $now = Carbon::parse('2026-11-06 10:00:00', 'UTC');

        $this->assertSame('CAPTURED', $this->service()->capture($m, $now)['outcome']);
        $this->assertSame('ALREADY_CAPTURED', $this->service()->capture($m, $now->copy()->addHour())['outcome']);

        $this->assertSame(2, Prediction::where('purpose', Prediction::PURPOSE_RECENT_EXPERIMENT)->count());   // first photograph kept
        $this->assertSame(0, Prediction::where('purpose', Prediction::PURPOSE_OFFICIAL)->count());            // nothing official written
        $this->assertSame('2026-11-06 10:00:00', Prediction::where('purpose', Prediction::PURPOSE_RECENT_EXPERIMENT)->first()->generated_at->format('Y-m-d H:i:s'));
    }

    public function test_nothing_is_recorded_at_or_after_kickoff(): void
    {
        $m = $this->match('2026-11-06 20:00:00');

        $this->assertSame('TOO_LATE', $this->service()->capture($m, Carbon::parse('2026-11-06 20:00:00', 'UTC'))['outcome']);
        $this->assertSame('TOO_LATE', $this->service()->capture($m, Carbon::parse('2026-11-06 20:00:01', 'UTC'))['outcome']);
        $this->assertSame(0, Prediction::count());
    }

    public function test_variants_differing_outside_the_recent_block_are_refused(): void
    {
        $this->leak = true;
        $m = $this->match('2099-12-01 20:00:00');

        $r = $this->service()->capture($m, Carbon::parse('2026-11-06 10:00:00', 'UTC'));

        $this->assertSame('ERROR', $r['outcome']);
        $this->assertStringContainsString('non-Recent', $r['reason']);
        $this->assertSame(0, Prediction::count());
    }

    public function test_snapshots_later_than_the_prediction_time_are_refused(): void
    {
        $m = $this->match('2099-12-01 20:00:00');

        $r = $this->service()->capture($m, Carbon::parse('2026-10-21 10:00:00', 'UTC'));      // before the Structural snapshot was generated

        $this->assertSame('ERROR', $r['outcome']);
        $this->assertStringContainsString('later than the prediction time', $r['reason']);
        $this->assertSame(0, Prediction::count());
    }

    public function test_team_outside_the_snapshots_yields_model_unavailable_without_rows(): void
    {
        $c = Team::forceCreate(['id' => 999001, 'name' => 'Unknown FC', 'type' => 'club', 'is_active' => true]);
        $m = $this->match('2099-12-01 20:00:00', 'scheduled', null, $this->home, $c);

        $this->assertSame('MODEL_UNAVAILABLE', $this->service()->capture($m, Carbon::parse('2026-11-06 10:00:00', 'UTC'))['outcome']);
        $this->assertSame(0, Prediction::count());
    }

    public function test_only_upcoming_uefa_fixtures_within_the_window_are_eligible(): void
    {
        $now = Carbon::parse('2026-11-06 10:00:00', 'UTC');
        $ok = $this->match('2026-11-07 20:00:00');                                                     // UEFA, scheduled, +34h
        $this->match('2026-11-12 20:00:00');                                                           // beyond 72h
        $this->match('2026-11-06 09:00:00');                                                           // already started
        $this->match('2026-11-07 20:00:00', 'finished');                                               // not scheduled
        $serieA = Competition::create(['country_id' => Country::first()->id, 'name' => 'Serie A', 'slug' => 'serie-a', 'format' => 'league', 'is_active' => true]);
        $this->match('2026-11-07 20:00:00', 'scheduled', $serieA);                                     // not UEFA

        $ids = $this->service()->findEligibleMatches(72, $now)->pluck('id')->all();

        $this->assertSame([$ok->id], $ids);
    }
}
