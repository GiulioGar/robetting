<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\TeamEloCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the "Forza dinamica Elo" section on /teams/{team}.
 *
 * Rules under test:
 *  - teamEloData passed to view (current_elo, elo_5_games_ago, elo_variation_5)
 *  - section heading visible
 *  - team with no definitive history shows INITIAL_ELO
 *  - current_elo rendered as formatted decimal
 *  - previous wins increase current_elo
 *  - future match not included in current_elo
 *  - elo_5_games_ago shown when ≥ 5 definitive matches exist
 *  - elo_variation_5 shown with sign (positive green / negative red)
 *  - no leakage from matches after now()
 */
class TeamPageEloTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;
    private Team $opponent;
    private Competition $comp;
    private Season $season;

    // Use a date clearly in the past so "previous" fixtures land before now().
    private const PAST_BASE = '2026-08-01 20:45:00';

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
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. View receives teamEloData
    // ─────────────────────────────────────────────────────────────────────────

    public function test_view_receives_team_elo_data(): void
    {
        $this->makeFinished(Carbon::parse(self::PAST_BASE));

        $response = $this->get(route('teams.show', $this->team));
        $response->assertOk();
        $response->assertViewHas('teamEloData');

        $data = $response->viewData('teamEloData');
        $this->assertArrayHasKey('current_elo', $data);
        $this->assertArrayHasKey('elo_5_games_ago', $data);
        $this->assertArrayHasKey('elo_variation_5', $data);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Section heading visible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_heading_visible(): void
    {
        $this->makeFinished(Carbon::parse(self::PAST_BASE));

        $response = $this->get(route('teams.show', $this->team));
        $response->assertOk();
        $response->assertSee('Forza dinamica Elo');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Team with no definitive history shows INITIAL_ELO
    //    (uses a scheduled match for analytics context, no finished results)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_team_with_no_history_shows_initial_elo(): void
    {
        // Scheduled match gives the page a context without contributing to Elo.
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->team->id,
            'away_team_id'   => $this->opponent->id,
            'kickoff_at'     => Carbon::now('UTC')->addDays(7),
            'status'         => 'scheduled',
        ]);

        $response = $this->get(route('teams.show', $this->team));
        $response->assertOk();

        $data = $response->viewData('teamEloData');
        $this->assertSame(TeamEloCalculator::INITIAL_ELO, $data['current_elo']);
        $this->assertNull($data['elo_5_games_ago']);
        $this->assertNull($data['elo_variation_5']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. current_elo rendered as formatted decimal
    // ─────────────────────────────────────────────────────────────────────────

    public function test_current_elo_rendered_as_formatted_decimal(): void
    {
        $this->makeFinished(Carbon::parse(self::PAST_BASE));

        $response = $this->get(route('teams.show', $this->team));
        $response->assertOk();

        $data      = $response->viewData('teamEloData');
        $formatted = number_format($data['current_elo'], 1);
        $response->assertSee($formatted);
        $response->assertSee('Elo attuale');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Previous wins increase current_elo above INITIAL_ELO
    // ─────────────────────────────────────────────────────────────────────────

    public function test_previous_wins_increase_current_elo(): void
    {
        $base = Carbon::parse(self::PAST_BASE);
        $this->makeFinished($base, 1, 0); // win as home
        $this->makeFinished($base->copy()->addDays(7), 1, 0); // second win

        $response = $this->get(route('teams.show', $this->team));
        $response->assertOk();

        $data = $response->viewData('teamEloData');
        $this->assertGreaterThan(TeamEloCalculator::INITIAL_ELO, $data['current_elo']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Future match not included in current_elo
    // ─────────────────────────────────────────────────────────────────────────

    public function test_future_match_not_included_in_current_elo(): void
    {
        // Only one past match for context.
        $this->makeFinished(Carbon::parse(self::PAST_BASE));

        $ratingAfterOnePastMatch = null;

        // Record the Elo after only 1 past match.
        {
            $response = $this->get(route('teams.show', $this->team));
            $response->assertOk();
            $ratingAfterOnePastMatch = $response->viewData('teamEloData')['current_elo'];
        }

        // Add a future "finished" match — calculator must ignore it.
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->team->id,
            'away_team_id'   => $this->opponent->id,
            'kickoff_at'     => Carbon::now('UTC')->addDays(7),
            'status'         => 'finished',
            'home_score_ft'  => 5,
            'away_score_ft'  => 0,
        ]);

        $response = $this->get(route('teams.show', $this->team));
        $response->assertOk();

        $data = $response->viewData('teamEloData');
        $this->assertSame($ratingAfterOnePastMatch, $data['current_elo']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. elo_5_games_ago shown when ≥ 5 definitive matches exist
    // ─────────────────────────────────────────────────────────────────────────

    public function test_elo_5_games_ago_shown_with_five_or_more_matches(): void
    {
        $base = Carbon::parse(self::PAST_BASE);
        for ($i = 0; $i < 6; $i++) {
            $this->makeFinished($base->copy()->addDays($i * 7));
        }

        $response = $this->get(route('teams.show', $this->team));
        $response->assertOk();

        $data = $response->viewData('teamEloData');
        $this->assertNotNull($data['elo_5_games_ago']);
        $this->assertNotNull($data['elo_variation_5']);

        $response->assertSee('Elo 5 partite fa');
        $response->assertSee('Variazione ultime 5');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. elo_variation_5 rendered with + sign when positive
    // ─────────────────────────────────────────────────────────────────────────

    public function test_variation_rendered_with_sign(): void
    {
        $base = Carbon::parse(self::PAST_BASE);

        // 6 wins as home team → Elo grows monotonically → variation5 > 0.
        for ($i = 0; $i < 6; $i++) {
            $this->makeFinished($base->copy()->addDays($i * 7), 2, 0);
        }

        $response = $this->get(route('teams.show', $this->team));
        $response->assertOk();

        $data = $response->viewData('teamEloData');
        $this->assertGreaterThan(0.0, $data['elo_variation_5']);

        $formatted = '+' . number_format($data['elo_variation_5'], 1);
        $response->assertSee($formatted);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makeFinished(
        Carbon $kickoff,
        int    $homeScore = 1,
        int    $awayScore = 0,
    ): FootballMatch {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->team->id,
            'away_team_id'   => $this->opponent->id,
            'kickoff_at'     => $kickoff,
            'status'         => 'finished',
            'home_score_ft'  => $homeScore,
            'away_score_ft'  => $awayScore,
        ]);
    }
}
