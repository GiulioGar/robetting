<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\Player;
use App\Models\PlayerAbsence;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\TeamAvailabilityCalculator;
use App\Services\Prediction\MatchPredictionService;
use App\Services\Prediction\PredictionEngineV1;
use App\Services\Prediction\PublicMatchPredictionService;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Match Page "Disponibilità rosa" (Availability V1, display only).
 */
class MatchPageAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $competition;
    private Season $season;
    private Team $home;
    private Team $away;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->competition = Competition::create(['country_id' => $country->id, 'name' => 'Serie A', 'slug' => 'serie-a', 'format' => 'league', 'is_active' => true]);
        $this->season = Season::create(['competition_id' => $this->competition->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);
        $this->home = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->away = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
    }

    private function fixture(?Carbon $fetchedAt, ?Carbon $kickoff = null): FootballMatch
    {
        $kickoff ??= Carbon::now()->addDays(2);
        $m = FootballMatch::create([
            'competition_id' => $this->competition->id, 'season_id' => $this->season->id,
            'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
            'kickoff_at' => $kickoff, 'status' => 'scheduled',
        ]);
        $m->forceFill(['injuries_fetched_at' => $fetchedAt])->save();

        return $m;
    }

    private function absence(FootballMatch $m, Team $team, string $playerName, string $reason): Player
    {
        $p = Player::create(['name' => $playerName]);
        PlayerAbsence::create([
            'match_id' => $m->id, 'player_id' => $p->id, 'team_id' => $team->id, 'data_source_id' => $this->ds->id,
            'absence_type' => 'Missing Fixture', 'reason' => $reason,
        ]);

        return $p;
    }

    private function page(FootballMatch $m, string $query = '')
    {
        return $this->get(route('matches.show', $m, absolute: false) . $query);
    }

    public function test_covered_future_fixture_with_absentees_shows_home_and_away_correctly(): void
    {
        $m = $this->fixture(Carbon::now()->subHour());
        $this->absence($m, $this->home, 'Anguissa Home', 'Knee Injury');
        $this->absence($m, $this->away, 'Lautaro Away', 'Suspended');

        $response = $this->page($m);
        $response->assertOk();

        // View data = the calculator output per side.
        $homeExpected = TeamAvailabilityCalculator::calculateForMatch($m, $this->home->id);
        $awayExpected = TeamAvailabilityCalculator::calculateForMatch($m, $this->away->id);
        $this->assertEquals($homeExpected, $response->viewData('homeAvailability'));
        $this->assertEquals($awayExpected, $response->viewData('awayAvailability'));
        $this->assertSame(1, $response->viewData('homeAvailability')['forced_absent_count']);

        $html = $response->getContent();
        $homeBlock = $this->block($html, 'home');
        $awayBlock = $this->block($html, 'away');
        $this->assertStringContainsString('Anguissa Home', $homeBlock);
        $this->assertStringContainsString('Knee Injury', $homeBlock);
        $this->assertStringNotContainsString('Lautaro Away', $homeBlock);
        $this->assertStringContainsString('Lautaro Away', $awayBlock);
        $this->assertStringContainsString('Suspended', $awayBlock);
        // No history/market-value data in this fixture => not weighable, shown as incomplete with the null cause.
        $this->assertStringContainsString('calcolo incompleto', $homeBlock);
        $this->assertStringContainsString('U_NULL_NO_MEMBERSHIP_EVIDENCE', $homeBlock);
    }

    public function test_covered_fixture_without_absentees_says_so(): void
    {
        $m = $this->fixture(Carbon::now()->subHour());

        $html = $this->page($m)->assertOk()->getContent();

        $this->assertStringContainsString('Nessuna assenza forzata rilevata', $this->block($html, 'home'));
        $this->assertStringContainsString('Nessuna assenza forzata rilevata', $this->block($html, 'away'));
    }

    public function test_non_pre_match_snapshot_shows_data_not_available(): void
    {
        $kickoff = Carbon::now()->addDays(2);
        $m = $this->fixture($kickoff->copy()->addHour(), $kickoff); // fetched AFTER kickoff: not certifiable
        $this->absence($m, $this->home, 'Hidden Player', 'Knee Injury');

        $response = $this->page($m)->assertOk();
        $html = $response->getContent();

        foreach (['home', 'away'] as $side) {
            $this->assertStringContainsString('Dato non ancora disponibile', $this->block($html, $side));
        }
        $response->assertDontSee('Hidden Player');
        $response->assertDontSee('Nessuna assenza forzata rilevata');

        // Never fetched at all: same message.
        $m2 = $this->fixture(null);
        $this->assertStringContainsString('Dato non ancora disponibile', $this->block($this->page($m2)->getContent(), 'home'));
    }

    public function test_availability_does_not_change_candidate47_probabilities(): void
    {
        // Real Napoli/Bodo ids present in the Champions-profile snapshots (as in MatchPageExperimentalPredictionTest).
        $this->home->delete();
        $this->away->delete();
        $home = new Team(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $home->id = 278;
        $home->save();
        $away = new Team(['name' => 'Bodo/Glimt', 'type' => 'club', 'is_active' => true]);
        $away->id = 414;
        $away->save();
        $this->home = $home;
        $this->away = $away;

        $names = PredictionEngineV1::featureNames();
        $mock = $this->createMock(MatchPredictionService::class);
        $mock->method('predictWithDebug')->willReturn([
            'lambda_home' => 1.6, 'lambda_away' => 1.3, 'probability_home' => 0.45, 'probability_draw' => 0.24, 'probability_away' => 0.31,
            'features' => array_fill_keys($names, 1.0), 'features_candidate47' => array_fill_keys($names, 1.0),
        ]);
        $this->app->instance(MatchPredictionService::class, $mock);

        $m = $this->fixture(null);
        $before = $this->page($m, '?debug_predict=1')->assertOk()->viewData('publicPrediction');
        $this->assertSame(PublicMatchPredictionService::MODEL_CANDIDATE_V2, $before['model_used']);

        // Add a valid pre-match snapshot with forced absences on both sides.
        $m->forceFill(['injuries_fetched_at' => Carbon::now()->subHour()])->save();
        $this->absence($m, $this->home, 'Injured Home', 'Knee Injury');
        $this->absence($m, $this->away, 'Injured Away', 'Muscle Injury');

        $response = $this->page($m->fresh(), '?debug_predict=1')->assertOk();
        $after = $response->viewData('publicPrediction');

        $this->assertSame(1, $response->viewData('homeAvailability')['forced_absent_count']);
        foreach (['probability_home', 'probability_draw', 'probability_away', 'lambda_home', 'lambda_away', 'lambda3', 'model_used'] as $k) {
            $this->assertSame($before[$k], $after[$k], "{$k} changed after adding Availability data");
        }
    }

    /** HTML of one side's Availability card. */
    private function block(string $html, string $side): string
    {
        $this->assertSame(1, preg_match('/data-availability="' . $side . '">(.*?)(?=data-availability="|<!--|\{\{--|id="disponibilita-rosa"|<h2 class="fs-5 fw-semibold mb-3">Confronto)/s', $html, $mm), "block {$side} not found");

        return $mm[1];
    }
}
