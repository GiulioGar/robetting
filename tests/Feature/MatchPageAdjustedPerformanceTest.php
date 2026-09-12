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
 * Integration tests for the Adjusted Performance (E10) Blade rendering.
 *
 * All numeric assertions on "adjusted > raw" / "adjusted < raw" / "adjusted == raw"
 * use assertViewHas() on the computed data rather than HTML parsing, since the
 * direction depends on exact Elo values and round-trip string comparison is fragile.
 *
 * Blade-rendering tests check labels, structure, null handling, and coverage format.
 *
 * Tests:
 *  [A]  Page loads without error (HTTP 200)
 *  [B]  homeAdjustedPerformance present in view with expected keys
 *  [C]  awayAdjustedPerformance present in view with expected keys
 *  [D]  Section heading rendered
 *  [E]  Tab IDs rendered: ap-5-tab, ap-10-tab, ap-venue-tab
 *  [F]  Row labels rendered: "grezzo", "corretto", "correzione media"
 *  [G]  Metric group labels rendered: Differenziale gol / tiri / tiri in porta
 *  [H]  Zero prior matches → "Dati precedenti non disponibili." rendered
 *  [I]  Null shot/sot stat → "N/D" rendered
 *  [J]  Goal diff always rendered (derived from FT score, never N/D when matches exist)
 *  [K]  Coverage rendered as X / Y format
 *  [L]  Venue tab: "in casa" for home, "in trasferta" for away
 *  [M]  Strong opponent (high Elo) → adjusted_avg > raw_avg in view data
 *  [N]  Weak opponent (low Elo) → adjusted_avg < raw_avg in view data
 *  [O]  No-history opponent (Elo = mean) → adjusted_avg == raw_avg in view data
 *  [P]  No synthetic score, probability, prediction, or structural-adjustment text
 */
class MatchPageAdjustedPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;
    private Team          $homeTeam;
    private Team          $awayTeam;
    private Competition   $comp;
    private Season        $season;

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

    private function createPriorMatch(
        int    $homeTeamId,
        int    $awayTeamId,
        int    $daysAgo,
        int    $homeScore = 1,
        int    $awayScore = 0
    ): FootballMatch {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $homeTeamId,
            'away_team_id'   => $awayTeamId,
            'kickoff_at'     => Carbon::parse(self::TARGET)->subDays($daysAgo),
            'status'         => 'finished',
            'home_score_ft'  => $homeScore,
            'away_score_ft'  => $awayScore,
        ]);
    }

    private function createStat(int $matchId, ?int $homeShots, ?int $awayShots, ?int $homeSot, ?int $awaySot, DataSource $ds): void
    {
        MatchStatistic::create([
            'match_id'             => $matchId,
            'data_source_id'       => $ds->id,
            'home_shots'           => $homeShots,
            'away_shots'           => $awayShots,
            'home_shots_on_target' => $homeSot,
            'away_shots_on_target' => $awaySot,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [A] Page loads without error
    // ─────────────────────────────────────────────────────────────────────────

    public function test_page_loads_successfully(): void
    {
        $this->get("/matches/{$this->match->id}")->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] homeAdjustedPerformance in view with expected keys
    // ─────────────────────────────────────────────────────────────────────────

    public function test_home_adjusted_performance_present_in_view(): void
    {
        $response = $this->get("/matches/{$this->match->id}");
        $response->assertViewHas('homeAdjustedPerformance');

        $ap = $response->viewData('homeAdjustedPerformance');
        $this->assertArrayHasKey('last5',  $ap);
        $this->assertArrayHasKey('last10', $ap);
        $this->assertArrayHasKey('venue',  $ap);

        $expectedKeys = [
            'matches_considered',
            'goal_diff_raw_avg', 'goal_diff_adjusted_avg', 'goal_diff_avg_adjustment', 'goal_diff_coverage',
            'shot_diff_raw_avg', 'shot_diff_adjusted_avg', 'shot_diff_avg_adjustment', 'shot_diff_coverage',
            'sot_diff_raw_avg',  'sot_diff_adjusted_avg',  'sot_diff_avg_adjustment',  'sot_diff_coverage',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $ap['last5'], "Missing key [{$key}] in last5");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] awayAdjustedPerformance in view with expected keys
    // ─────────────────────────────────────────────────────────────────────────

    public function test_away_adjusted_performance_present_in_view(): void
    {
        $response = $this->get("/matches/{$this->match->id}");
        $response->assertViewHas('awayAdjustedPerformance');

        $ap = $response->viewData('awayAdjustedPerformance');
        $this->assertArrayHasKey('last5',  $ap);
        $this->assertArrayHasKey('last10', $ap);
        $this->assertArrayHasKey('venue',  $ap);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] Section heading rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_heading_rendered(): void
    {
        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('adjusted-performance-section', false);
        $response->assertSee('Performance corretta per qualit');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] Tab IDs rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_tab_ids_rendered(): void
    {
        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('ap-5-tab',    false);
        $response->assertSee('ap-10-tab',   false);
        $response->assertSee('ap-venue-tab', false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] Row labels rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_row_labels_rendered(): void
    {
        // Need a prior match so the table panel renders (not "Dati non disponibili").
        $opponent = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);
        $this->createPriorMatch($this->homeTeam->id, $opponent->id, 7);

        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('· grezzo');
        $response->assertSee('· corretto');
        $response->assertSee('· correzione media');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] Metric group labels rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_metric_group_labels_rendered(): void
    {
        // Need a prior match so the table panel renders.
        $opponent = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);
        $this->createPriorMatch($this->homeTeam->id, $opponent->id, 7);

        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('Differenziale gol');
        $response->assertSee('Differenziale tiri');
        $response->assertSee('Differenziale tiri in porta');
        $response->assertSee('Copertura statistiche');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] Zero prior matches → "Dati precedenti non disponibili."
    // ─────────────────────────────────────────────────────────────────────────

    public function test_zero_matches_shows_no_data_message(): void
    {
        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('Dati precedenti non disponibili.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] Null shot/sot stat → "N/D" rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_stat_renders_nd(): void
    {
        $opponent = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);

        // Prior match exists but NO stat row → shot/sot will be null
        $this->createPriorMatch($this->homeTeam->id, $opponent->id, 7, 2, 1);

        $response = $this->get("/matches/{$this->match->id}");
        $response->assertStatus(200);
        $response->assertSee('N/D');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J] Goal diff always rendered when matches exist (FT score, never N/D)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_goal_diff_rendered_without_stat_row(): void
    {
        $opponent = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);
        $this->createPriorMatch($this->homeTeam->id, $opponent->id, 7, 2, 0);

        $ap = $this->get("/matches/{$this->match->id}")->viewData('homeAdjustedPerformance');

        // goal_diff is always computable from FT score
        $this->assertNotNull($ap['last5']['goal_diff_raw_avg']);
        $this->assertNotNull($ap['last5']['goal_diff_adjusted_avg']);
        $this->assertSame(1, $ap['last5']['goal_diff_coverage']);

        // shot/sot are null (no stat row)
        $this->assertNull($ap['last5']['shot_diff_raw_avg']);
        $this->assertNull($ap['last5']['sot_diff_raw_avg']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [K] Coverage rendered as X / Y format
    // ─────────────────────────────────────────────────────────────────────────

    public function test_coverage_rendered_as_x_slash_y(): void
    {
        $ds       = DataSource::create(['name' => 'FDO', 'slug' => 'football-data', 'source_type' => 'csv']);
        $opponent = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);

        // 2 prior matches; only 1 has a stat row
        $m1 = $this->createPriorMatch($this->homeTeam->id, $opponent->id, 14, 1, 0);
        $m2 = $this->createPriorMatch($this->homeTeam->id, $opponent->id,  7, 2, 1);
        $this->createStat($m1->id, 6, 4, 3, 2, $ds);
        // m2 has no stat row

        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('1 / 2');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [L] Venue tab: "in casa" for home team, "in trasferta" for away team
    // ─────────────────────────────────────────────────────────────────────────

    public function test_venue_tab_shows_correct_venue_labels(): void
    {
        $opponent = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);

        // 3 home matches for homeTeam, 2 away matches for awayTeam
        for ($i = 1; $i <= 3; $i++) {
            $this->createPriorMatch($this->homeTeam->id, $opponent->id, $i * 7);
        }
        for ($i = 1; $i <= 2; $i++) {
            $this->createPriorMatch($opponent->id, $this->awayTeam->id, $i * 7 + 1);
        }

        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('in casa');
        $response->assertSee('in trasferta');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [M] Strong opponent → adjusted_avg > raw_avg
    //
    // Strategy: create a "strong" opponent that has won many games → Elo >> 1500.
    // No teams attached to season → league mean fallback = INITIAL_ELO = 1500.
    // opponent_elo_delta = opponent_elo - 1500 > 0.
    // For goal_diff: positive delta → adjusted pushed upward → adjusted > raw.
    //
    // "Strong" = wins many games against a patsy (TeamC) before the target window.
    // In target window: home team plays this strong opponent → adjusted > raw.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_strong_opponent_adjusted_exceeds_raw(): void
    {
        $strongOpponent = Team::create(['name' => 'Juventus',  'type' => 'club', 'is_active' => true]);
        $patsy          = Team::create(['name' => 'Patsy',     'type' => 'club', 'is_active' => true]);

        // Strong opponent wins 6 games as home team (patsy always away) → Elo rises well above 1500.
        for ($i = 1; $i <= 6; $i++) {
            FootballMatch::create([
                'competition_id' => $this->comp->id,
                'season_id'      => $this->season->id,
                'home_team_id'   => $strongOpponent->id,
                'away_team_id'   => $patsy->id,
                'kickoff_at'     => Carbon::parse(self::TARGET)->subDays(100 + $i * 7),
                'status'         => 'finished',
                'home_score_ft'  => 2,
                'away_score_ft'  => 0,
            ]);
        }

        // Home team plays the strong opponent in a prior match (homeTeam as home).
        $this->createPriorMatch($this->homeTeam->id, $strongOpponent->id, 14, 1, 2);

        $ap = $this->get("/matches/{$this->match->id}")->viewData('homeAdjustedPerformance');

        // opponent_elo_delta > 0 → goal_diff_adjusted_avg > goal_diff_raw_avg
        $raw = $ap['last5']['goal_diff_raw_avg'];
        $adj = $ap['last5']['goal_diff_adjusted_avg'];

        $this->assertNotNull($raw);
        $this->assertNotNull($adj);
        $this->assertGreaterThan($raw, $adj,
            'Adjusted goal diff should exceed raw when opponent is stronger than league mean');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [N] Weak opponent → adjusted_avg < raw_avg
    //
    // Weak opponent lost many games → Elo << 1500.
    // opponent_elo_delta < 0 → adjusted is pulled downward.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_weak_opponent_adjusted_below_raw(): void
    {
        $weakOpponent = Team::create(['name' => 'Weakling', 'type' => 'club', 'is_active' => true]);
        $strong       = Team::create(['name' => 'Strong',   'type' => 'club', 'is_active' => true]);

        // Weak opponent loses 6 games as away team → Elo drops well below 1500.
        for ($i = 1; $i <= 6; $i++) {
            FootballMatch::create([
                'competition_id' => $this->comp->id,
                'season_id'      => $this->season->id,
                'home_team_id'   => $strong->id,
                'away_team_id'   => $weakOpponent->id,
                'kickoff_at'     => Carbon::parse(self::TARGET)->subDays(100 + $i * 7),
                'status'         => 'finished',
                'home_score_ft'  => 3,
                'away_score_ft'  => 0,
            ]);
        }

        // Home team beats the weak opponent easily.
        $this->createPriorMatch($this->homeTeam->id, $weakOpponent->id, 14, 3, 0);

        $ap = $this->get("/matches/{$this->match->id}")->viewData('homeAdjustedPerformance');

        // opponent_elo_delta < 0 → goal_diff_adjusted_avg < goal_diff_raw_avg
        $raw = $ap['last5']['goal_diff_raw_avg'];
        $adj = $ap['last5']['goal_diff_adjusted_avg'];

        $this->assertNotNull($raw);
        $this->assertNotNull($adj);
        $this->assertLessThan($raw, $adj,
            'Adjusted goal diff should be less than raw when opponent is weaker than league mean');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [O] No-history opponent → delta = 0 → adjusted == raw
    //
    // No-history opponent has Elo = INITIAL_ELO = league mean (no teams in season).
    // opponent_elo_delta = 0 → adjusted = raw exactly.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_history_opponent_adjusted_equals_raw(): void
    {
        $freshOpponent = Team::create(['name' => 'NewTeam', 'type' => 'club', 'is_active' => true]);

        // No prior Elo history for freshOpponent → Elo = 1500 = league mean.
        $this->createPriorMatch($this->homeTeam->id, $freshOpponent->id, 7, 2, 1);

        $ap = $this->get("/matches/{$this->match->id}")->viewData('homeAdjustedPerformance');

        $raw = $ap['last5']['goal_diff_raw_avg'];
        $adj = $ap['last5']['goal_diff_adjusted_avg'];

        $this->assertNotNull($raw);
        $this->assertNotNull($adj);
        $this->assertEqualsWithDelta($raw, $adj, 0.0001,
            'When opponent Elo equals league mean, adjusted must equal raw');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [P] No synthetic score, probability, prediction, or structural adjustment
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_forbidden_content_rendered(): void
    {
        $response = $this->get("/matches/{$this->match->id}");

        $response->assertDontSee('score sintetico');
        $response->assertDontSee('opponent score');
        $response->assertDontSee('probabilit');
        $response->assertDontSee('predizion');
        $response->assertDontSee('structural adjustment');
        $response->assertDontSee('adjusted structural');
        $response->assertDontSee('combined');
    }
}
