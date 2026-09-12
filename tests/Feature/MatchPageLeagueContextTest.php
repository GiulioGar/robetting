<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for the League Context (E11) Blade rendering.
 *
 * Tests:
 *  [A]  Page loads without error (HTTP 200)
 *  [B]  leagueContext present in view with all expected keys
 *  [C]  Section heading "Contesto campionato" rendered
 *  [D]  Section ID "league-context-section" in HTML
 *  [E]  Zero prior matches → "Dati campionato precedenti non disponibili." shown
 *  [F]  With prior matches: matches_considered visible in page
 *  [G]  avg_goals_per_match, avg_home_goals, avg_away_goals visible
 *  [H]  home_win_rate, draw_rate, away_win_rate rendered as percentages
 *  [I]  home_vs_away_goal_diff rendered
 *  [J]  No stat data → shots section not shown (null values hidden)
 *  [K]  With stat data → shots rows visible
 *  [L]  View data: leagueContext matches_considered equals count of competition prior matches
 *  [M]  Target match itself is excluded from league context
 *  [N]  No prediction, probability, or forecast text in section
 */
class MatchPageLeagueContextTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;
    private Team          $homeTeam;
    private Team          $awayTeam;
    private Competition   $comp;
    private Season        $season;
    private DataSource    $ds;

    private const TARGET = '2026-09-25 20:45:00';

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->ds       = DataSource::create([
            'name'        => 'API-Football',
            'slug'        => 'api-football',
            'source_type' => 'api',
        ]);

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
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function createLeagueMatch(
        Team $home,
        Team $away,
        int  $daysAgo,
        int  $homeScore = 1,
        int  $awayScore = 0
    ): FootballMatch {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $home->id,
            'away_team_id'   => $away->id,
            'kickoff_at'     => Carbon::parse(self::TARGET)->subDays($daysAgo),
            'status'         => 'finished',
            'home_score_ft'  => $homeScore,
            'away_score_ft'  => $awayScore,
        ]);
    }

    private function addStat(FootballMatch $m, int $hs, int $as, ?int $hsot = null, ?int $asot = null): void
    {
        MatchStatistic::create([
            'match_id'             => $m->id,
            'data_source_id'       => $this->ds->id,
            'home_shots'           => $hs,
            'away_shots'           => $as,
            'home_shots_on_target' => $hsot,
            'away_shots_on_target' => $asot,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [A] Page loads
    // ─────────────────────────────────────────────────────────────────────────

    public function test_page_loads_successfully(): void
    {
        $this->get("/matches/{$this->match->id}")->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] leagueContext in view with expected keys
    // ─────────────────────────────────────────────────────────────────────────

    public function test_league_context_present_in_view_with_expected_keys(): void
    {
        $response = $this->get("/matches/{$this->match->id}");
        $response->assertViewHas('leagueContext');

        $lc = $response->viewData('leagueContext');
        foreach ([
            'matches_considered', 'avg_goals_per_match',
            'avg_home_goals', 'avg_away_goals',
            'home_win_rate', 'draw_rate', 'away_win_rate',
            'home_vs_away_goal_diff',
            'avg_home_shots', 'avg_away_shots',
            'avg_home_shots_on_target', 'avg_away_shots_on_target',
            'shots_coverage', 'shots_on_target_coverage',
        ] as $key) {
            $this->assertArrayHasKey($key, $lc, "Missing key: $key");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] Section heading "Contesto campionato" rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_heading_rendered(): void
    {
        $this->get("/matches/{$this->match->id}")
            ->assertSee('Contesto campionato');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] Section ID present in HTML
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_id_present(): void
    {
        $this->get("/matches/{$this->match->id}")
            ->assertSee('league-context-section', false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] Zero prior matches → empty-state message
    // ─────────────────────────────────────────────────────────────────────────

    public function test_zero_prior_matches_shows_unavailable_message(): void
    {
        // No competition matches before target kickoff
        $this->get("/matches/{$this->match->id}")
            ->assertSee('Dati campionato precedenti non disponibili.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] With prior matches: matches_considered visible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_with_prior_matches_shows_matches_considered(): void
    {
        $teamC = Team::create(['name' => 'Juve', 'type' => 'club', 'is_active' => true]);
        $this->createLeagueMatch($this->homeTeam, $teamC, 14, 2, 1);
        $this->createLeagueMatch($teamC, $this->awayTeam, 7, 1, 0);

        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('Partite considerate');
        $response->assertSee('2');  // 2 prior league matches
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] Goals averages visible in page
    // ─────────────────────────────────────────────────────────────────────────

    public function test_goals_averages_rendered(): void
    {
        $teamC = Team::create(['name' => 'Juve', 'type' => 'club', 'is_active' => true]);
        $this->createLeagueMatch($this->homeTeam, $teamC, 14, 2, 1);

        $this->get("/matches/{$this->match->id}")
            ->assertSee('Media gol / partita')
            ->assertSee('Media gol casa')
            ->assertSee('Media gol trasferta');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] Outcome rates rendered as percentages
    // ─────────────────────────────────────────────────────────────────────────

    public function test_outcome_rates_rendered(): void
    {
        $teamC = Team::create(['name' => 'Juve', 'type' => 'club', 'is_active' => true]);
        $this->createLeagueMatch($this->homeTeam, $teamC, 14, 1, 0);

        $this->get("/matches/{$this->match->id}")
            ->assertSee('Vittorie casa')
            ->assertSee('Pareggi')
            ->assertSee('Vittorie trasferta')
            ->assertSee('%');  // percentages rendered
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] home_vs_away_goal_diff rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_home_vs_away_goal_diff_rendered(): void
    {
        $teamC = Team::create(['name' => 'Juve', 'type' => 'club', 'is_active' => true]);
        $this->createLeagueMatch($this->homeTeam, $teamC, 14, 2, 0);

        $this->get("/matches/{$this->match->id}")
            ->assertSee('Differenziale casa/trasferta');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J] No stat data → shots section not rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_stat_data_hides_shots_section(): void
    {
        $teamC = Team::create(['name' => 'Juve', 'type' => 'club', 'is_active' => true]);
        $this->createLeagueMatch($this->homeTeam, $teamC, 14, 2, 1);
        // No MatchStatistic rows created

        $this->get("/matches/{$this->match->id}")
            ->assertDontSee('Media tiri casa');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [K] With stat data → shots section visible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_with_stat_data_shows_shots_section(): void
    {
        $teamC = Team::create(['name' => 'Juve', 'type' => 'club', 'is_active' => true]);
        $m     = $this->createLeagueMatch($this->homeTeam, $teamC, 14, 2, 1);
        $this->addStat($m, 12, 8);

        $this->get("/matches/{$this->match->id}")
            ->assertSee('Media tiri casa')
            ->assertSee('Media tiri trasferta');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [L] View data: matches_considered reflects all competition prior matches
    // ─────────────────────────────────────────────────────────────────────────

    public function test_view_data_matches_considered_counts_all_league_matches(): void
    {
        $teamC = Team::create(['name' => 'Juve', 'type' => 'club', 'is_active' => true]);
        $teamD = Team::create(['name' => 'Roma', 'type' => 'club', 'is_active' => true]);

        // 4 league matches before target kickoff (none involving home/away teams directly)
        $this->createLeagueMatch($teamC, $teamD, 21, 1, 0);
        $this->createLeagueMatch($teamD, $teamC, 14, 2, 2);
        $this->createLeagueMatch($teamC, $this->homeTeam, 7, 0, 1);
        $this->createLeagueMatch($this->awayTeam, $teamD, 3, 1, 1);

        $response = $this->get("/matches/{$this->match->id}");
        $lc = $response->viewData('leagueContext');

        $this->assertSame(4, $lc['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [M] Target match itself excluded from league context
    // ─────────────────────────────────────────────────────────────────────────

    public function test_target_match_excluded_from_league_context(): void
    {
        // The target match has kickoff_at = TARGET — same kickoff means it is NOT < TARGET
        // Only prior matches count
        $teamC = Team::create(['name' => 'Juve', 'type' => 'club', 'is_active' => true]);
        $this->createLeagueMatch($teamC, $this->homeTeam, 7, 1, 1);

        $response = $this->get("/matches/{$this->match->id}");
        $lc = $response->viewData('leagueContext');

        // Only the prior league match is counted; the target match itself is NOT included
        $this->assertSame(1, $lc['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [N] No prediction/probability text in section
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_prediction_probability_text(): void
    {
        $teamC = Team::create(['name' => 'Juve', 'type' => 'club', 'is_active' => true]);
        $this->createLeagueMatch($teamC, $this->homeTeam, 7, 2, 0);

        $html = $this->get("/matches/{$this->match->id}")->getContent();

        // Extract only the league-context section to avoid false positives from other sections
        $start = strpos($html, 'league-context-section');
        $end   = strpos($html, 'id="', $start + 100);  // next section start
        $section = $end !== false ? substr($html, $start, $end - $start) : substr($html, $start);

        $this->assertStringNotContainsStringIgnoringCase('probabilit', $section);
        $this->assertStringNotContainsStringIgnoringCase('previsione', $section);
        $this->assertStringNotContainsStringIgnoringCase('pronostico', $section);
        $this->assertStringNotContainsStringIgnoringCase('forecast', $section);
    }
}
