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

class TeamPageMarketValueTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;
    private Team $opponent;
    private Competition $comp;
    private Season $season;
    private DataSource $source;

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
        $this->team     = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->opponent = Team::create(['name' => 'Milan',  'type' => 'club', 'is_active' => true]);

        $this->source = DataSource::create([
            'slug'        => 'transfermarkt',
            'name'        => 'Transfermarkt',
            'source_type' => 'scraper',
        ]);

        // Minimal finished match so the page has an analytics context.
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->team->id,
            'away_team_id'   => $this->opponent->id,
            'kickoff_at'     => Carbon::parse('2026-08-01 20:45:00'),
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Team with snapshot shows correct formatted value
    // ─────────────────────────────────────────────────────────────────────────

    public function test_team_with_snapshot_shows_correct_value(): void
    {
        // €730 million → displayed as "€730,00 mln"
        TeamMarketValueSnapshot::create([
            'team_id'        => $this->team->id,
            'data_source_id' => $this->source->id,
            'snapshot_date'  => '2026-09-11',
            'market_value'   => 730_000_000,
        ]);

        $response = $this->get(route('teams.show', $this->team));
        $response->assertOk();
        $response->assertSee('Market Value');
        $response->assertSee('€730,00 mln');

        $this->assertSame(730_000_000, $response->viewData('marketValue'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Team without snapshot shows N/D
    // ─────────────────────────────────────────────────────────────────────────

    public function test_team_without_snapshot_shows_nd(): void
    {
        $response = $this->get(route('teams.show', $this->team));
        $response->assertOk();
        $response->assertSee('Market Value');
        $response->assertSee('N/D');

        $this->assertNull($response->viewData('marketValue'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Uses latest snapshot when multiple exist
    // ─────────────────────────────────────────────────────────────────────────

    public function test_uses_latest_snapshot(): void
    {
        // Older snapshot
        TeamMarketValueSnapshot::create([
            'team_id'        => $this->team->id,
            'data_source_id' => $this->source->id,
            'snapshot_date'  => '2026-08-01',
            'market_value'   => 200_000_000,
        ]);

        // Newer snapshot
        TeamMarketValueSnapshot::create([
            'team_id'        => $this->team->id,
            'data_source_id' => $this->source->id,
            'snapshot_date'  => '2026-09-11',
            'market_value'   => 500_000_000,
        ]);

        $response = $this->get(route('teams.show', $this->team));
        $response->assertOk();
        $response->assertSee('€500,00 mln');
        $response->assertDontSee('€200,00 mln');

        $this->assertSame(500_000_000, $response->viewData('marketValue'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. No Structural Rating shown on the page
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_structural_rating_on_page(): void
    {
        TeamMarketValueSnapshot::create([
            'team_id'        => $this->team->id,
            'data_source_id' => $this->source->id,
            'snapshot_date'  => '2026-09-11',
            'market_value'   => 400_000_000,
        ]);

        $response = $this->get(route('teams.show', $this->team));
        $response->assertOk();
        $response->assertDontSee('Structural Rating');
        $response->assertDontSee('Forza Strutturale');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. No breaking changes — core page sections still render
    // ─────────────────────────────────────────────────────────────────────────

    public function test_page_still_renders_core_sections(): void
    {
        $response = $this->get(route('teams.show', $this->team));
        $response->assertOk();
        $response->assertSee('Forza dinamica Elo');
        $response->assertSee('Elo attuale');
        $response->assertSee('Riepilogo stagione');
        $response->assertSee($this->team->name);
    }
}
