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
 * Verifies the "Forza dinamica Elo" (E6) section on /matches/{match}.
 *
 * Rules under test:
 *  - eloData passed to view (home_elo, away_elo, elo_difference)
 *  - section heading always visible
 *  - new teams → 1500.0 rendered
 *  - Elo values rendered as 1-decimal formatted numbers
 *  - elo_difference rendered with sign
 *  - target match excluded (anti-leakage): finished match shows pre-match Elo
 *  - future match does not influence Elo
 *  - previous match shifts Elo correctly
 *  - label "Molto equilibrata" when |diff| < 25
 *  - label "Leggero vantaggio" when 25 ≤ |diff| < 75
 *  - section visible on finished match
 *  - home/away Elo computed independently
 */
class MatchPageEloTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;
    private Team $homeTeam;
    private Team $awayTeam;
    private Competition $comp;
    private Season $season;

    private const TARGET = '2026-09-10 20:45:00';

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
    // 1. View receives eloData array
    // ─────────────────────────────────────────────────────────────────────────

    public function test_view_receives_elo_data(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertViewHas('eloData');

        $elo = $response->viewData('eloData');
        $this->assertArrayHasKey('home_elo', $elo);
        $this->assertArrayHasKey('away_elo', $elo);
        $this->assertArrayHasKey('elo_difference', $elo);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Section heading always visible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_heading_visible(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('Forza dinamica Elo');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. New teams show INITIAL_ELO (1500.0)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_new_teams_show_initial_elo(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();

        $elo = $response->viewData('eloData');
        $this->assertSame(TeamEloCalculator::INITIAL_ELO, $elo['home_elo']);
        $this->assertSame(TeamEloCalculator::INITIAL_ELO, $elo['away_elo']);
        $this->assertSame(0.0, $elo['elo_difference']);

        // 1500.0 renders as "1 500,0" or "1,500.0" depending on locale — the
        // number_format(1500.0, 1) call in Blade produces "1,500.0" (en locale).
        $response->assertSee('1,500.0');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Elo values rendered as 1-decimal formatted numbers
    // ─────────────────────────────────────────────────────────────────────────

    public function test_elo_values_rendered_as_formatted_decimals(): void
    {
        // Create a previous home win to push home Elo above 1500.
        $this->makePrev(Carbon::parse(self::TARGET)->subDays(7), 1, 0);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $elo          = $response->viewData('eloData');
        $expectedHome = number_format($elo['home_elo'], 1);
        $expectedAway = number_format($elo['away_elo'], 1);

        $response->assertSee($expectedHome);
        $response->assertSee($expectedAway);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. elo_difference rendered with sign
    // ─────────────────────────────────────────────────────────────────────────

    public function test_elo_difference_rendered_with_sign(): void
    {
        // A home win pushes home Elo higher → positive difference.
        $this->makePrev(Carbon::parse(self::TARGET)->subDays(7), 1, 0);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $elo = $response->viewData('eloData');
        $this->assertGreaterThan(0.0, $elo['elo_difference']);

        $formatted = '+' . number_format($elo['elo_difference'], 1);
        $response->assertSee($formatted);
        $response->assertSee('Differenza Elo');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Target match excluded (anti-leakage)
    //    A finished target match must NOT affect the pre-match Elo shown.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_target_match_result_excluded(): void
    {
        // Give the target match a finished result.
        $this->match->update([
            'status'        => 'finished',
            'home_score_ft' => 5,
            'away_score_ft' => 0,
        ]);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $elo = $response->viewData('eloData');
        // No prior matches → both teams still at INITIAL_ELO.
        $this->assertSame(TeamEloCalculator::INITIAL_ELO, $elo['home_elo']);
        $this->assertSame(TeamEloCalculator::INITIAL_ELO, $elo['away_elo']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Future match does not influence Elo
    // ─────────────────────────────────────────────────────────────────────────

    public function test_future_match_not_included(): void
    {
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET)->addDays(7),
            'status'         => 'finished',
            'home_score_ft'  => 3,
            'away_score_ft'  => 0,
        ]);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $elo = $response->viewData('eloData');
        $this->assertSame(TeamEloCalculator::INITIAL_ELO, $elo['home_elo']);
        $this->assertSame(TeamEloCalculator::INITIAL_ELO, $elo['away_elo']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. Previous match shifts Elo correctly
    // ─────────────────────────────────────────────────────────────────────────

    public function test_previous_home_win_shifts_home_elo_up(): void
    {
        $this->makePrev(Carbon::parse(self::TARGET)->subDays(7), 1, 0);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $elo = $response->viewData('eloData');
        $this->assertGreaterThan(TeamEloCalculator::INITIAL_ELO, $elo['home_elo']);
        $this->assertLessThan(TeamEloCalculator::INITIAL_ELO, $elo['away_elo']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9. Label "Molto equilibrata" when |diff| < 25
    //    Both at INITIAL_ELO → diff = 0 < 25.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_label_molto_equilibrata_when_diff_below_25(): void
    {
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $elo = $response->viewData('eloData');
        $this->assertLessThan(25.0, abs($elo['elo_difference']));
        $response->assertSee('Molto equilibrata');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10. Label "Leggero vantaggio" when 25 ≤ |diff| < 75
    //     Two prior home wins produce |diff| ≈ 32 (verified numerically).
    // ─────────────────────────────────────────────────────────────────────────

    public function test_label_leggero_vantaggio_when_diff_in_range(): void
    {
        // After 2 home wins: |diff| ≈ 32 → "Leggero vantaggio".
        $this->makePrev(Carbon::parse(self::TARGET)->subDays(14), 1, 0);
        $this->makePrev(Carbon::parse(self::TARGET)->subDays(7),  1, 0);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $elo = $response->viewData('eloData');
        $abs = abs($elo['elo_difference']);
        $this->assertGreaterThanOrEqual(25.0, $abs);
        $this->assertLessThan(75.0, $abs);
        $response->assertSee('Leggero vantaggio');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11. Section visible on finished match
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
        $response->assertSee('Forza dinamica Elo');
        $response->assertSee('Rating Elo');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 12. Home and away Elo are computed independently
    // ─────────────────────────────────────────────────────────────────────────

    public function test_home_and_away_elo_computed_independently(): void
    {
        // Previous match where away team played a different opponent and won.
        $teamC = Team::create(['name' => 'TeamC', 'type' => 'club', 'is_active' => true]);

        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->awayTeam->id, // Milan (away in target) is home here
            'away_team_id'   => $teamC->id,
            'kickoff_at'     => Carbon::parse(self::TARGET)->subDays(7),
            'status'         => 'finished',
            'home_score_ft'  => 3,
            'away_score_ft'  => 0,
        ]);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $elo = $response->viewData('eloData');

        // Inter (home, no history) stays at 1500.
        $this->assertSame(TeamEloCalculator::INITIAL_ELO, $elo['home_elo']);
        // Milan (away, won a match) should be above 1500.
        $this->assertGreaterThan(TeamEloCalculator::INITIAL_ELO, $elo['away_elo']);
        // elo_difference = home - away → negative (away is stronger).
        $this->assertLessThan(0.0, $elo['elo_difference']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makePrev(Carbon $kickoff, int $homeScore, int $awayScore): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => $kickoff,
            'status'         => 'finished',
            'home_score_ft'  => $homeScore,
            'away_score_ft'  => $awayScore,
        ]);
    }
}
