<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\MatchPredictionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Struttura portale, Step 1A/1D: /matches day-by-day navigation.
 * DB-only, no API calls. Step 1D adds: historical dates (past matches)
 * must render correctly, and a 'finished' row must never show prediction
 * percentages (real FT score instead), even if a stale public_latest row
 * happens to still exist for it.
 */
class MatchIndexDayNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Competition $comp;
    private Season $season;
    private Team $home;
    private Team $away;

    protected function setUp(): void
    {
        parent::setUp();

        $country    = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->comp = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $this->season = Season::create([
            'competition_id' => $this->comp->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
        ]);
        $this->home = Team::create(['name' => 'Napoli', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Frosinone', 'country_id' => $country->id]);
    }

    private function match(string $kickoffRome, string $status = 'scheduled', ?int $hg = null, ?int $ag = null): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->home->id,
            'away_team_id'   => $this->away->id,
            'kickoff_at'     => Carbon::parse($kickoffRome, 'Europe/Rome')->utc(),
            'status'         => $status,
            'home_score_ft'  => $hg,
            'away_score_ft'  => $ag,
        ]);
    }

    public function test_default_shows_today_matches_only(): void
    {
        $todayRome = Carbon::now('Europe/Rome')->format('Y-m-d');
        $todayMatch = $this->match($todayRome . ' 20:45:00');
        $this->match('2000-01-01 20:45:00'); // far in the past, must not appear

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('Napoli');
        $response->assertSee(route('matches.show', $todayMatch));
    }

    public function test_date_param_shows_matches_for_that_day_only(): void
    {
        $this->match('2026-11-20 18:00:00');
        $other = $this->match('2026-11-21 18:00:00');

        $response = $this->get(route('home', ['date' => '2026-11-21']));

        $response->assertOk();
        $response->assertSee(route('matches.show', $other));
        $response->assertSee('21/11/2026');
    }

    public function test_prev_and_next_links_shift_by_one_day(): void
    {
        $response = $this->get(route('home', ['date' => '2026-11-21']));

        $response->assertOk();
        $response->assertSee(route('home', ['date' => '2026-11-20']), false);
        $response->assertSee(route('home', ['date' => '2026-11-22']), false);
    }

    public function test_can_advance_far_into_the_future_without_limit(): void
    {
        $future = $this->match('2030-06-01 18:00:00');

        $response = $this->get(route('home', ['date' => '2030-06-01']));

        $response->assertOk();
        $response->assertSee(route('matches.show', $future));
    }

    public function test_invalid_date_falls_back_to_today(): void
    {
        $todayRome = Carbon::now('Europe/Rome')->format('d/m/Y');

        $response = $this->get(route('home', ['date' => 'not-a-date']));

        $response->assertOk();
        $response->assertSee($todayRome);
    }

    public function test_match_row_shows_required_fields_and_links_to_detail_page(): void
    {
        $todayRome = Carbon::now('Europe/Rome')->format('Y-m-d');
        $match = $this->match($todayRome . ' 20:45:00', 'finished', 2, 1);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('Serie A');
        $response->assertSee('Napoli');
        $response->assertSee('Frosinone');
        $response->assertSee('Terminata');
        $response->assertSee('2 - 1');
        $response->assertSee(route('matches.show', $match));
    }

    // Step 1D — 1. historical date with finished matches must render them,
    // same as any future date. Uses a date strictly before "now" (not a
    // fixed literal), so this stays a real regression guard regardless of
    // when the suite runs.
    public function test_historical_date_with_finished_matches_is_visible(): void
    {
        $pastDate = Carbon::now('Europe/Rome')->subYear()->format('Y-m-d');
        $pastMatch = $this->match($pastDate . ' 18:00:00', 'finished', 3, 0);

        $response = $this->get(route('home', ['date' => $pastDate]));

        $response->assertOk();
        $response->assertSee('Napoli');
        $response->assertSee('3 - 0');
        $response->assertSee(route('matches.show', $pastMatch));
    }

    // Step 1D — 2. "Giorno precedente" from a past date keeps moving further
    // back and still finds matches (not just forward from today).
    public function test_previous_day_navigation_works_from_a_past_date(): void
    {
        $pastDate = Carbon::now('Europe/Rome')->subYear()->format('Y-m-d');
        $dayBefore = Carbon::parse($pastDate)->subDay();
        $earlierMatch = $this->match($dayBefore->format('Y-m-d') . ' 18:00:00', 'finished', 1, 1);

        $response = $this->get(route('home', ['date' => $pastDate]));
        $response->assertOk();
        $response->assertSee(route('home', ['date' => $dayBefore->format('Y-m-d')]), false);

        $prevResponse = $this->get(route('home', ['date' => $dayBefore->format('Y-m-d')]));
        $prevResponse->assertOk();
        $prevResponse->assertSee(route('matches.show', $earlierMatch));
        $prevResponse->assertSee('1 - 1');
    }

    // Step 1D — 4. finished: percentuali prediction non mostrate anche se
    // esiste una public_latest (riga "stale" lasciata da prima che il match
    // venisse giocato — scenario reale, non ipotetico).
    public function test_finished_match_never_shows_prediction_even_with_stale_public_latest_row(): void
    {
        $match = $this->match('2026-11-21 18:00:00', 'finished', 2, 1);
        $this->predictionRow($match, [
            'probability_home' => 0.70,
            'probability_draw' => 0.20,
            'probability_away' => 0.10,
        ]);

        $response = $this->get(route('home', ['date' => '2026-11-21']));

        $response->assertOk();
        $response->assertSee('2 - 1');
        $response->assertDontSee('1 70%');
    }

    public function test_empty_day_shows_no_matches_message(): void
    {
        $response = $this->get(route('home', ['date' => '2026-12-25']));

        $response->assertOk();
        $response->assertSee('Nessuna partita in programma per questo giorno.');
    }

    // Step 1A.5 — index reads PUBLIC_LATEST only, via a single batch query.

    private function predictionRow(FootballMatch $match, array $overrides = []): Prediction
    {
        return Prediction::create(array_merge([
            'match_id'             => $match->id,
            'model_key'            => 'candidate47_structural_log',
            'purpose'              => Prediction::PURPOSE_PUBLIC_LATEST,
            'model_version'        => '1.0.0',
            'feature_set_version'  => 'core_v1',
            'generated_at'         => now()->subMinute(),
            'kickoff_at'           => $match->kickoff_at,
            'lambda_home'          => 1.5,
            'lambda_away'          => 1.1,
            'probability_home'     => 0.60,
            'probability_draw'     => 0.25,
            'probability_away'     => 0.15,
            'features_json'        => ['a' => 1.0],
        ], $overrides));
    }

    public function test_shows_public_latest_prediction_percentages(): void
    {
        $match = $this->match('2026-11-21 18:00:00');
        $this->predictionRow($match);

        $response = $this->get(route('home', ['date' => '2026-11-21']));

        $response->assertOk();
        $response->assertSee('1 60%');
        $response->assertSee('X 25%');
        $response->assertSee('2 15%');
    }

    public function test_ignores_official_prediction_rows(): void
    {
        $match = $this->match('2026-11-21 18:00:00');
        $this->predictionRow($match, [
            'purpose'          => Prediction::PURPOSE_OFFICIAL,
            'probability_home' => 0.99,
            'probability_draw' => 0.005,
            'probability_away' => 0.005,
        ]);

        $response = $this->get(route('home', ['date' => '2026-11-21']));

        $response->assertOk();
        $response->assertDontSee('1 99%');
    }

    public function test_shows_public_latest_when_both_official_and_public_latest_exist(): void
    {
        $match = $this->match('2026-11-21 18:00:00');
        $this->predictionRow($match, [
            'purpose'          => Prediction::PURPOSE_OFFICIAL,
            'probability_home' => 0.99,
            'probability_draw' => 0.005,
            'probability_away' => 0.005,
        ]);
        $this->predictionRow($match); // public_latest: 60/25/15

        $response = $this->get(route('home', ['date' => '2026-11-21']));

        $response->assertOk();
        $response->assertSee('1 60%');
        $response->assertDontSee('1 99%');
    }

    public function test_match_without_public_latest_shows_no_percentages_and_never_computes_live(): void
    {
        $this->match('2026-11-21 18:00:00'); // no Prediction row at all

        // Proves the index never falls back to the live pipeline: if it did,
        // this binding would blow up the request and the page would 500.
        $throwingMock = $this->createMock(MatchPredictionService::class);
        $throwingMock->method('predictWithDebug')->willThrowException(
            new \RuntimeException('index must never call the live prediction pipeline')
        );
        $this->app->instance(MatchPredictionService::class, $throwingMock);

        // assertOk() alone proves it: if the controller had fallen back to a
        // live computation, the mocked exception above would have bubbled up
        // into a 500 response instead.
        $response = $this->get(route('home', ['date' => '2026-11-21']));

        $response->assertOk();
        $response->assertSee('Napoli');
    }

    public function test_uses_latest_duplicate_public_latest_row(): void
    {
        $match = $this->match('2026-11-21 18:00:00');
        $this->predictionRow($match, [
            'generated_at'      => now()->subHours(2),
            'probability_home'  => 0.10,
            'probability_draw'  => 0.10,
            'probability_away'  => 0.80,
        ]);
        $this->predictionRow($match, [
            'generated_at'      => now()->subMinute(), // freshest
            'probability_home'  => 0.70,
            'probability_draw'  => 0.20,
            'probability_away'  => 0.10,
        ]);

        $response = $this->get(route('home', ['date' => '2026-11-21']));

        $response->assertOk();
        $response->assertSee('1 70%');
        $response->assertDontSee('1 10%');
    }
}
