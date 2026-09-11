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
 * Verifies the "Prestazione recente" (E8) section on /matches/{match}.
 *
 * The section is always visible (not gated by match status) and contains
 * three tab panels: Ultime 5, Ultime 10, Sede del match.
 *
 * All values come from TeamAnalyticsCalculator; the blade performs NO
 * re-calculation — it only reads and formats.
 *
 * Tests:
 *  [01] Section heading "Prestazione recente" always visible
 *  [02] "Ultime 5" tab visible
 *  [03] "Ultime 10" tab visible
 *  [04] "Ultime 5 in casa" label visible in Sede del match panel (home team)
 *  [05] "Ultime 5 in trasferta" label visible in Sede del match panel (away team)
 *  [06] avg_goals_for rendered correctly (Ultime 5)
 *  [07] avg_goals_against rendered correctly
 *  [08] avg_shots_for rendered correctly when stats present
 *  [09] avg_shots_against rendered correctly when stats present
 *  [10] avg_shots_on_target_for / against rendered correctly
 *  [11] goal_diff_per_match rendered with sign (+/-)
 *  [12] avg_shot_diff rendered with sign
 *  [13] avg_shots_on_target_diff rendered with sign
 *  [14] clean_sheets shown as "X / Y" with real denominator
 *  [15] failed_to_score shown as "X / Y" with real denominator
 *  [16] venue panel: fewer than 5 matches → uses real matches_played as denominator
 *  [17] null technical metric (no match_statistics) → "N/D" rendered
 *  [18] zero matches (no prior history) → "Dati precedenti non disponibili"
 *  [19] section visible on finished match (not gated)
 *  [20] no form score / combined strength / probability in section
 */
class MatchPageRecentPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;
    private Team $homeTeam;
    private Team $awayTeam;
    private Competition $comp;
    private Season $season;
    private DataSource $ds;

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

        $this->ds = DataSource::create([
            'slug'        => 'api-football',
            'name'        => 'Api Football',
            'source_type' => 'api',
        ]);

        $this->match = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET, 'UTC'),
            'status'         => 'scheduled',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function played(Team $home, Team $away, string $kickoff, int $hs, int $as): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $home->id,
            'away_team_id'   => $away->id,
            'kickoff_at'     => Carbon::parse($kickoff, 'UTC'),
            'status'         => 'finished',
            'home_score_ft'  => $hs,
            'away_score_ft'  => $as,
        ]);
    }

    private function addStats(FootballMatch $m, array $data): void
    {
        MatchStatistic::create(array_merge([
            'match_id'       => $m->id,
            'data_source_id' => $this->ds->id,
        ], $data));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [01] Section heading always visible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_heading_visible(): void
    {
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();
        $response->assertSee('Prestazione recente');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [02] "Ultime 5" tab visible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ultime_5_tab_visible(): void
    {
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();
        $response->assertSee('Ultime 5');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [03] "Ultime 10" tab visible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ultime_10_tab_visible(): void
    {
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();
        $response->assertSee('Ultime 10');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [04] "in casa" label for HOME team in Sede del match
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ultime_in_casa_label_visible(): void
    {
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();
        $response->assertSee('in casa');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [05] "Sede del match" tab always visible; "in trasf." column header
    //      appears only when there are venue data — verified in test [16].
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sede_del_match_tab_visible(): void
    {
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();
        $response->assertSee('Sede del match');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [06] avg_goals_for rendered correctly
    // Home team wins 2-0 and 4-0 → avg_goals_for = 3.00
    // ─────────────────────────────────────────────────────────────────────────

    public function test_avg_goals_for_rendered(): void
    {
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-01 20:00:00', 2, 0);
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-08 20:00:00', 4, 0);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('homeLast5Analytics');
        $this->assertSame(3.0, $data['summary']['avg_goals_for']);
        $response->assertSee('3.00'); // blade renders with 2 decimals
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [07] avg_goals_against rendered correctly
    // Milan plays 2 away matches, conceding 3 and 1 → avg_goals_against = 2.00
    // ─────────────────────────────────────────────────────────────────────────

    public function test_avg_goals_against_rendered(): void
    {
        // Inter (homeTeam) hosts Milan (awayTeam) twice: Milan concedes 3 then 1
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-08 20:00:00', 3, 0);
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-15 20:00:00', 1, 0);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('awayLast5Analytics');
        // Milan (away): goals_against = 3+1=4, played=2 → avg_goals_against = 2.00
        $this->assertSame(2.0, $data['summary']['avg_goals_against']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [08] avg_shots_for rendered when stats present
    // ─────────────────────────────────────────────────────────────────────────

    public function test_avg_shots_for_rendered_with_stats(): void
    {
        $m1 = $this->played($this->homeTeam, $this->awayTeam, '2026-08-01 20:00:00', 1, 0);
        $m2 = $this->played($this->homeTeam, $this->awayTeam, '2026-08-08 20:00:00', 2, 1);

        $this->addStats($m1, ['home_shots' => 12, 'away_shots' => 6, 'home_shots_on_target' => 5, 'away_shots_on_target' => 2, 'home_corners' => 6, 'away_corners' => 3, 'home_fouls' => 10, 'away_fouls' => 12]);
        $this->addStats($m2, ['home_shots' => 8,  'away_shots' => 4, 'home_shots_on_target' => 3, 'away_shots_on_target' => 1, 'home_corners' => 4, 'away_corners' => 2, 'home_fouls' => 8,  'away_fouls' => 10]);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('homeLast5Analytics');
        // avg_shots_for = (12+8)/2 = 10.00
        $this->assertSame(10.0, $data['technical']['avg_shots_for']);
        $response->assertSee('10.00');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [09] avg_shots_against rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_avg_shots_against_rendered(): void
    {
        $m1 = $this->played($this->homeTeam, $this->awayTeam, '2026-08-01 20:00:00', 1, 0);
        $this->addStats($m1, ['home_shots' => 10, 'away_shots' => 4, 'home_shots_on_target' => 4, 'away_shots_on_target' => 2, 'home_corners' => 5, 'away_corners' => 3, 'home_fouls' => 10, 'away_fouls' => 10]);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('homeLast5Analytics');
        $this->assertSame(4.0, $data['technical']['avg_shots_against']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [10] avg_shots_on_target for/against rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_avg_shots_on_target_rendered(): void
    {
        $m1 = $this->played($this->homeTeam, $this->awayTeam, '2026-08-01 20:00:00', 1, 0);
        $this->addStats($m1, ['home_shots' => 10, 'away_shots' => 6, 'home_shots_on_target' => 6, 'away_shots_on_target' => 2, 'home_corners' => 5, 'away_corners' => 3, 'home_fouls' => 10, 'away_fouls' => 10]);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('homeLast5Analytics');
        $this->assertSame(6.0, $data['technical']['avg_shots_on_target_for']);
        $this->assertSame(2.0, $data['technical']['avg_shots_on_target_against']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [11] goal_diff_per_match rendered with sign
    // Home wins 3-0 and 1-0 → avg_gf=2, avg_ga=0 → gd/m = +2.00
    // ─────────────────────────────────────────────────────────────────────────

    public function test_goal_diff_per_match_rendered_with_sign(): void
    {
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-01 20:00:00', 3, 0);
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-08 20:00:00', 1, 0);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('homeLast5Analytics');
        $this->assertSame(2.0, $data['summary']['goal_diff_per_match']);
        $response->assertSee('+2.00');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [12] avg_shot_diff rendered with sign
    // ─────────────────────────────────────────────────────────────────────────

    public function test_avg_shot_diff_rendered_with_sign(): void
    {
        $m1 = $this->played($this->homeTeam, $this->awayTeam, '2026-08-01 20:00:00', 1, 0);
        $this->addStats($m1, ['home_shots' => 12, 'away_shots' => 6, 'home_shots_on_target' => 5, 'away_shots_on_target' => 2, 'home_corners' => 5, 'away_corners' => 3, 'home_fouls' => 10, 'away_fouls' => 10]);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('homeLast5Analytics');
        $this->assertSame(6.0, $data['technical']['avg_shot_diff']);
        $response->assertSee('+6.00');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [13] avg_shots_on_target_diff rendered with sign
    // ─────────────────────────────────────────────────────────────────────────

    public function test_avg_shots_on_target_diff_rendered_with_sign(): void
    {
        $m1 = $this->played($this->homeTeam, $this->awayTeam, '2026-08-01 20:00:00', 1, 0);
        $this->addStats($m1, ['home_shots' => 10, 'away_shots' => 6, 'home_shots_on_target' => 5, 'away_shots_on_target' => 1, 'home_corners' => 5, 'away_corners' => 3, 'home_fouls' => 10, 'away_fouls' => 10]);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('homeLast5Analytics');
        $this->assertSame(4.0, $data['technical']['avg_shots_on_target_diff']);
        $response->assertSee('+4.00');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [14] clean_sheets shown as "X / Y" with real denominator
    // 3 wins 2-0, 1 loss 0-1 → played=4, clean_sheets=3 → "3 / 4"
    // ─────────────────────────────────────────────────────────────────────────

    public function test_clean_sheets_shown_as_fraction(): void
    {
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-01 20:00:00', 2, 0);
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-08 20:00:00', 2, 0);
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-15 20:00:00', 2, 0);
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-22 20:00:00', 0, 1);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('homeLast5Analytics');
        $this->assertSame(3, $data['summary']['clean_sheets']);
        $this->assertSame(4, $data['summary']['matches_played']);
        $response->assertSee('3 / 4');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [15] failed_to_score shown as "X / Y" with real denominator
    // 1 match scored 0 goals, 2 matches scored → failed=1, played=3 → "1 / 3"
    // ─────────────────────────────────────────────────────────────────────────

    public function test_failed_to_score_shown_as_fraction(): void
    {
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-01 20:00:00', 0, 2);
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-08 20:00:00', 1, 1);
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-15 20:00:00', 2, 0);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('homeLast5Analytics');
        $this->assertSame(1, $data['summary']['failed_to_score']);
        $response->assertSee('1 / 3');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [16] venue panel: fewer than 5 home matches → real matches_played shown
    // 2 home matches for homeTeam, 3 away for awayTeam → denominators are 2 and 3
    // ─────────────────────────────────────────────────────────────────────────

    public function test_venue_panel_uses_real_matches_played_as_denominator(): void
    {
        $thirdTeam = Team::create(['name' => 'Juve', 'type' => 'club', 'is_active' => true]);

        // 2 home matches for Inter (homeTeam) — Inter hosts thirdTeam
        $this->played($this->homeTeam, $thirdTeam,      '2026-08-01 20:00:00', 1, 0);
        $this->played($this->homeTeam, $thirdTeam,      '2026-08-08 20:00:00', 2, 0);

        // 3 away matches for Milan (awayTeam) — Milan is AWAY at thirdTeam's ground
        $this->played($thirdTeam,      $this->awayTeam, '2026-08-15 20:00:00', 0, 1);
        $this->played($thirdTeam,      $this->awayTeam, '2026-08-22 20:00:00', 1, 2);
        $this->played($thirdTeam,      $this->awayTeam, '2026-08-29 20:00:00', 0, 3);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $hVenue = $response->viewData('homeRecentHomeAnalytics');
        $aVenue = $response->viewData('awayRecentAwayAnalytics');

        $this->assertSame(2, $hVenue['summary']['matches_played']);
        $this->assertSame(3, $aVenue['summary']['matches_played']);

        // Table headers with real denominators visible in venue panel
        $response->assertSee('in casa');
        $response->assertSee('in trasf.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [17] null technical metric → "N/D" in blade output
    // No match_statistics → avg_shots_for = null → blade shows N/D
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_metric_shows_nd(): void
    {
        // Match played but no MatchStatistic record → technical averages all null
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-01 20:00:00', 1, 0);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('homeLast5Analytics');
        $this->assertNull($data['technical']['avg_shots_for']);

        // All null metrics in E8 render as 'N/D' ($rtpAvg and $rtpDiff both return 'N/D' for null)
        $response->assertSee('N/D');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [18] zero matches → "Dati precedenti non disponibili"
    // ─────────────────────────────────────────────────────────────────────────

    public function test_zero_matches_shows_no_data_message(): void
    {
        // No prior matches → both teams have 0 played
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();
        $response->assertSee('Dati precedenti non disponibili');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [19] section visible on finished match (not gated by status)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_visible_on_finished_match(): void
    {
        $finishedMatch = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse('2026-08-01 20:00:00', 'UTC'),
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 1,
        ]);

        $response = $this->get(route('matches.show', $finishedMatch));
        $response->assertOk();
        $response->assertSee('Prestazione recente');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [20] no form score / combined strength / probability
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_score_combined_or_probability_in_section(): void
    {
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();
        $response->assertDontSee('form score');
        $response->assertDontSee('combined');
        $response->assertDontSee('probabilit');
        $response->assertDontSee('Forza complessiva');
    }
}
