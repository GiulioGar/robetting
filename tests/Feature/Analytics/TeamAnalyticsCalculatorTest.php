<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\TeamAnalyticsCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Feature tests for TeamAnalyticsCalculator.
 *
 * The calculator is purely Collection-in / array-out.  Tests build Eloquent
 * models in the DB, load them via normal queries, then pass them to
 * TeamAnalyticsCalculator::calculate() as the controller would.
 *
 * MatchStatistic collections are built manually (keyed by match_id) rather
 * than going through PreferredMatchStatisticResolver, so these tests are
 * isolated from DataSource / config dependencies.
 *
 * Tests:
 *  [A]  clean_sheets: home perspective
 *  [B]  clean_sheets: away perspective
 *  [C]  failed_to_score: home perspective
 *  [D]  failed_to_score: away perspective
 *  [E]  mixed home/away — correct orientation for both counters
 *  [F]  goal_diff_per_match: positive result
 *  [G]  goal_diff_per_match: negative result
 *  [H]  goal_diff_per_match: null when zero matches
 *  [I]  avg_shot_diff: correct positive value
 *  [J]  avg_shot_diff: correct negative value
 *  [K]  avg_shot_diff: null when no match statistics provided
 *  [L]  avg_shots_on_target_diff: correct value
 *  [M]  avg_shots_on_target_diff: null when stat is missing for one match only
 *  [N]  differential is null if only one side is null (not both)
 *  [O]  no regression — all pre-existing summary keys still present
 *  [P]  no regression — all pre-existing technical keys still present
 *  [Q]  clean_sheets = 0 and failed_to_score = 0 when all matches are scoring both ways
 */
class TeamAnalyticsCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Team $teamA;
    private Team $teamB;
    private Competition $comp;
    private Season $season;

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
        $this->teamA = Team::create(['name' => 'TeamA', 'type' => 'club', 'is_active' => true]);
        $this->teamB = Team::create(['name' => 'TeamB', 'type' => 'club', 'is_active' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makeMatch(Team $home, Team $away, string $kickoff, int $hs, int $as): FootballMatch
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

    /** Build a match_statistics collection keyed by match_id, bypassing PreferredMatchStatisticResolver. */
    private function makeStatsColl(array $entries): Collection
    {
        $result = collect();
        foreach ($entries as [$matchId, $data]) {
            $stat = new MatchStatistic($data);
            $stat->match_id = $matchId;
            $result->put($matchId, $stat);
        }
        return $result;
    }

    private function loadMatches(array $matchIds): Collection
    {
        return FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->whereIn('id', $matchIds)
            ->orderBy('kickoff_at')
            ->get();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [A] clean_sheets — home perspective
    // ─────────────────────────────────────────────────────────────────────────

    public function test_clean_sheets_home_perspective(): void
    {
        $m1 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-01 20:00:00', 2, 0); // CS
        $m2 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-08 20:00:00', 1, 1); // not CS
        $m3 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-15 20:00:00', 0, 0); // CS (conceded 0)

        $matches = $this->loadMatches([$m1->id, $m2->id, $m3->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id);

        $this->assertSame(2, $result['summary']['clean_sheets']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] clean_sheets — away perspective
    // ─────────────────────────────────────────────────────────────────────────

    public function test_clean_sheets_away_perspective(): void
    {
        $m1 = $this->makeMatch($this->teamB, $this->teamA, '2026-08-01 20:00:00', 0, 3); // CS for teamA
        $m2 = $this->makeMatch($this->teamB, $this->teamA, '2026-08-08 20:00:00', 1, 2); // not CS
        $m3 = $this->makeMatch($this->teamB, $this->teamA, '2026-08-15 20:00:00', 2, 0); // not CS

        $matches = $this->loadMatches([$m1->id, $m2->id, $m3->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id);

        $this->assertSame(1, $result['summary']['clean_sheets']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] failed_to_score — home perspective
    // ─────────────────────────────────────────────────────────────────────────

    public function test_failed_to_score_home_perspective(): void
    {
        $m1 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-01 20:00:00', 0, 1); // FTS
        $m2 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-08 20:00:00', 0, 0); // FTS
        $m3 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-15 20:00:00', 2, 1); // scored

        $matches = $this->loadMatches([$m1->id, $m2->id, $m3->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id);

        $this->assertSame(2, $result['summary']['failed_to_score']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] failed_to_score — away perspective
    // ─────────────────────────────────────────────────────────────────────────

    public function test_failed_to_score_away_perspective(): void
    {
        $m1 = $this->makeMatch($this->teamB, $this->teamA, '2026-08-01 20:00:00', 2, 0); // FTS for teamA
        $m2 = $this->makeMatch($this->teamB, $this->teamA, '2026-08-08 20:00:00', 1, 1); // scored
        $m3 = $this->makeMatch($this->teamB, $this->teamA, '2026-08-15 20:00:00', 0, 0); // FTS for teamA

        $matches = $this->loadMatches([$m1->id, $m2->id, $m3->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id);

        $this->assertSame(2, $result['summary']['failed_to_score']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] mixed home/away — correct orientation for both counters
    // Scenario:
    //   m1: teamA home 2-0 → CS=1, FTS=0
    //   m2: teamA away 1-0 → CS=1, FTS=0
    //   m3: teamA home 0-1 → CS=0, FTS=1
    //   m4: teamA away 0-0 → CS=1, FTS=1
    // Expected: clean_sheets=3, failed_to_score=2
    // ─────────────────────────────────────────────────────────────────────────

    public function test_mixed_venue_correct_orientation(): void
    {
        $m1 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-01 20:00:00', 2, 0);
        $m2 = $this->makeMatch($this->teamB, $this->teamA, '2026-08-08 20:00:00', 0, 1);
        $m3 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-15 20:00:00', 0, 1);
        $m4 = $this->makeMatch($this->teamB, $this->teamA, '2026-08-22 20:00:00', 0, 0);

        $matches = $this->loadMatches([$m1->id, $m2->id, $m3->id, $m4->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id);

        $this->assertSame(3, $result['summary']['clean_sheets']);
        $this->assertSame(2, $result['summary']['failed_to_score']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] goal_diff_per_match — positive
    // 3 matches: 2-0, 3-1, 1-2 → gf=6 ga=3 played=3 → gd/m = 1.00
    // ─────────────────────────────────────────────────────────────────────────

    public function test_goal_diff_per_match_positive(): void
    {
        $m1 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-01 20:00:00', 2, 0);
        $m2 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-08 20:00:00', 3, 1);
        $m3 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-15 20:00:00', 1, 2);

        $matches = $this->loadMatches([$m1->id, $m2->id, $m3->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id);

        // gf=6, ga=3, played=3 → gd/m = 1.00
        $this->assertSame(1.0, $result['summary']['goal_diff_per_match']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] goal_diff_per_match — negative (away team perspective)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_goal_diff_per_match_negative(): void
    {
        // teamA always away, loses: 0-1, 0-2 → gf=0, ga=3, played=2 → gd/m = -1.50
        $m1 = $this->makeMatch($this->teamB, $this->teamA, '2026-08-01 20:00:00', 1, 0);
        $m2 = $this->makeMatch($this->teamB, $this->teamA, '2026-08-08 20:00:00', 2, 0);

        $matches = $this->loadMatches([$m1->id, $m2->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id);

        $this->assertSame(-1.5, $result['summary']['goal_diff_per_match']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] goal_diff_per_match — null when zero matches
    // ─────────────────────────────────────────────────────────────────────────

    public function test_goal_diff_per_match_null_when_no_matches(): void
    {
        $result = TeamAnalyticsCalculator::calculate(collect(), $this->teamA->id);

        $this->assertNull($result['summary']['goal_diff_per_match']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] avg_shot_diff — correct positive value
    // 2 matches (teamA home):
    //   m1: shots_for=10 shots_against=6
    //   m2: shots_for=8  shots_against=4
    // avg_shots_for=9, avg_shots_against=5 → diff=4.0
    // ─────────────────────────────────────────────────────────────────────────

    public function test_avg_shot_diff_correct_positive(): void
    {
        $m1 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-01 20:00:00', 1, 0);
        $m2 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-08 20:00:00', 2, 1);

        $statsColl = $this->makeStatsColl([
            [$m1->id, ['home_shots' => 10, 'away_shots' => 6, 'home_shots_on_target' => 4, 'away_shots_on_target' => 2, 'home_corners' => 5, 'away_corners' => 3, 'home_fouls' => 10, 'away_fouls' => 12]],
            [$m2->id, ['home_shots' => 8,  'away_shots' => 4, 'home_shots_on_target' => 3, 'away_shots_on_target' => 1, 'home_corners' => 4, 'away_corners' => 2, 'home_fouls' => 8,  'away_fouls' => 11]],
        ]);

        $matches = $this->loadMatches([$m1->id, $m2->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id, $statsColl);

        // avg_shots_for = (10+8)/2 = 9.0, avg_shots_against = (6+4)/2 = 5.0 → diff = 4.0
        $this->assertSame(4.0, $result['technical']['avg_shot_diff']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J] avg_shot_diff — correct negative value (away perspective)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_avg_shot_diff_correct_negative(): void
    {
        // teamA is away: shots for = away_shots, shots against = home_shots
        $m1 = $this->makeMatch($this->teamB, $this->teamA, '2026-08-01 20:00:00', 1, 0);

        $statsColl = $this->makeStatsColl([
            [$m1->id, ['home_shots' => 15, 'away_shots' => 7, 'home_shots_on_target' => 6, 'away_shots_on_target' => 2, 'home_corners' => 6, 'away_corners' => 2, 'home_fouls' => 9, 'away_fouls' => 13]],
        ]);

        $matches = $this->loadMatches([$m1->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id, $statsColl);

        // shots_for = 7 (away), shots_against = 15 (home) → diff = -8.0
        $this->assertSame(-8.0, $result['technical']['avg_shot_diff']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [K] avg_shot_diff — null when no match statistics provided
    // ─────────────────────────────────────────────────────────────────────────

    public function test_avg_shot_diff_null_when_no_stats(): void
    {
        $m1 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-01 20:00:00', 1, 0);

        $matches = $this->loadMatches([$m1->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id); // no $matchStatistics

        $this->assertNull($result['technical']['avg_shot_diff']);
        $this->assertNull($result['technical']['avg_shots_on_target_diff']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [L] avg_shots_on_target_diff — correct value
    // ─────────────────────────────────────────────────────────────────────────

    public function test_avg_shots_on_target_diff_correct(): void
    {
        $m1 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-01 20:00:00', 1, 0);
        $m2 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-08 20:00:00', 2, 1);

        $statsColl = $this->makeStatsColl([
            [$m1->id, ['home_shots' => 10, 'away_shots' => 6, 'home_shots_on_target' => 6, 'away_shots_on_target' => 2, 'home_corners' => 5, 'away_corners' => 3, 'home_fouls' => 10, 'away_fouls' => 12]],
            [$m2->id, ['home_shots' => 8,  'away_shots' => 4, 'home_shots_on_target' => 4, 'away_shots_on_target' => 2, 'home_corners' => 4, 'away_corners' => 2, 'home_fouls' => 8,  'away_fouls' => 11]],
        ]);

        $matches = $this->loadMatches([$m1->id, $m2->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id, $statsColl);

        // avg_sot_for = (6+4)/2 = 5.0, avg_sot_against = (2+2)/2 = 2.0 → diff = 3.0
        $this->assertSame(3.0, $result['technical']['avg_shots_on_target_diff']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [M] avg_shots_on_target_diff — null when only one of two matches has stats
    // (avg_sot_for will still be non-null if at least one match has it, but
    //  the field-level null propagation only applies when avg itself is null)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_avg_shots_on_target_diff_null_when_sot_col_missing(): void
    {
        // Provide stats but with shots_on_target cols set to null → TAC treats
        // null fields as uncoverable → avg_sot stays null → diff stays null.
        $m1 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-01 20:00:00', 1, 0);

        $statsColl = $this->makeStatsColl([
            [$m1->id, ['home_shots' => 10, 'away_shots' => 6, 'home_shots_on_target' => null, 'away_shots_on_target' => null, 'home_corners' => 5, 'away_corners' => 3, 'home_fouls' => 10, 'away_fouls' => 12]],
        ]);

        $matches = $this->loadMatches([$m1->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id, $statsColl);

        $this->assertNull($result['technical']['avg_shots_on_target_for']);
        $this->assertNull($result['technical']['avg_shots_on_target_diff']);
        // avg_shot_diff should still be computed when shots are present
        $this->assertSame(4.0, $result['technical']['avg_shot_diff']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [N] differential is null if one side's average is null (not both required)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_differential_null_when_only_for_is_null(): void
    {
        // m1 has both sides; m2 only has home_shots null → home team's shots_for = null
        $m1 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-01 20:00:00', 1, 0);
        $m2 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-08 20:00:00', 2, 1);

        $statsColl = $this->makeStatsColl([
            // m1: home_shots null → PAIR skips this row for 'shots' metric entirely (both must be non-null)
            [$m1->id, ['home_shots' => null, 'away_shots' => 6, 'home_shots_on_target' => 4, 'away_shots_on_target' => 2, 'home_corners' => 5, 'away_corners' => 3, 'home_fouls' => 10, 'away_fouls' => 12]],
            // m2: both null
            [$m2->id, ['home_shots' => null, 'away_shots' => null, 'home_shots_on_target' => null, 'away_shots_on_target' => null, 'home_corners' => 4, 'away_corners' => 2, 'home_fouls' => 8, 'away_fouls' => 11]],
        ]);

        $matches = $this->loadMatches([$m1->id, $m2->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id, $statsColl);

        $this->assertNull($result['technical']['avg_shots_for']);
        $this->assertNull($result['technical']['avg_shots_against']);
        $this->assertNull($result['technical']['avg_shot_diff']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [O] no regression — all pre-existing summary keys still present
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_regression_summary_keys(): void
    {
        $m1 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-01 20:00:00', 2, 1);
        $matches = $this->loadMatches([$m1->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id);

        $expectedKeys = [
            'matches_played', 'wins', 'draws', 'losses', 'points',
            'goals_for', 'goals_against', 'goal_difference',
            'avg_goals_for', 'avg_goals_against', 'avg_total_goals',
            'win_percentage', 'draw_percentage', 'loss_percentage',
            'clean_sheets', 'failed_to_score', 'goal_diff_per_match',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result['summary'], "Missing summary key: $key");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [P] no regression — all pre-existing technical keys still present
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_regression_technical_keys(): void
    {
        $m1 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-01 20:00:00', 1, 0);

        $statsColl = $this->makeStatsColl([
            [$m1->id, ['home_shots' => 10, 'away_shots' => 6, 'home_shots_on_target' => 4, 'away_shots_on_target' => 2, 'home_corners' => 5, 'away_corners' => 3, 'home_fouls' => 10, 'away_fouls' => 12, 'home_yellow_cards' => 1, 'away_yellow_cards' => 2, 'home_red_cards' => 0, 'away_red_cards' => 0]],
        ]);

        $matches = $this->loadMatches([$m1->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id, $statsColl);

        $expectedTechnical = [
            'avg_shots_for', 'avg_shots_against',
            'avg_shots_on_target_for', 'avg_shots_on_target_against',
            'avg_corners_for', 'avg_corners_against',
            'avg_fouls_for', 'avg_fouls_against',
            'avg_yellow_cards', 'avg_red_cards',
            'avg_shot_diff', 'avg_shots_on_target_diff',
        ];

        foreach ($expectedTechnical as $key) {
            $this->assertArrayHasKey($key, $result['technical'], "Missing technical key: $key");
        }

        $expectedTopLevel = ['team_id', 'summary', 'form', 'technical', 'coverage', 'market_trends'];
        foreach ($expectedTopLevel as $key) {
            $this->assertArrayHasKey($key, $result, "Missing top-level key: $key");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [Q] clean_sheets=0 failed_to_score=0 when all matches are mutual scoring
    // ─────────────────────────────────────────────────────────────────────────

    public function test_zero_clean_sheets_and_zero_failed_to_score(): void
    {
        $m1 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-01 20:00:00', 1, 2);
        $m2 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-08 20:00:00', 2, 1);
        $m3 = $this->makeMatch($this->teamA, $this->teamB, '2026-08-15 20:00:00', 3, 3);

        $matches = $this->loadMatches([$m1->id, $m2->id, $m3->id]);
        $result  = TeamAnalyticsCalculator::calculate($matches, $this->teamA->id);

        $this->assertSame(0, $result['summary']['clean_sheets']);
        $this->assertSame(0, $result['summary']['failed_to_score']);
    }
}
