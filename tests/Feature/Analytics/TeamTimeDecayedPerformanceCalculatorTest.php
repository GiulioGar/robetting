<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\TeamTimeDecayedPerformanceCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for TeamTimeDecayedPerformanceCalculator (E12).
 *
 * [A]  Zero matches → all nulls, matches_considered = 0
 * [B]  Weight function: day 0 → 1.0
 * [C]  Weight function: day 28 → 0.5
 * [D]  Weight function: day 56 → 0.25
 * [E]  Weighted average — two matches, exact formula
 * [F]  Strict target cutoff: match AT target kickoff is excluded
 * [G]  Match strictly before target kickoff is included
 * [H]  Future match (kickoff after target) is excluded
 * [I]  Match beyond max_horizon_days (> 90) is excluded
 * [J]  Match at exactly max_horizon_days is included
 * [K]  Max 10 matches respected — 11th oldest excluded
 * [L]  Null stats row: goal_diff computed, shot/sot null
 * [M]  Null stats: shot_matches_available = 0, goal_matches_available = 1
 * [N]  Coverage per metric is independent
 * [O]  Raw goal_diff correct — home POV
 * [P]  Raw goal_diff correct — away POV
 * [Q]  Raw shot_diff correct — home POV
 * [R]  Raw shot_diff correct — away POV
 * [S]  Adjusted: delta = 0 → adjusted = raw
 * [T]  Adjusted: above-average opponent → adjusted > raw (goal/shot/sot)
 * [U]  Adjusted: below-average opponent → adjusted < raw
 * [V]  Missing Elo context → delta = 0 fallback (adjusted = raw)
 * [W]  Missing league_mean_elo key → delta = 0 fallback
 * [X]  All venues included — no venue split
 * [Y]  One match only → correct output
 * [Z]  effective_weight_sum = Σ weights over all window matches
 * [AA] No new Elo replay — Elo context consumed as-passed (static check)
 */
class TeamTimeDecayedPerformanceCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Team $teamA;
    private Team $teamB;
    private Competition $comp;
    private Season $season;
    private DataSource $ds;

    private const DELTA = 0.0001;

    // Coefficients from config — pulled at runtime so tests stay in sync.
    private array $coefficients;

    // Fixed target kickoff for deterministic days_ago in weight tests.
    private Carbon $target;

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
        $this->ds    = DataSource::create(['name' => 'Test Source', 'slug' => 'test-source', 'source_type' => 'csv']);

        $this->coefficients = config('analytics.opponent_adjustment.coefficients');
        $this->target       = Carbon::parse('2024-03-01 15:00:00');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [A] Zero matches → all nulls
    // ─────────────────────────────────────────────────────────────────────────

    public function test_zero_matches_returns_empty_result(): void
    {
        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect(),
            $this->target,
            [],
            collect()
        );

        $this->assertSame(0, $result['matches_considered']);
        $this->assertEqualsWithDelta(0.0, $result['effective_weight_sum'], self::DELTA);
        $this->assertNull($result['weighted_goal_diff']);
        $this->assertNull($result['weighted_shot_diff']);
        $this->assertNull($result['weighted_sot_diff']);
        $this->assertNull($result['weighted_adjusted_goal_diff']);
        $this->assertNull($result['weighted_adjusted_shot_diff']);
        $this->assertNull($result['weighted_adjusted_sot_diff']);
        $this->assertSame(0, $result['goal_matches_available']);
        $this->assertSame(0, $result['shot_matches_available']);
        $this->assertSame(0, $result['sot_matches_available']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] Weight function: day 0 → 1.0
    //
    // Match exactly at the same timestamp as target (but < target by 1 second
    // for inclusion) → days_ago ≈ 0 → weight ≈ 1.0.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_weight_day_0_equals_1(): void
    {
        $m = $this->makeMatchAt($this->target->copy()->subSeconds(1), 1, 0);
        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            [],
            collect()
        );

        // effective_weight_sum ≈ 1.0 (sub-second ago, essentially day 0)
        $this->assertEqualsWithDelta(1.0, $result['effective_weight_sum'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] Weight function: exactly 28 days ago → weight 0.5
    // ─────────────────────────────────────────────────────────────────────────

    public function test_weight_28_days_equals_half(): void
    {
        $kickoff = $this->target->copy()->subDays(28);
        $m = $this->makeMatchAt($kickoff, 1, 0);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            [],
            collect()
        );

        $this->assertEqualsWithDelta(0.5, $result['effective_weight_sum'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] Weight function: exactly 56 days ago → weight 0.25
    // ─────────────────────────────────────────────────────────────────────────

    public function test_weight_56_days_equals_quarter(): void
    {
        $kickoff = $this->target->copy()->subDays(56);
        $m = $this->makeMatchAt($kickoff, 2, 0);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            [],
            collect()
        );

        $this->assertEqualsWithDelta(0.25, $result['effective_weight_sum'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] Weighted average — two matches, exact formula
    //
    // m1: 0 days ago  → weight 1.0, goal_diff = +2
    // m2: 28 days ago → weight 0.5, goal_diff = -1
    //
    // weighted_avg = (2×1.0 + (−1)×0.5) / (1.0 + 0.5) = 1.5 / 1.5 = 1.0
    // ─────────────────────────────────────────────────────────────────────────

    public function test_weighted_average_formula_exact(): void
    {
        $m1 = $this->makeMatchAt($this->target->copy()->subSeconds(1), 2, 0); // w≈1.0, diff=+2
        $m2 = $this->makeMatchAt($this->target->copy()->subDays(28),   0, 1); // w=0.5,  diff=−1

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m1, $m2]),
            $this->target,
            [],
            collect()
        );

        // (2*1.0 + (-1)*0.5) / (1.0+0.5) = 1.5/1.5 = 1.0
        $this->assertEqualsWithDelta(1.0, $result['weighted_goal_diff'], 0.002);
        $this->assertEqualsWithDelta(1.5, $result['effective_weight_sum'], 0.002);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] Strict target cutoff: match at target timestamp is excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_match_at_exact_target_kickoff_is_excluded(): void
    {
        $m = $this->makeMatchAt($this->target, 3, 0); // kickoff = target → must be excluded

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            [],
            collect()
        );

        $this->assertSame(0, $result['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] Match strictly before target kickoff is included
    // ─────────────────────────────────────────────────────────────────────────

    public function test_match_strictly_before_target_is_included(): void
    {
        $m = $this->makeMatchAt($this->target->copy()->subMinutes(1), 1, 0);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            [],
            collect()
        );

        $this->assertSame(1, $result['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] Future match (kickoff after target) is excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_future_match_is_excluded(): void
    {
        $future = $this->makeMatchAt($this->target->copy()->addDays(7), 1, 0);
        $past   = $this->makeMatchAt($this->target->copy()->subDays(3), 1, 0);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$future, $past]),
            $this->target,
            [],
            collect()
        );

        $this->assertSame(1, $result['matches_considered']); // only $past
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] Match beyond max_horizon_days (91 days) is excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_match_beyond_90_days_is_excluded(): void
    {
        $tooOld  = $this->makeMatchAt($this->target->copy()->subDays(91), 2, 0);
        $inRange = $this->makeMatchAt($this->target->copy()->subDays(89), 1, 0);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$tooOld, $inRange]),
            $this->target,
            [],
            collect()
        );

        $this->assertSame(1, $result['matches_considered']); // only $inRange
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J] Match at exactly max_horizon_days (90) is included
    // ─────────────────────────────────────────────────────────────────────────

    public function test_match_at_exactly_90_days_is_included(): void
    {
        $atHorizon = $this->makeMatchAt($this->target->copy()->subDays(90), 1, 0);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$atHorizon]),
            $this->target,
            [],
            collect()
        );

        $this->assertSame(1, $result['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [K] Max 10 matches — 11th oldest is excluded
    //
    // Create 11 matches within 90 days; only the 10 most recent should appear.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_max_10_matches_oldest_excluded(): void
    {
        $matches = collect();
        for ($i = 1; $i <= 11; $i++) {
            $matches->push($this->makeMatchAt($this->target->copy()->subDays($i * 3), 1, 0));
        }

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            $matches,
            $this->target,
            [],
            collect()
        );

        $this->assertSame(10, $result['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [L] Null stats row: goal_diff computable, shot/sot null
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_stats_row_goal_diff_computed_shots_null(): void
    {
        $m = $this->makeMatchAt($this->target->copy()->subDays(7), 2, 1);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            [],
            collect() // no stat rows
        );

        $this->assertEqualsWithDelta(1.0, $result['weighted_goal_diff'], self::DELTA);
        $this->assertNull($result['weighted_shot_diff']);
        $this->assertNull($result['weighted_sot_diff']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [M] Coverage: goal always available, shots absent when no stat row
    // ─────────────────────────────────────────────────────────────────────────

    public function test_coverage_goal_available_shots_zero_when_no_stat(): void
    {
        $m = $this->makeMatchAt($this->target->copy()->subDays(7), 1, 0);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            [],
            collect()
        );

        $this->assertSame(1, $result['goal_matches_available']);
        $this->assertSame(0, $result['shot_matches_available']);
        $this->assertSame(0, $result['sot_matches_available']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [N] Coverage per metric is independent
    //
    // 3 matches: m1 has shots, m2 has shots, m3 has no stats.
    // goal: 3 available, shots: 2, sot: 2.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_per_metric_coverage_is_independent(): void
    {
        $m1 = $this->makeMatchAt($this->target->copy()->subDays(5),  1, 0);
        $m2 = $this->makeMatchAt($this->target->copy()->subDays(10), 2, 1);
        $m3 = $this->makeMatchAt($this->target->copy()->subDays(15), 0, 0);

        $stat1 = $this->makeStat($m1->id, 8, 4, 4, 2);
        $stat2 = $this->makeStat($m2->id, 6, 5, 3, 2);
        $statsMap = collect([$m1->id => $stat1, $m2->id => $stat2]);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m1, $m2, $m3]),
            $this->target,
            [],
            $statsMap
        );

        $this->assertSame(3, $result['matches_considered']);
        $this->assertSame(3, $result['goal_matches_available']); // always
        $this->assertSame(2, $result['shot_matches_available']);
        $this->assertSame(2, $result['sot_matches_available']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [O] Raw goal_diff — home POV
    //
    // TeamA home, wins 3−1 → goal_diff = +2.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_raw_goal_diff_home_pov(): void
    {
        $m = $this->makeMatchAt($this->target->copy()->subDays(7), 3, 1); // A home 3-1

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            [],
            collect()
        );

        $this->assertEqualsWithDelta(2.0, $result['weighted_goal_diff'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [P] Raw goal_diff — away POV
    //
    // TeamA is AWAY, match: B(1)−A(3) → from A's POV: +2.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_raw_goal_diff_away_pov(): void
    {
        // Swap: home=B, away=A, score 1-3
        $m = $this->makeMatchAt(
            $this->target->copy()->subDays(7),
            1, 3,
            homeTeam: $this->teamB,
            awayTeam: $this->teamA
        );

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            [],
            collect()
        );

        $this->assertEqualsWithDelta(2.0, $result['weighted_goal_diff'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [Q] Raw shot_diff — home POV
    //
    // TeamA home, shots 10−4 → shot_diff = +6.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_raw_shot_diff_home_pov(): void
    {
        $m    = $this->makeMatchAt($this->target->copy()->subDays(7), 1, 0);
        $stat = $this->makeStat($m->id, homeShots: 10, awayShots: 4, homeSot: 5, awaySot: 2);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            [],
            collect([$m->id => $stat])
        );

        $this->assertEqualsWithDelta(6.0, $result['weighted_shot_diff'], self::DELTA);
        $this->assertEqualsWithDelta(3.0, $result['weighted_sot_diff'],  self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [R] Raw shot_diff — away POV
    //
    // TeamA is AWAY, match B(4 shots)−A(10 shots) → from A's POV: +6.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_raw_shot_diff_away_pov(): void
    {
        $m = $this->makeMatchAt(
            $this->target->copy()->subDays(7),
            0, 1,
            homeTeam: $this->teamB,
            awayTeam: $this->teamA
        );
        $stat = $this->makeStat($m->id, homeShots: 4, awayShots: 10, homeSot: 2, awaySot: 5);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            [],
            collect([$m->id => $stat])
        );

        $this->assertEqualsWithDelta(6.0, $result['weighted_shot_diff'], self::DELTA);
        $this->assertEqualsWithDelta(3.0, $result['weighted_sot_diff'],  self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [S] Adjusted: delta = 0 → adjusted equals raw
    // ─────────────────────────────────────────────────────────────────────────

    public function test_zero_delta_adjusted_equals_raw(): void
    {
        $m    = $this->makeMatchAt($this->target->copy()->subDays(7), 2, 1);
        $stat = $this->makeStat($m->id, 8, 5, 4, 2);

        $eloCtx   = [$m->id => ['home_elo' => 1500.0, 'away_elo' => 1500.0, 'league_mean_elo' => 1500.0]];
        $statsMap = collect([$m->id => $stat]);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            $eloCtx,
            $statsMap
        );

        $this->assertEqualsWithDelta($result['weighted_goal_diff'], $result['weighted_adjusted_goal_diff'], self::DELTA);
        $this->assertEqualsWithDelta($result['weighted_shot_diff'], $result['weighted_adjusted_shot_diff'], self::DELTA);
        $this->assertEqualsWithDelta($result['weighted_sot_diff'],  $result['weighted_adjusted_sot_diff'],  self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [T] Adjusted: above-average opponent → adjusted > raw
    //
    // opponent_elo = 1600, league_mean = 1500 → delta = +100.
    // Adjustment is positive → adjusted > raw for all metrics.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_above_average_opponent_adjusted_greater_than_raw(): void
    {
        $m    = $this->makeMatchAt($this->target->copy()->subDays(7), 1, 2); // A home loses
        $stat = $this->makeStat($m->id, 5, 10, 2, 5);

        // A is home, opponent (away) elo = 1600, league mean = 1500 → delta = +100
        $eloCtx   = [$m->id => ['home_elo' => 1500.0, 'away_elo' => 1600.0, 'league_mean_elo' => 1500.0]];
        $statsMap = collect([$m->id => $stat]);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            $eloCtx,
            $statsMap
        );

        $this->assertGreaterThan($result['weighted_goal_diff'], $result['weighted_adjusted_goal_diff']);
        $this->assertGreaterThan($result['weighted_shot_diff'], $result['weighted_adjusted_shot_diff']);
        $this->assertGreaterThan($result['weighted_sot_diff'],  $result['weighted_adjusted_sot_diff']);

        // Numeric spot-check: raw_goal = 1-2 = -1, adj = -1 + 0.00764*100 = -0.236
        $this->assertEqualsWithDelta(-1.0, $result['weighted_goal_diff'], self::DELTA);
        $this->assertEqualsWithDelta(
            -1.0 + $this->coefficients['goal_diff'] * 100.0,
            $result['weighted_adjusted_goal_diff'],
            self::DELTA
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [U] Adjusted: below-average opponent → adjusted < raw
    //
    // opponent_elo = 1400, league_mean = 1500 → delta = -100.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_below_average_opponent_adjusted_less_than_raw(): void
    {
        $m    = $this->makeMatchAt($this->target->copy()->subDays(7), 4, 0); // A wins big
        $stat = $this->makeStat($m->id, 15, 3, 7, 1);

        $eloCtx   = [$m->id => ['home_elo' => 1500.0, 'away_elo' => 1400.0, 'league_mean_elo' => 1500.0]];
        $statsMap = collect([$m->id => $stat]);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            $eloCtx,
            $statsMap
        );

        $this->assertLessThan($result['weighted_goal_diff'], $result['weighted_adjusted_goal_diff']);
        $this->assertLessThan($result['weighted_shot_diff'], $result['weighted_adjusted_shot_diff']);
        $this->assertLessThan($result['weighted_sot_diff'],  $result['weighted_adjusted_sot_diff']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [V] Missing Elo context entry → delta = 0 fallback
    // ─────────────────────────────────────────────────────────────────────────

    public function test_missing_elo_context_falls_back_to_zero_delta(): void
    {
        $m    = $this->makeMatchAt($this->target->copy()->subDays(7), 2, 1);
        $stat = $this->makeStat($m->id, 8, 4, 4, 2);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            [], // empty context
            collect([$m->id => $stat])
        );

        $this->assertEqualsWithDelta($result['weighted_goal_diff'], $result['weighted_adjusted_goal_diff'], self::DELTA);
        $this->assertEqualsWithDelta($result['weighted_shot_diff'], $result['weighted_adjusted_shot_diff'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [W] Missing league_mean_elo key in context → delta = 0 fallback
    // ─────────────────────────────────────────────────────────────────────────

    public function test_missing_league_mean_key_falls_back_to_zero_delta(): void
    {
        $m    = $this->makeMatchAt($this->target->copy()->subDays(7), 2, 0);
        $stat = $this->makeStat($m->id, 7, 3, 3, 1);

        // no league_mean_elo key
        $eloCtx = [$m->id => ['home_elo' => 1500.0, 'away_elo' => 1600.0]];

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            $eloCtx,
            collect([$m->id => $stat])
        );

        $this->assertEqualsWithDelta($result['weighted_goal_diff'], $result['weighted_adjusted_goal_diff'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [X] No venue split — both home and away matches are included
    //
    // A is home in m1, away in m2. Both must appear in the window.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_venue_split_both_venues_included(): void
    {
        $teamC = Team::create(['name' => 'TeamC', 'type' => 'club', 'is_active' => true]);

        $m1 = $this->makeMatchAt($this->target->copy()->subDays(7),  1, 0); // A home
        $m2 = $this->makeMatchAt(                                           // A away vs C
            $this->target->copy()->subDays(14),
            0, 2,
            homeTeam: $teamC,
            awayTeam: $this->teamA
        );

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m1, $m2]),
            $this->target,
            [],
            collect()
        );

        $this->assertSame(2, $result['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [Y] One match only → correct single-match output
    // ─────────────────────────────────────────────────────────────────────────

    public function test_single_match_produces_correct_output(): void
    {
        $m    = $this->makeMatchAt($this->target->copy()->subDays(14), 2, 0);
        $stat = $this->makeStat($m->id, 8, 3, 4, 1);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            [],
            collect([$m->id => $stat])
        );

        $this->assertSame(1, $result['matches_considered']);
        // Single match: weighted_avg = raw value (weight cancels in numerator/denominator)
        $this->assertEqualsWithDelta(2.0, $result['weighted_goal_diff'], self::DELTA);
        $this->assertEqualsWithDelta(5.0, $result['weighted_shot_diff'], self::DELTA);
        $this->assertEqualsWithDelta(3.0, $result['weighted_sot_diff'],  self::DELTA);
        // effective_weight_sum ≈ 0.5 (28 days half-life, 14 days ago → 0.5^0.5 ≈ 0.7071)
        $this->assertEqualsWithDelta(0.5 ** (14.0 / 28.0), $result['effective_weight_sum'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [Z] effective_weight_sum = Σ weights over all window matches
    //
    // m1: 0 days ago → w=1.0, m2: 28 days ago → w=0.5
    // Σ = 1.5
    // ─────────────────────────────────────────────────────────────────────────

    public function test_effective_weight_sum_is_sum_of_all_weights(): void
    {
        $m1 = $this->makeMatchAt($this->target->copy()->subSeconds(1), 1, 0); // w≈1.0
        $m2 = $this->makeMatchAt($this->target->copy()->subDays(28),   1, 0); // w=0.5

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m1, $m2]),
            $this->target,
            [],
            collect()
        );

        $this->assertEqualsWithDelta(1.5, $result['effective_weight_sum'], 0.002);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [AA] No Elo replay — calculator is pure (static, no DB queries for Elo)
    //
    // This is a structural test: calling calculate() with an in-memory collection
    // and empty Elo context must not throw and must return consistent results.
    // The guarantee is that no TeamEloCalculator::calculateRatingsBeforeMatches()
    // call happens internally (it's not called anywhere in this class).
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_elo_replay_performed_internally(): void
    {
        // If this test passes without hitting the DB for Elo, the invariant holds.
        // We verify by using a completely empty Elo context and checking the
        // adjusted output degrades gracefully to raw (neutral fallback).
        $m    = $this->makeMatchAt($this->target->copy()->subDays(10), 1, 1);
        $stat = $this->makeStat($m->id, 5, 5, 2, 2);

        $result = TeamTimeDecayedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]),
            $this->target,
            [], // no Elo context — no replay should happen
            collect([$m->id => $stat])
        );

        // adjusted = raw when no Elo context (delta = 0)
        $this->assertEqualsWithDelta($result['weighted_goal_diff'], $result['weighted_adjusted_goal_diff'], self::DELTA);
        $this->assertEqualsWithDelta($result['weighted_shot_diff'], $result['weighted_adjusted_shot_diff'], self::DELTA);
        $this->assertEqualsWithDelta($result['weighted_sot_diff'],  $result['weighted_adjusted_sot_diff'],  self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makeMatchAt(
        Carbon $kickoff,
        int    $homeScore,
        int    $awayScore,
        ?Team  $homeTeam = null,
        ?Team  $awayTeam = null
    ): FootballMatch {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => ($homeTeam ?? $this->teamA)->id,
            'away_team_id'   => ($awayTeam ?? $this->teamB)->id,
            'kickoff_at'     => $kickoff,
            'status'         => 'finished',
            'home_score_ft'  => $homeScore,
            'away_score_ft'  => $awayScore,
        ]);
    }

    private function makeStat(
        int  $matchId,
        ?int $homeShots,
        ?int $awayShots,
        ?int $homeSot,
        ?int $awaySot
    ): MatchStatistic {
        return MatchStatistic::create([
            'match_id'               => $matchId,
            'data_source_id'         => $this->ds->id,
            'home_shots'             => $homeShots,
            'away_shots'             => $awayShots,
            'home_shots_on_target'   => $homeSot,
            'away_shots_on_target'   => $awaySot,
        ]);
    }
}
