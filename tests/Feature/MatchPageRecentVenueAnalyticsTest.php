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
 * Verifies the semantics of homeRecentHomeAnalytics / awayRecentAwayAnalytics
 * (the "last 5 venue-only" windows) produced by MatchController.
 *
 * Core invariant being tested:
 *   The window is built as:
 *     previousMatches(team) → filter(venue) → lastN(5)
 *   NOT:
 *     previousMatches(team) → lastN(5) → filter(venue)
 *
 *   The two expressions are NOT equivalent. The spec requires the former:
 *   "last 5 matches actually played at that venue", not
 *   "venue matches found inside the last 5 overall".
 *
 * Tests:
 *  [A]  homeRecentHomeAnalytics passed in view data
 *  [B]  awayRecentAwayAnalytics passed in view data
 *  [C]  venue window uses last 5 home-only (not venue-filter of last 5 overall)
 *  [D]  venue window uses last 5 away-only (not venue-filter of last 5 overall)
 *  [E]  fewer than 5 venue matches → uses all available (no padding/crash)
 *  [F]  target match is excluded from the venue window
 *  [G]  future match is excluded from the venue window
 *  [H]  zero additional DB queries compared to base controller (in-memory filter only)
 *       — verified indirectly: no query count assertion but the window is built
 *         from already-loaded collections, so this is structural, not measured here
 *  [I]  no regression: pre-existing view variables still present
 */
class MatchPageRecentVenueAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;
    private Team $homeTeam;
    private Team $awayTeam;
    private Competition $comp;
    private Season $season;

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
        $this->homeTeam  = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->awayTeam  = Team::create(['name' => 'Milan',  'type' => 'club', 'is_active' => true]);
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

    /** Finished match where $home hosts $away with given scores. */
    private function played(Team $home, Team $away, string $kickoff, int $hs = 1, int $as = 0): FootballMatch
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

    // ─────────────────────────────────────────────────────────────────────────
    // [A] homeRecentHomeAnalytics present in view data
    // ─────────────────────────────────────────────────────────────────────────

    public function test_home_recent_home_analytics_in_view(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertViewHas('homeRecentHomeAnalytics');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] awayRecentAwayAnalytics present in view data
    // ─────────────────────────────────────────────────────────────────────────

    public function test_away_recent_away_analytics_in_view(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertViewHas('awayRecentAwayAnalytics');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] venue window correctness — home side
    //
    // Setup:
    //   HomeTeam plays 3 home matches (Aug 1, 8, 15),
    //   then 5 away matches (Aug 20, 27, Sep 3, 10, 17).
    //   Target: Sep 25.
    //
    //   Last 5 overall for homeTeam = the 5 away matches.
    //   Venue filter of last 5 overall → 0 home matches.
    //
    //   Correct last-5-home-only = 3 home matches (Aug 1, 8, 15).
    //   Expected: homeRecentHomeAnalytics.summary.matches_played = 3.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_home_venue_window_uses_last_5_home_not_venue_filter_of_overall(): void
    {
        $thirdTeam = Team::create(['name' => 'Juve', 'type' => 'club', 'is_active' => true]);

        // 3 home matches (early in the season)
        $this->played($this->homeTeam, $thirdTeam,  '2026-08-01 20:00:00');
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-08 20:00:00');
        $this->played($this->homeTeam, $thirdTeam,  '2026-08-15 20:00:00');

        // 5 away matches (more recent — these are the "last 5 overall")
        $this->played($thirdTeam,      $this->homeTeam, '2026-08-20 20:00:00');
        $this->played($this->awayTeam, $this->homeTeam, '2026-08-27 20:00:00');
        $this->played($thirdTeam,      $this->homeTeam, '2026-09-03 20:00:00');
        $this->played($this->awayTeam, $this->homeTeam, '2026-09-10 20:00:00');
        $this->played($thirdTeam,      $this->homeTeam, '2026-09-17 20:00:00');

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('homeRecentHomeAnalytics');

        // Correct semantics: 3 home games (capped at 5, only 3 available)
        $this->assertSame(3, $data['summary']['matches_played']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] venue window correctness — away side
    //
    // AwayTeam plays 3 away matches (Aug 1, 8, 15),
    // then 5 home matches (Aug 20 … Sep 17).
    // Last 5 overall for awayTeam = the 5 home matches → 0 away games inside.
    // Correct last-5-away-only = the 3 away matches.
    // Expected: awayRecentAwayAnalytics.summary.matches_played = 3.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_away_venue_window_uses_last_5_away_not_venue_filter_of_overall(): void
    {
        $thirdTeam = Team::create(['name' => 'Juve', 'type' => 'club', 'is_active' => true]);

        // 3 away matches for awayTeam
        $this->played($thirdTeam,     $this->awayTeam, '2026-08-01 20:00:00');
        $this->played($this->homeTeam,$this->awayTeam, '2026-08-08 20:00:00');
        $this->played($thirdTeam,     $this->awayTeam, '2026-08-15 20:00:00');

        // 5 home matches for awayTeam (more recent)
        $this->played($this->awayTeam, $thirdTeam,     '2026-08-20 20:00:00');
        $this->played($this->awayTeam, $this->homeTeam,'2026-08-27 20:00:00');
        $this->played($this->awayTeam, $thirdTeam,     '2026-09-03 20:00:00');
        $this->played($this->awayTeam, $this->homeTeam,'2026-09-10 20:00:00');
        $this->played($this->awayTeam, $thirdTeam,     '2026-09-17 20:00:00');

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('awayRecentAwayAnalytics');

        $this->assertSame(3, $data['summary']['matches_played']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] fewer than 5 venue matches → uses all available, no crash
    // ─────────────────────────────────────────────────────────────────────────

    public function test_fewer_than_5_venue_matches_uses_all_available(): void
    {
        // Only 2 home matches for homeTeam before target
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-01 20:00:00');
        $this->played($this->homeTeam, $this->awayTeam, '2026-08-08 20:00:00');

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('homeRecentHomeAnalytics');

        $this->assertSame(2, $data['summary']['matches_played']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] target match excluded from venue window
    // ─────────────────────────────────────────────────────────────────────────

    public function test_target_match_excluded_from_venue_window(): void
    {
        // No previous matches; target match kickoff is Sep 25.
        // homeRecentHomeAnalytics must be empty (0 played).
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('homeRecentHomeAnalytics');
        $this->assertSame(0, $data['summary']['matches_played']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] future match excluded from venue window
    // ─────────────────────────────────────────────────────────────────────────

    public function test_future_match_excluded_from_venue_window(): void
    {
        // One home match in the future (after target kickoff) — should not count.
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse('2026-10-05 20:00:00', 'UTC'),
            'status'         => 'scheduled',
        ]);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $data = $response->viewData('homeRecentHomeAnalytics');
        $this->assertSame(0, $data['summary']['matches_played']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] no regression — pre-existing view variables still present
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_regression_existing_view_variables(): void
    {
        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $keys = [
            'homeSeasonAnalytics', 'homeLast5Analytics', 'homeLast10Analytics', 'homeHomeAnalytics',
            'awaySeasonAnalytics', 'awayLast5Analytics', 'awayLast10Analytics', 'awayAwayAnalytics',
            'headToHead', 'eloData', 'strengthComparison',
            'homeRecentHomeAnalytics', 'awayRecentAwayAnalytics',
        ];

        foreach ($keys as $key) {
            $response->assertViewHas($key, null, "View missing: $key");
        }
    }
}
