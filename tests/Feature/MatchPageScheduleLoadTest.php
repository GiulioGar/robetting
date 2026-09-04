<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the "Carico recente" (E1 schedule load) section on /matches/{match}.
 *
 * Rules under test:
 *  - Section heading always visible regardless of match status.
 *  - homeScheduleLoad / awayScheduleLoad passed to the view.
 *  - No previous matches → rest_days null, counts 0; view renders "—" fallback.
 *  - rest_days is the floor of calendar days since the last match.
 *  - Counts respect the 7 / 14 / 30 day windows.
 *  - Matches at or after the target kickoff are NOT included (no leakage).
 *  - Cross-competition matches ARE included.
 */
class MatchPageScheduleLoadTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;
    private Team $homeTeam;
    private Team $awayTeam;
    private Competition $comp;
    private Season $season;

    /** Fixed target kickoff used across all tests. */
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

    // -------------------------------------------------------------------------
    // 1. Both load arrays are passed to the view
    // -------------------------------------------------------------------------

    public function test_view_receives_home_and_away_schedule_load(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertViewHas('homeScheduleLoad');
        $response->assertViewHas('awayScheduleLoad');
    }

    // -------------------------------------------------------------------------
    // 2. No history → rest_days null, all counts 0; page shows "—"
    // -------------------------------------------------------------------------

    public function test_no_history_gives_null_rest_days_and_zero_counts(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertViewHas('homeScheduleLoad', [
            'rest_days'            => null,
            'matches_last_7_days'  => 0,
            'matches_last_14_days' => 0,
            'matches_last_30_days' => 0,
        ]);
        $response->assertViewHas('awayScheduleLoad', [
            'rest_days'            => null,
            'matches_last_7_days'  => 0,
            'matches_last_14_days' => 0,
            'matches_last_30_days' => 0,
        ]);
        // Section heading always rendered
        $response->assertSee('Carico recente');
        // Null rest_days renders as "—" fallback
        $response->assertSee('—');
    }

    // -------------------------------------------------------------------------
    // 3. rest_days correctly calculated for home team
    // -------------------------------------------------------------------------

    public function test_rest_days_calculated_for_home_team(): void
    {
        // Home team played exactly 5 days before target kickoff (same time)
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET)->subDays(5),
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 0,
        ]);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertViewHas('homeScheduleLoad.rest_days', 5);
    }

    // -------------------------------------------------------------------------
    // 4. Window counts (7 / 14 / 30 days) correct for away team
    // -------------------------------------------------------------------------

    public function test_window_counts_correct_for_away_team(): void
    {
        $target = Carbon::parse(self::TARGET);

        // 2 matches inside last 7 days (3 and 6 days ago)
        foreach ([3, 6] as $daysAgo) {
            FootballMatch::create([
                'competition_id' => $this->comp->id,
                'season_id'      => $this->season->id,
                'home_team_id'   => $this->awayTeam->id,
                'away_team_id'   => $this->homeTeam->id,
                'kickoff_at'     => $target->copy()->subDays($daysAgo),
                'status'         => 'finished',
                'home_score_ft'  => 1,
                'away_score_ft'  => 1,
            ]);
        }
        // 1 more inside last 14 days (10 days ago — outside 7-day window)
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => $target->copy()->subDays(10),
            'status'         => 'finished',
            'home_score_ft'  => 0,
            'away_score_ft'  => 2,
        ]);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertViewHas('awayScheduleLoad.matches_last_7_days', 2);
        $response->assertViewHas('awayScheduleLoad.matches_last_14_days', 3);
        $response->assertViewHas('awayScheduleLoad.matches_last_30_days', 3);
    }

    // -------------------------------------------------------------------------
    // 5. Future matches excluded (no data leakage)
    // -------------------------------------------------------------------------

    public function test_future_matches_excluded_no_leakage(): void
    {
        // A match for home team that kicks off AFTER the target — must be ignored
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET)->addDays(3),
            'status'         => 'finished', // status = finished but kickoff is AFTER target
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        // With no valid previous matches, rest_days must be null
        $response->assertViewHas('homeScheduleLoad.rest_days', null);
        $response->assertViewHas('homeScheduleLoad.matches_last_7_days', 0);
    }

    // -------------------------------------------------------------------------
    // 6. Cross-competition matches are included
    // -------------------------------------------------------------------------

    public function test_cross_competition_matches_included(): void
    {
        $country2 = Country::create(['name' => 'Europe', 'football_code' => 'EU']);
        $comp2    = Competition::create([
            'country_id' => $country2->id,
            'name'       => 'Champions League',
            'slug'       => 'champions-league',
            'format'     => 'cup',
            'is_active'  => true,
        ]);
        $season2  = Season::create([
            'competition_id' => $comp2->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);

        // Home team played in a different competition 3 days before target
        FootballMatch::create([
            'competition_id' => $comp2->id,
            'season_id'      => $season2->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET)->subDays(3),
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 1,
        ]);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertViewHas('homeScheduleLoad.rest_days', 3);
        $response->assertViewHas('homeScheduleLoad.matches_last_7_days', 1);
    }

    // -------------------------------------------------------------------------
    // 7. awarded and walkover statuses counted (not only finished)
    // -------------------------------------------------------------------------

    public function test_awarded_and_walkover_statuses_included(): void
    {
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET)->subDays(4),
            'status'         => 'awarded',
        ]);
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->awayTeam->id,
            'away_team_id'   => $this->homeTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET)->subDays(8),
            'status'         => 'walkover',
        ]);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        // Both statuses contribute to home team's count
        $response->assertViewHas('homeScheduleLoad.rest_days', 4);
        $response->assertViewHas('homeScheduleLoad.matches_last_7_days', 1);  // only 4-days-ago
        $response->assertViewHas('homeScheduleLoad.matches_last_14_days', 2); // both
    }

    // -------------------------------------------------------------------------
    // 8. Section labels rendered in HTML
    // -------------------------------------------------------------------------

    public function test_section_labels_visible_in_html(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('Carico recente');
        $response->assertSee('Riposo (gg)');
        $response->assertSee('Ultime 7 gg');
        $response->assertSee('Ultime 14 gg');
        $response->assertSee('Ultime 30 gg');
    }

    // -------------------------------------------------------------------------
    // 9. Section visible for finished matches too (always-visible validation block)
    // -------------------------------------------------------------------------

    public function test_section_visible_for_finished_match(): void
    {
        $finishedMatch = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET),
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 1,
        ]);

        $response = $this->get(route('matches.show', $finishedMatch));

        $response->assertOk();
        $response->assertSee('Carico recente');
    }
}
