<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamMarketValueSnapshot;
use App\Services\Analytics\TeamEloCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the "Confronto forza squadre" (E7) section on /matches/{match}.
 *
 * Coverage checklist:
 *  01. Card heading visible
 *  02. Structural Rating home/away visible when snapshots exist
 *  03. Elo home/away visible (always)
 *  04. Structural/Elo differences visible
 *  05. CONCORDI shown when both signals agree
 *  06. DIVERGENTI shown when signals diverge
 *  07. NON VALUTABILE shown when Structural is missing
 *  08. Elo still visible when Structural is missing
 *  09. No probability shown
 *  10. No combined strength shown
 */
class MatchPageStrengthComparisonTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;
    private Team $homeTeam;
    private Team $awayTeam;
    private Competition $comp;
    private Season $season;
    private DataSource $source;

    private const TARGET = '2026-10-15 20:45:00';

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

        $this->source = DataSource::create([
            'slug'        => 'transfermarkt',
            'name'        => 'Transfermarkt',
            'source_type' => 'scraper',
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

    private function makeSnapshot(Team $team, int $marketValue, string $date): void
    {
        TeamMarketValueSnapshot::create([
            'team_id'        => $team->id,
            'data_source_id' => $this->source->id,
            'snapshot_date'  => $date,
            'market_value'   => $marketValue,
        ]);
    }

    private function makeFinished(Team $home, Team $away, string $kickoff, int $hs = 1, int $as = 0): void
    {
        FootballMatch::create([
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

    // ─────────────────────────────────────────────────────────────────────────
    // 01 — Card heading visible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_card_heading_visible(): void
    {
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();
        $response->assertSee('Confronto forza squadre');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 02 — Structural Rating home/away visible when snapshots exist
    // ─────────────────────────────────────────────────────────────────────────

    public function test_structural_rating_visible_when_snapshots_exist(): void
    {
        $this->makeSnapshot($this->homeTeam, 800_000_000, '2026-09-11');
        $this->makeSnapshot($this->awayTeam, 400_000_000, '2026-09-11');

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        // strengthComparison passed to view
        $comparison = $response->viewData('strengthComparison');
        $this->assertNotNull($comparison['home_structural']['structural_rating']);
        $this->assertNotNull($comparison['away_structural']['structural_rating']);

        // Structural Rating label visible twice (home + away cards)
        $response->assertSee('Structural Rating');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 03 — Elo home/away always visible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_elo_visible_for_both_teams(): void
    {
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $comparison = $response->viewData('strengthComparison');
        $this->assertSame(TeamEloCalculator::INITIAL_ELO, $comparison['home_elo']);
        $this->assertSame(TeamEloCalculator::INITIAL_ELO, $comparison['away_elo']);

        // "Elo dinamico" label appears in the E7 cards
        $response->assertSee('Elo dinamico');
        $response->assertSee(number_format(TeamEloCalculator::INITIAL_ELO, 1));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 04 — Structural and Elo differences visible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_differences_visible(): void
    {
        $this->makeSnapshot($this->homeTeam, 800_000_000, '2026-09-11');
        $this->makeSnapshot($this->awayTeam, 400_000_000, '2026-09-11');
        $this->makeFinished($this->homeTeam, $this->awayTeam, '2026-09-01 20:45:00', 2, 0);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();
        $response->assertSee('Differenza Structural');
        $response->assertSee('Differenza Elo');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 05 — CONCORDI shown when both signals favor the same team
    // ─────────────────────────────────────────────────────────────────────────

    public function test_concordi_shown_when_signals_agree(): void
    {
        // Structural: home (800M) > away (100M) → structural_favorite = HOME
        $this->makeSnapshot($this->homeTeam, 800_000_000, '2026-09-11');
        $this->makeSnapshot($this->awayTeam, 100_000_000, '2026-09-11');

        // Elo: home wins historical match → elo_favorite = HOME
        $this->makeFinished($this->homeTeam, $this->awayTeam, '2026-09-01 20:45:00', 3, 0);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $comparison = $response->viewData('strengthComparison');
        $this->assertTrue($comparison['signals_agree']);
        $response->assertSee('CONCORDI');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 06 — DIVERGENTI shown when signals point to opposite teams
    // ─────────────────────────────────────────────────────────────────────────

    public function test_divergenti_shown_when_signals_diverge(): void
    {
        // Structural: home (800M) > away (100M) → structural_favorite = HOME
        $this->makeSnapshot($this->homeTeam, 800_000_000, '2026-09-11');
        $this->makeSnapshot($this->awayTeam, 100_000_000, '2026-09-11');

        // Elo: away team wins as home in a historical match → away_elo > home_elo → elo_favorite = AWAY
        $this->makeFinished($this->awayTeam, $this->homeTeam, '2026-09-01 20:45:00', 3, 0);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $comparison = $response->viewData('strengthComparison');
        $this->assertFalse($comparison['signals_agree']);
        $response->assertSee('DIVERGENTI');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 07 — NON VALUTABILE when Structural data is missing
    // ─────────────────────────────────────────────────────────────────────────

    public function test_non_valutabile_when_structural_missing(): void
    {
        // No snapshots → structural UNKNOWN → signals_agree null
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $comparison = $response->viewData('strengthComparison');
        $this->assertNull($comparison['signals_agree']);
        $response->assertSee('NON VALUTABILE');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 08 — Elo still shown when Structural is missing
    // ─────────────────────────────────────────────────────────────────────────

    public function test_elo_visible_when_structural_missing(): void
    {
        // Historical match raises Elo — no snapshots so structural is null.
        $this->makeFinished($this->homeTeam, $this->awayTeam, '2026-09-01 20:45:00', 2, 0);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $comparison = $response->viewData('strengthComparison');
        $this->assertNull($comparison['home_structural']['structural_rating']);
        $this->assertGreaterThan(TeamEloCalculator::INITIAL_ELO, $comparison['home_elo']);

        $response->assertSee('Elo dinamico');
        $response->assertSee('N/D');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 09 — No probability shown
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_probability_shown(): void
    {
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();
        $response->assertDontSee('probabilit');   // covers "probabilità", "probability"
        $response->assertDontSee('Probabilit');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10 — No combined strength shown
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_combined_strength_shown(): void
    {
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();
        $response->assertDontSee('combined');
        $response->assertDontSee('Combined');
        $response->assertDontSee('Forza complessiva');
        $response->assertDontSee('Robetting Strength');
    }
}
