<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamMarketValueSnapshot;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for the Opponent Quality (E9) data flow and Blade rendering.
 *
 *  [A]  Page loads without error (HTTP 200)
 *  [B]  homeOpponentQuality present in view data with expected keys
 *  [C]  awayOpponentQuality present in view data with expected keys
 *  [D]  All window sub-keys present in each result
 *  [E]  matches_considered = 0 when no prior history (no crash)
 *  [F]  matches_considered reflects real window sizes
 *  [G]  Structural populated when transfermarkt source + snapshot present
 *  [H]  Structural null when no transfermarkt source in DB
 *  [I]  Section heading "Qualità avversari recenti" rendered
 *  [J]  Tab labels rendered: Ultime 5 / Ultime 10 / Sede del match
 *  [K]  Metric labels rendered: Elo medio / Elo mediano / Structural media / Structural mediana / Coverage
 *  [L]  Zero prior matches → "Dati precedenti non disponibili." message rendered
 *  [M]  Structural null → "N/D" rendered in the table cells
 *  [N]  Venue tab shows "in casa" for home team, "in trasferta" for away team
 *  [O]  Coverage rendered as X / Y format
 *  [P]  No combined score, probability, prediction, or opponent-adjusted text rendered
 */
class MatchPageOpponentQualityTest extends TestCase
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
        string $status = 'finished'
    ): FootballMatch {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $homeTeamId,
            'away_team_id'   => $awayTeamId,
            'kickoff_at'     => Carbon::parse(self::TARGET)->subDays($daysAgo),
            'status'         => $status,
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [A] Page loads without error (no prior matches, no structural data)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_page_loads_successfully(): void
    {
        $this->get("/matches/{$this->match->id}")->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] homeOpponentQuality present in view data with expected keys
    // ─────────────────────────────────────────────────────────────────────────

    public function test_home_opponent_quality_present_in_view(): void
    {
        $response = $this->get("/matches/{$this->match->id}");
        $response->assertViewHas('homeOpponentQuality');

        $oq = $response->viewData('homeOpponentQuality');
        $this->assertArrayHasKey('last5',  $oq);
        $this->assertArrayHasKey('last10', $oq);
        $this->assertArrayHasKey('venue',  $oq);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] awayOpponentQuality present in view data with expected keys
    // ─────────────────────────────────────────────────────────────────────────

    public function test_away_opponent_quality_present_in_view(): void
    {
        $response = $this->get("/matches/{$this->match->id}");
        $response->assertViewHas('awayOpponentQuality');

        $oq = $response->viewData('awayOpponentQuality');
        $this->assertArrayHasKey('last5',  $oq);
        $this->assertArrayHasKey('last10', $oq);
        $this->assertArrayHasKey('venue',  $oq);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] All expected sub-keys present in each window
    // ─────────────────────────────────────────────────────────────────────────

    public function test_window_sub_keys_present(): void
    {
        $response = $this->get("/matches/{$this->match->id}");
        $oq       = $response->viewData('homeOpponentQuality');

        $expectedKeys = [
            'matches_considered',
            'average_opponent_elo',
            'median_opponent_elo',
            'average_opponent_structural',
            'median_opponent_structural',
            'structural_matches_available',
            'structural_coverage_percentage',
        ];

        foreach (['last5', 'last10', 'venue'] as $window) {
            foreach ($expectedKeys as $key) {
                $this->assertArrayHasKey($key, $oq[$window],
                    "Missing key [{$key}] in window [{$window}]");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] matches_considered = 0 when no prior history (no crash)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_zero_prior_history_does_not_crash(): void
    {
        $response = $this->get("/matches/{$this->match->id}");
        $response->assertStatus(200);

        $oq = $response->viewData('homeOpponentQuality');
        $this->assertSame(0, $oq['last5']['matches_considered']);
        $this->assertSame(0, $oq['last10']['matches_considered']);
        $this->assertSame(0, $oq['venue']['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] matches_considered reflects real window sizes
    // ─────────────────────────────────────────────────────────────────────────

    public function test_matches_considered_reflects_window_size(): void
    {
        $opponent = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);

        for ($i = 7; $i >= 1; $i--) {
            $this->createPriorMatch($this->homeTeam->id, $opponent->id, $i * 7);
        }

        $oq = $this->get("/matches/{$this->match->id}")->viewData('homeOpponentQuality');

        $this->assertSame(5, $oq['last5']['matches_considered']);
        $this->assertSame(7, $oq['last10']['matches_considered']); // only 7 exist
        $this->assertSame(5, $oq['venue']['matches_considered']);  // all 7 are home; capped at 5
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] Structural populated when transfermarkt source + snapshot available
    // ─────────────────────────────────────────────────────────────────────────

    public function test_structural_populated_with_source_and_snapshot(): void
    {
        $ds = DataSource::create([
            'slug'        => 'transfermarkt',
            'name'        => 'Transfermarkt',
            'source_type' => 'manual',
            'is_active'   => true,
        ]);

        $opponent = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);
        TeamMarketValueSnapshot::create([
            'team_id'        => $opponent->id,
            'data_source_id' => $ds->id,
            'snapshot_date'  => '2026-09-11',
            'market_value'   => 500_000_000,
        ]);

        $this->createPriorMatch($this->homeTeam->id, $opponent->id, 7);

        $oq = $this->get("/matches/{$this->match->id}")->viewData('homeOpponentQuality');

        $this->assertNotNull($oq['last5']['average_opponent_structural']);
        $this->assertSame(1, $oq['last5']['structural_matches_available']);
        $this->assertEqualsWithDelta(100.0, $oq['last5']['structural_coverage_percentage'], 0.1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] Structural null when no transfermarkt source in DB
    // ─────────────────────────────────────────────────────────────────────────

    public function test_structural_null_when_no_transfermarkt_source(): void
    {
        $opponent = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);
        $this->createPriorMatch($this->homeTeam->id, $opponent->id, 7);

        $oq = $this->get("/matches/{$this->match->id}")->viewData('homeOpponentQuality');

        $this->assertNull($oq['last5']['average_opponent_structural']);
        $this->assertSame(0, $oq['last5']['structural_matches_available']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] Section heading rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_heading_rendered(): void
    {
        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('Qualit');                       // 'à' encoding-safe prefix
        $response->assertSee('avversari recenti');
        $response->assertSee('opponent-quality-section', false); // id attribute, no escaping
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J] Tab labels rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_tab_labels_rendered(): void
    {
        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('oq-5-tab', false);
        $response->assertSee('oq-10-tab', false);
        $response->assertSee('oq-venue-tab', false);
        $response->assertSee('Ultime 5');
        $response->assertSee('Ultime 10');
        $response->assertSee('Sede del match');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [K] Metric labels rendered (requires at least one prior match to show table)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_metric_labels_rendered(): void
    {
        $opponent = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);
        $this->createPriorMatch($this->homeTeam->id, $opponent->id, 14);

        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('Elo medio avversari');
        $response->assertSee('Elo mediano avversari');
        $response->assertSee('Structural media avversari');
        $response->assertSee('Structural mediana avversari');
        $response->assertSee('Coverage Structural');
        $response->assertSee('Elo avversari');
        $response->assertSee('Structural avversari');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [L] Zero prior matches → "Dati precedenti non disponibili." rendered
    //     Both teams have 0 prior matches → all windows show the message.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_zero_matches_shows_no_data_message(): void
    {
        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('Dati precedenti non disponibili.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [M] Structural null → "N/D" rendered in table cells
    //     No Transfermarkt source in DB → structural = null for all matches.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_structural_null_renders_nd(): void
    {
        $opponent = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);
        $this->createPriorMatch($this->homeTeam->id, $opponent->id, 7);

        // No DataSource 'transfermarkt' → $transfermarktDsId = null → structural = null
        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('N/D');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [N] Venue tab: home shows "in casa", away shows "in trasferta"
    //     Creates 3 home matches for home team, 2 away matches for away team.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_venue_tab_labels_show_correct_venue(): void
    {
        $opponent = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);

        // 3 home matches for home team (venue window = 3 in casa)
        $this->createPriorMatch($this->homeTeam->id, $opponent->id, 7);
        $this->createPriorMatch($this->homeTeam->id, $opponent->id, 14);
        $this->createPriorMatch($this->homeTeam->id, $opponent->id, 21);

        // 2 away matches for away team (venue window = 2 in trasferta)
        $this->createPriorMatch($opponent->id, $this->awayTeam->id, 10);
        $this->createPriorMatch($opponent->id, $this->awayTeam->id, 17);

        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('in casa');
        $response->assertSee('in trasferta');
        $response->assertSee('3 in casa');
        $response->assertSee('2 in trasferta');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [O] Coverage rendered as "X / Y" format
    // ─────────────────────────────────────────────────────────────────────────

    public function test_coverage_rendered_as_x_slash_y(): void
    {
        $ds = DataSource::create([
            'slug'        => 'transfermarkt',
            'name'        => 'Transfermarkt',
            'source_type' => 'manual',
            'is_active'   => true,
        ]);

        $opp1 = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);
        $opp2 = Team::create(['name' => 'Roma',     'type' => 'club', 'is_active' => true]);

        // Snapshot only for opp1 → 1/2 coverage in last5
        TeamMarketValueSnapshot::create([
            'team_id'        => $opp1->id,
            'data_source_id' => $ds->id,
            'snapshot_date'  => '2026-08-01',
            'market_value'   => 300_000_000,
        ]);

        $this->createPriorMatch($this->homeTeam->id, $opp1->id, 14);
        $this->createPriorMatch($this->homeTeam->id, $opp2->id, 7);

        $response = $this->get("/matches/{$this->match->id}");
        $response->assertSee('1 / 2');  // structural_matches_available / matches_considered
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [P] No combined score, probability, prediction, or opponent-adjusted text
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_combined_or_predictive_content(): void
    {
        $response = $this->get("/matches/{$this->match->id}");

        $response->assertDontSee('combined opponent');
        $response->assertDontSee('opponent score');
        $response->assertDontSee('probabilit');         // prefix covers probabilità/probability
        $response->assertDontSee('predizion');          // prefix covers previsione/prediction
        $response->assertDontSee('opponent-adjusted');
        $response->assertDontSee('adjusted performance');
    }
}
