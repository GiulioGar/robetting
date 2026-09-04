<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\TeamScheduleLoadCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Tests for TeamScheduleLoadCalculator.
 *
 * Each test that involves the "caller query" uses $this->loadFor() which
 * replicates exactly the query a controller/feature-engineering layer would use.
 * Tests annotated with [pure] call TeamScheduleLoadCalculator::calculate()
 * directly with hand-built Collections and exercise zero DB logic.
 *
 * Spec requirements covered:
 *  [A] no previous match → rest_days = null
 *  [B] last match 3 days before → rest_days = 3
 *  [C] correct 7 / 14 / 30 counts
 *  [D] target match not included (strict <)
 *  [E] future matches not included
 *  [F] live / non-definitive excluded
 *  [G] team found as home_team_id
 *  [H] team found as away_team_id
 *  [I] matches from other competitions included
 *  [J] strict kickoff_at < target (boundary = not included)
 *  [K] no contamination between teams
 *  [L] most recent previous match identified correctly
 */
class TeamScheduleLoadCalculatorTest extends TestCase
{
    use RefreshDatabase;

    // ── Shared fixtures ──────────────────────────────────────────────────────

    private Team $teamA;    // the team under analysis
    private Team $teamB;    // regular opponent
    private Team $teamC;    // extra opponent (cross-competition, contamination tests)
    private int  $compId;
    private int  $seasonId;

    // Fixed target kickoff used across most tests.
    private Carbon $target;

