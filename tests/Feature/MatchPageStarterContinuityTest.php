<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchPlayerStatistic;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the "Continuità titolari" (E4 starter continuity) section on /matches/{match}.
 *
 * Rules under test:
 *  - homeStarterContinuity / awayStarterContinuity passed to view
 *  - average_starters_retained rendered (1 decimal)
 *  - average_starters_changed rendered (1 decimal)
 *  - players_started_4_of_last_5 rendered
 *  - players_started_5_of_last_5 rendered
 *  - distinct_starters_last_5 rendered
 *  - coverage shown (X/Y format + %)
 *  - complete_transitions_count rendered
 *  - NULL averages rendered as "—"
 *  - matches_considered = 0 → fallback message
 *  - future match excluded (anti-leakage)
 *  - section visible on finished match
 *  - section heading always visible
 *  - home and away kept separate
 */
class MatchPageStarterContinuityTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private FootballMatch $match;
    private Team $homeTeam;
    private Team $awayTeam;
    private Competition $comp;
    private Season $season;

    private const TARGET = '2026-09-10 20:45:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        $country      = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->comp   = Competition::create([
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
            'is_current'     => true,
        ]);
        $this->homeTeam = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->awayTeam = Team::create(['name' => 'Milan',  'type' => 'club', 'is_active' => true]);

        $this->match = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET),
            'status'         => 'scheduled',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. View receives both continuity arrays
    // ─────────────────────────────────────────────────────────────────────────

    public function test_view_receives_home_and_away_starter_continuity(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertViewHas('homeStarterContinuity');
        $response->assertViewHas('awayStarterContinuity');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Section heading always visible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_heading_visible(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('Continuità titolari');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. No history → fallback message
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_history_shows_fallback_message(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('Dati lineup non disponibili');

        $home = $response->viewData('homeStarterContinuity');
        $this->assertSame(0, $home['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. average_starters_retained rendered with 1 decimal
    // ─────────────────────────────────────────────────────────────────────────

    public function test_average_retained_rendered(): void
    {
        $players = $this->createPlayers(11);

        $prev1 = $this->makePrev(Carbon::parse(self::TARGET)->subDays(14));
        $prev2 = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStarters($prev1, $this->homeTeam, $players);
        $this->addStarters($prev2, $this->homeTeam, $players); // same XI → retained = 11

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeStarterContinuity');
        $this->assertNotNull($home['average_starters_retained']);
        $expected = number_format($home['average_starters_retained'], 1);
        $response->assertSee($expected);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. average_starters_changed rendered with 1 decimal
    // ─────────────────────────────────────────────────────────────────────────

    public function test_average_changed_rendered(): void
    {
        $core  = $this->createPlayers(10);
        $extra = $this->createPlayers(2); // 1 per match → 1 change each pair

        $prev1 = $this->makePrev(Carbon::parse(self::TARGET)->subDays(14));
        $prev2 = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStarters($prev1, $this->homeTeam, array_merge($core, [$extra[0]]));
        $this->addStarters($prev2, $this->homeTeam, array_merge($core, [$extra[1]]));

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeStarterContinuity');
        $this->assertNotNull($home['average_starters_changed']);
        $expected = number_format($home['average_starters_changed'], 1);
        $response->assertSee($expected);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. players_started_4_of_last_5 and 5_of_last_5 rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_started_4_and_5_of_last_5_rendered(): void
    {
        $players = $this->createPlayers(11);

        for ($i = 1; $i <= 5; $i++) {
            $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays($i * 7));
            $this->addStarters($prev, $this->homeTeam, $players);
        }

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeStarterContinuity');
        $this->assertSame(11, $home['players_started_5_of_last_5']);
        $this->assertSame(11, $home['players_started_4_of_last_5']);

        $response->assertSee('Titolari ≥4/5 volte');
        $response->assertSee('Sempre titolari (5/5)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. distinct_starters_last_5 rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_distinct_starters_rendered(): void
    {
        // Two different XIs across two matches → 22 distinct starters
        $groupA = $this->createPlayers(11);
        $groupB = $this->createPlayers(11);

        $prev1 = $this->makePrev(Carbon::parse(self::TARGET)->subDays(14));
        $prev2 = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStarters($prev1, $this->homeTeam, $groupA);
        $this->addStarters($prev2, $this->homeTeam, $groupB);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeStarterContinuity');
        $this->assertSame(22, $home['distinct_starters_last_5']);
        $response->assertSee('Titolari diversi usati');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. Coverage rendered as "X/Y (ZZ%)"
    // ─────────────────────────────────────────────────────────────────────────

    public function test_coverage_rendered(): void
    {
        $full    = $this->createPlayers(11);
        $partial = $this->createPlayers(9);

        $prev1 = $this->makePrev(Carbon::parse(self::TARGET)->subDays(14));
        $prev2 = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStarters($prev1, $this->homeTeam, $full);    // complete
        $this->addStarters($prev2, $this->homeTeam, $partial); // incomplete

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeStarterContinuity');
        $this->assertSame(1, $home['matches_with_complete_starting_xi']);
        $this->assertSame(2, $home['matches_considered']);

        $response->assertSee('1/2');
        $response->assertSee('50%');
        $response->assertSee('Copertura lineup');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9. complete_transitions_count rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_complete_transitions_count_rendered(): void
    {
        $players = $this->createPlayers(11);

        $prev1 = $this->makePrev(Carbon::parse(self::TARGET)->subDays(14));
        $prev2 = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStarters($prev1, $this->homeTeam, $players);
        $this->addStarters($prev2, $this->homeTeam, $players);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeStarterContinuity');
        $this->assertSame(1, $home['complete_transitions_count']);
        $response->assertSee('Transizioni complete analizzate');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10. NULL averages rendered as "—" when no complete transitions
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_averages_rendered_as_dash(): void
    {
        // One match only → no pairs → averages null
        $players = $this->createPlayers(11);
        $prev    = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStarters($prev, $this->homeTeam, $players);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeStarterContinuity');
        $this->assertNull($home['average_starters_retained']);
        $this->assertNull($home['average_starters_changed']);
        $response->assertSee('—');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11. Future match excluded (anti-leakage)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_future_match_excluded(): void
    {
        $players = $this->createPlayers(11);

        $future = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET)->addDays(7),
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 0,
        ]);
        $this->addStarters($future, $this->homeTeam, $players);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeStarterContinuity');
        $this->assertSame(0, $home['matches_considered']);
        $response->assertSee('Dati lineup non disponibili');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 12. Section visible on finished match
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_visible_on_finished_match(): void
    {
        $this->match->update([
            'status'        => 'finished',
            'home_score_ft' => 2,
            'away_score_ft' => 1,
        ]);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('Continuità titolari');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 13. Home and away profiles kept separate
    // ─────────────────────────────────────────────────────────────────────────

    public function test_home_and_away_continuity_are_separate(): void
    {
        $homePlayers = $this->createPlayers(11);
        $awayPlayers = $this->createPlayers(11);

        $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStarters($prev, $this->homeTeam, $homePlayers);
        $this->addStarters($prev, $this->awayTeam, $awayPlayers);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeStarterContinuity');
        $away = $response->viewData('awayStarterContinuity');

        $this->assertSame(1, $home['matches_considered']);
        $this->assertSame(1, $away['matches_considered']);
        $this->assertSame(11, $home['distinct_starters_last_5']);
        $this->assertSame(11, $away['distinct_starters_last_5']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 14. Labels visible when data available
    // ─────────────────────────────────────────────────────────────────────────

    public function test_all_labels_visible_when_data_available(): void
    {
        $players = $this->createPlayers(11);
        $prev    = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStarters($prev, $this->homeTeam, $players);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $response->assertSee('Titolari confermati in media');
        $response->assertSee("Cambi medi nell'XI", false); // apostrophe is literal in HTML
        $response->assertSee('Titolari ≥4/5 volte');
        $response->assertSee('Sempre titolari (5/5)');
        $response->assertSee('Titolari diversi usati');
        $response->assertSee('Copertura lineup');
        $response->assertSee('Transizioni complete analizzate');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makePrev(Carbon $kickoff): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => $kickoff,
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);
    }

    private function createPlayers(int $n): array
    {
        $players = [];
        for ($i = 0; $i < $n; $i++) {
            $players[] = Player::create(['name' => 'Player_' . uniqid()]);
        }
        return $players;
    }

    private function addStarters(FootballMatch $match, Team $team, array $players): void
    {
        foreach ($players as $player) {
            MatchPlayerStatistic::create([
                'match_id'         => $match->id,
                'player_id'        => $player->id,
                'team_id'          => $team->id,
                'data_source_id'   => $this->ds->id,
                'games_substitute' => false,
                'games_minutes'    => 90,
            ]);
        }
    }
}