    protected function setUp(): void
    {
        parent::setUp();

        $country  = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $comp     = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $season   = Season::create([
            'competition_id' => $comp->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);

        $this->compId   = $comp->id;
        $this->seasonId = $season->id;

        $this->teamA = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->teamB = Team::create(['name' => 'Milan',  'type' => 'club', 'is_active' => true]);
        $this->teamC = Team::create(['name' => 'Juve',   'type' => 'club', 'is_active' => true]);

        $this->target = Carbon::parse('2026-09-10 20:45:00');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Canonical caller query: definitive matches for $teamId with kickoff < target.
     * This replicates exactly what a controller / feature-engineering caller would do.
     */
    private function loadFor(FootballMatch $targetMatch, int $teamId): array
    {
        $previous = FootballMatch::whereIn('status', ['finished', 'awarded', 'walkover'])
            ->where('kickoff_at', '<', $targetMatch->kickoff_at)
            ->where(function ($q) use ($teamId) {
                $q->where('home_team_id', $teamId)
                  ->orWhere('away_team_id', $teamId);
            })
            ->get();

        return TeamScheduleLoadCalculator::calculate($previous, $targetMatch->kickoff_at);
    }

    /**
     * Create a match in DB with sensible defaults.
     */
    private function makeMatch(
        int    $homeId,
        int    $awayId,
        string $kickoff,
        string $status = 'finished',
        ?int   $compId   = null,
        ?int   $seasonId = null,
    ): FootballMatch {
        return FootballMatch::create([
            'competition_id' => $compId   ?? $this->compId,
            'season_id'      => $seasonId ?? $this->seasonId,
            'home_team_id'   => $homeId,
            'away_team_id'   => $awayId,
            'kickoff_at'     => $kickoff,
            'status'         => $status,
            'home_score_ft'  => $status === 'finished' ? 1 : null,
            'away_score_ft'  => $status === 'finished' ? 0 : null,
        ]);
    }

    /**
     * Build a pure Collection of minimal objects for the calculator without hitting the DB.
     * Useful for testing calculator arithmetic without needing FK constraints.
     */
    private function pureCollection(array $kickoffs): Collection
    {
        return collect(array_map(
            fn($ko) => (object)['kickoff_at' => Carbon::parse($ko)],
            $kickoffs,
        ));
    }

    // =========================================================================
    // [A] No previous matches
    // =========================================================================

    public function test_no_previous_matches_returns_null_rest_days_and_zero_counts(): void
    {
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertNull($result['rest_days'],              'rest_days must be null with no history');
        $this->assertSame(0, $result['matches_last_7_days'],  'last 7  must be 0');
        $this->assertSame(0, $result['matches_last_14_days'], 'last 14 must be 0');
        $this->assertSame(0, $result['matches_last_30_days'], 'last 30 must be 0');
    }

    // =========================================================================
    // [B] rest_days
    // =========================================================================

    public function test_rest_days_is_three_when_last_match_was_three_days_ago_same_time(): void
    {
        // previous: 07-Sep 20:45, target: 10-Sep 20:45 → exactly 3 full days
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-07 20:45:00');
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertSame(3, $result['rest_days']);
    }

    public function test_rest_days_floors_when_target_kickoff_is_later_in_the_day(): void
    {
        // previous: 07-Sep 18:00, target: 10-Sep 21:00 → 3d 3h → floor = 3
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-07 18:00:00');
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 21:00:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertSame(3, $result['rest_days'], '3d 3h should floor to 3');
    }

    public function test_rest_days_floors_when_target_kickoff_is_earlier_in_the_day(): void
    {
        // previous: 07-Sep 21:00, target: 10-Sep 18:00 → 2d 21h → floor = 2
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-07 21:00:00');
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 18:00:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertSame(2, $result['rest_days'], '2d 21h should floor to 2');
    }

    public function test_most_recent_previous_match_is_used_for_rest_days(): void // [L]
    {
        // Two previous matches: 3 days ago and 10 days ago.
        // rest_days must reflect the closest one (3), not the furthest (10).
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-08-31 20:45:00'); // 10 days
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-07 20:45:00'); // 3 days
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertSame(3, $result['rest_days']);
    }

    // =========================================================================
    // [C] Window counts: 7 / 14 / 30 days
    // =========================================================================

    public function test_window_counts_are_correct_for_matches_at_various_distances(): void
    {
        // target: 2026-09-10 20:45
        // matches at:  1d, 5d, 8d, 15d, 31d before target
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-09 20:45:00'); //  1d → in 7, 14, 30
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-05 20:45:00'); //  5d → in 7, 14, 30
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-02 20:45:00'); //  8d → in 14, 30
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-08-26 20:45:00'); // 15d → in 30
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-08-10 20:45:00'); // 31d → outside all
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertSame(2, $result['matches_last_7_days'],  '1d and 5d ago → 2');
        $this->assertSame(3, $result['matches_last_14_days'], '1d, 5d, 8d ago → 3');
        $this->assertSame(4, $result['matches_last_30_days'], '1d, 5d, 8d, 15d ago → 4');
    }

    public function test_match_exactly_at_7_day_boundary_is_included(): void
    {
        // target: 2026-09-10 20:45 — subDays(7) = 2026-09-03 20:45
        // A match at exactly that moment must be included (>=).
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-03 20:45:00');
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertSame(1, $result['matches_last_7_days'],  'exactly 7 days before = included');
        $this->assertSame(1, $result['matches_last_14_days']);
        $this->assertSame(1, $result['matches_last_30_days']);
    }

    public function test_match_just_outside_7_day_boundary_is_excluded_from_7_count(): void
    {
        // One second before the 7-day boundary is NOT in the 7-day window but IS in 14-day.
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-03 20:44:59');
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertSame(0, $result['matches_last_7_days'],  '1s outside the 7d boundary = excluded');
        $this->assertSame(1, $result['matches_last_14_days'], 'but inside 14d window');
        $this->assertSame(1, $result['matches_last_30_days']);
    }

    // =========================================================================
    // [D] Target match not included (strict <)
    // =========================================================================

    public function test_target_match_kickoff_is_not_counted_strict_less_than(): void
    {
        // Create the target match and another match with the SAME kickoff_at.
        // The target must not appear in its own previous-match query.
        // We also add a distinct "other" match at the same time for the same team
        // (simulating a double-header edge case) — it too must be excluded.
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        // Simulate a hypothetical match exactly at target kickoff — strict < excludes it.
        $this->makeMatch($this->teamA->id, $this->teamC->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertNull($result['rest_days'],              'no match strictly before target');
        $this->assertSame(0, $result['matches_last_7_days']);
    }

    // =========================================================================
    // [E] Future matches not included
    // =========================================================================

    public function test_future_matches_are_excluded(): void
    {
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-15 20:45:00', 'finished');
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertNull($result['rest_days'],   'future match must be excluded');
        $this->assertSame(0, $result['matches_last_7_days']);
    }

    // =========================================================================
    // [F] Non-definitive / live matches excluded
    // =========================================================================

    public function test_scheduled_match_is_excluded(): void
    {
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-07 20:45:00', 'scheduled');
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertNull($result['rest_days'],   'scheduled match must not appear in history');
        $this->assertSame(0, $result['matches_last_7_days']);
    }

    public function test_live_match_is_excluded(): void
    {
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-07 20:45:00', 'live');
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertNull($result['rest_days'],   'live match must not appear in history');
    }

    public function test_awarded_and_walkover_are_included_as_definitive(): void
    {
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-05 20:45:00', 'awarded');
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-03 20:45:00', 'walkover');
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertSame(5, $result['rest_days'],             'last definitive = awarded 5 days ago');
        $this->assertSame(2, $result['matches_last_7_days'],   'both awarded and walkover counted');
    }

    // =========================================================================
    // [G] Team as home_team_id
    // =========================================================================

    public function test_team_counted_when_playing_at_home(): void
    {
        // teamA is home
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-07 20:45:00');
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertSame(3, $result['rest_days']);
        $this->assertSame(1, $result['matches_last_7_days']);
    }

    // =========================================================================
    // [H] Team as away_team_id
    // =========================================================================

    public function test_team_counted_when_playing_away(): void
    {
        // teamA is away
        $this->makeMatch($this->teamB->id, $this->teamA->id, '2026-09-07 20:45:00');
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertSame(3, $result['rest_days'],            'team found as away_team_id');
        $this->assertSame(1, $result['matches_last_7_days']);
    }

    // =========================================================================
    // [I] Cross-competition matches included
    // =========================================================================

    public function test_match_from_different_competition_is_included(): void
    {
        // Create a second competition + season
        $country2 = Country::create(['name' => 'Europe', 'football_code' => 'EU']);
        $comp2    = Competition::create([
            'country_id' => $country2->id,
            'name'       => 'Champions League',
            'slug'       => 'champions-league',
            'format'     => 'knockout',
            'is_active'  => true,
        ]);
        $season2  = Season::create([
            'competition_id' => $comp2->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);

        // Previous match in another competition (CL)
        $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-07 18:45:00',
            'finished', $comp2->id, $season2->id);

        // Target match in Serie A
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertSame(1, $result['matches_last_7_days'],
            'cross-competition match must be included — fatigue does not stop at league borders');
    }

    // =========================================================================
    // [J] Strict kickoff_at < target (exact boundary excluded)
    // =========================================================================

    public function test_match_at_exact_target_kickoff_is_excluded_by_strict_less_than(): void
    {
        // Covered by test_target_match_kickoff_is_not_counted_strict_less_than above.
        // This test verifies a match for another team at the same timestamp is also
        // excluded from the target team's query (sanity: strict < applies globally).
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        // A different match at exact same time involving teamA (e.g. replayed fixture)
        // kickoff_at is NOT < target → excluded.
        $this->makeMatch($this->teamA->id, $this->teamC->id, '2026-09-10 20:45:00');

        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertNull($result['rest_days']);
        $this->assertSame(0, $result['matches_last_7_days']);
    }

    // =========================================================================
    // [K] No contamination between teams
    // =========================================================================

    public function test_other_teams_matches_do_not_contaminate_result(): void
    {
        // teamB vs teamC played 3 days ago — teamA was not involved
        $this->makeMatch($this->teamB->id, $this->teamC->id, '2026-09-07 20:45:00');

        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        // Querying for teamA should return zero history
        $result = $this->loadFor($target, $this->teamA->id);

        $this->assertNull($result['rest_days'],   'teamA has no previous matches; other teams must not contaminate');
        $this->assertSame(0, $result['matches_last_7_days']);
    }

    public function test_home_and_away_team_counts_are_independent(): void
    {
        // teamA played 3 days ago. teamB played 1 day ago.
        // Results for teamA and teamB must be independent.
        $this->makeMatch($this->teamA->id, $this->teamC->id, '2026-09-07 20:45:00'); // teamA 3 days ago
        $this->makeMatch($this->teamB->id, $this->teamC->id, '2026-09-09 20:45:00'); // teamB 1 day ago
        $target = $this->makeMatch($this->teamA->id, $this->teamB->id, '2026-09-10 20:45:00');

        $resultA = $this->loadFor($target, $this->teamA->id);
        $resultB = $this->loadFor($target, $this->teamB->id);

        $this->assertSame(3, $resultA['rest_days'], 'teamA rested 3 days');
        $this->assertSame(1, $resultB['rest_days'], 'teamB rested 1 day');
        $this->assertSame(1, $resultA['matches_last_7_days'], 'teamA: 1 match in 7 days');
        $this->assertSame(1, $resultB['matches_last_7_days'], 'teamB: 1 match in 7 days');
    }

    // =========================================================================
    // [pure] Calculator arithmetic without DB (Collection built in-memory)
    // =========================================================================

    public function test_pure_empty_collection_returns_nulls_and_zeros(): void
    {
        $result = TeamScheduleLoadCalculator::calculate(collect(), Carbon::parse('2026-09-10 20:45:00'));

        $this->assertNull($result['rest_days']);
        $this->assertSame(0, $result['matches_last_7_days']);
        $this->assertSame(0, $result['matches_last_14_days']);
        $this->assertSame(0, $result['matches_last_30_days']);
    }

    public function test_pure_single_match_at_various_distances(): void
    {
        $target = Carbon::parse('2026-09-10 20:45:00');

        // 3 days before
        $result = TeamScheduleLoadCalculator::calculate(
            $this->pureCollection(['2026-09-07 20:45:00']),
            $target,
        );
        $this->assertSame(3, $result['rest_days']);
        $this->assertSame(1, $result['matches_last_7_days']);
        $this->assertSame(1, $result['matches_last_14_days']);
        $this->assertSame(1, $result['matches_last_30_days']);

        // 31 days before — outside all windows
        $result = TeamScheduleLoadCalculator::calculate(
            $this->pureCollection(['2026-08-10 20:45:00']),
            $target,
        );
        $this->assertSame(31, $result['rest_days']);
        $this->assertSame(0, $result['matches_last_7_days']);
        $this->assertSame(0, $result['matches_last_14_days']);
        $this->assertSame(0, $result['matches_last_30_days']);
    }

    public function test_pure_multiple_matches_correct_most_recent_for_rest(): void
    {
        $target  = Carbon::parse('2026-09-10 20:45:00');
        $matches = $this->pureCollection([
            '2026-09-07 20:45:00', // 3 days
            '2026-09-01 20:45:00', // 9 days
            '2026-08-25 20:45:00', // 16 days
        ]);

        $result = TeamScheduleLoadCalculator::calculate($matches, $target);

        $this->assertSame(3,  $result['rest_days'],            'most recent = 3 days ago');
        $this->assertSame(1,  $result['matches_last_7_days'],  '3d');
        $this->assertSame(2,  $result['matches_last_14_days'], '3d + 9d');
        $this->assertSame(3,  $result['matches_last_30_days'], '3d + 9d + 16d');
    }

    // =========================================================================
    // Internal anti-leakage guard
    // =========================================================================

    /**
     * The calculator must discard items at or after targetKickoff even if the
     * caller passed them in by mistake — two-layer leakage defence.
     */
    public function test_internal_guard_discards_matches_at_and_after_target_kickoff(): void
    {
        $target = Carbon::parse('2026-09-10 20:45:00');

        $collection = $this->pureCollection([
            '2026-09-07 20:45:00', // 3 days before  → VALID
            '2026-09-10 20:45:00', // exactly at target → MUST be discarded
            '2026-09-15 20:45:00', // future          → MUST be discarded
        ]);

        $result = TeamScheduleLoadCalculator::calculate($collection, $target);

        // Only the match 3 days before should be counted.
        $this->assertSame(3, $result['rest_days'],
            'rest_days must use only the pre-target match; target and future must be discarded');
        $this->assertSame(1, $result['matches_last_7_days'],  'only 1 valid match');
        $this->assertSame(1, $result['matches_last_14_days']);
        $this->assertSame(1, $result['matches_last_30_days']);
    }

    public function test_internal_guard_with_only_leaky_matches_returns_null_rest_days(): void
    {
        $target = Carbon::parse('2026-09-10 20:45:00');

        // Collection contains ONLY the target itself and a future match — both invalid.
        $collection = $this->pureCollection([
            '2026-09-10 20:45:00', // exactly at target
            '2026-09-11 18:00:00', // future
        ]);

        $result = TeamScheduleLoadCalculator::calculate($collection, $target);

        $this->assertNull($result['rest_days'],              'no valid previous match → null');
        $this->assertSame(0, $result['matches_last_7_days']);
        $this->assertSame(0, $result['matches_last_14_days']);
        $this->assertSame(0, $result['matches_last_30_days']);
    }
}
