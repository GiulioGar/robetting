<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\TeamOpponentAdjustedPerformanceCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for TeamOpponentAdjustedPerformanceCalculator.
 *
 * Tests:
 *  [A]  Empty window → all nulls, matches_considered = 0
 *  [B]  raw diffs are POV-correct (home perspective)
 *  [C]  raw diffs are POV-correct (away perspective)
 *  [D]  delta = 0 → adjusted = raw (neutral opponent)
 *  [E]  above-average opponent (delta > 0) → adjustment pushes values upward
 *  [F]  below-average opponent (delta < 0) → adjustment pushes values downward
 *  [G]  null stats → shot/sot null, goal_diff always computed
 *  [H]  null stats → adjusted also null (no null-to-zero promotion)
 *  [I]  coverage counts only non-null stat matches
 *  [J]  matches_considered across last5/last10/venue
 *  [K]  avg_adjustment = adjusted_avg − raw_avg
 *  [L]  missing eloContext entry → delta = 0 (neutral fallback)
 *  [M]  missing league_mean_elo key → delta = 0 (neutral fallback)
 *  [N]  single batch: all windows share one consistent statsMap lookup
 */
class TeamOpponentAdjustedPerformanceCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Team $teamA;
    private Team $teamB;
    private Competition $comp;
    private Season $season;
    private DataSource $ds;

    private const DELTA = 0.0001;

    // Coefficients from config — pulled at runtime so tests stay in sync.
    // (config/analytics.php opponent_adjustment.coefficients)
    private array $coefficients;

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
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [A] Empty window → all nulls
    // ─────────────────────────────────────────────────────────────────────────

    public function test_empty_window_returns_zero_and_nulls(): void
    {
        $result = TeamOpponentAdjustedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect(), collect(), collect(),
            [],
            collect()
        );

        foreach (['last5', 'last10', 'venue'] as $window) {
            $this->assertSame(0, $result[$window]['matches_considered']);
            foreach (['shot_diff', 'sot_diff', 'goal_diff'] as $metric) {
                $this->assertNull($result[$window][$metric . '_raw_avg']);
                $this->assertNull($result[$window][$metric . '_adjusted_avg']);
                $this->assertNull($result[$window][$metric . '_avg_adjustment']);
                $this->assertSame(0, $result[$window][$metric . '_coverage']);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] raw diffs are POV-correct — home perspective
    //
    // TeamA is HOME.  Match: A(2)–B(1), shots 8–5, SOT 4–2.
    // From A's POV: goal_diff = +1, shot_diff = +3, sot_diff = +2.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_raw_diffs_home_perspective(): void
    {
        $m = $this->makeMatch(2, 1, 0); // A home, A wins 2-1

        $stat = $this->makeStat($m->id, homeShots: 8, awayShots: 5, homeSot: 4, awaySot: 2);

        $eloCtx  = [$m->id => ['home_elo' => 1500.0, 'away_elo' => 1500.0, 'league_mean_elo' => 1500.0]];
        $statsMap = collect([$m->id => $stat]);

        $result = TeamOpponentAdjustedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]), collect([$m]), collect([$m]),
            $eloCtx, $statsMap
        );

        // delta = 0 (opponent at league mean) → adjusted = raw
        $this->assertEqualsWithDelta(1.0,  $result['last5']['goal_diff_raw_avg'],  self::DELTA);
        $this->assertEqualsWithDelta(3.0,  $result['last5']['shot_diff_raw_avg'],  self::DELTA);
        $this->assertEqualsWithDelta(2.0,  $result['last5']['sot_diff_raw_avg'],   self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] raw diffs are POV-correct — away perspective
    //
    // TeamA is AWAY.  Match: B(1)–A(3), shots 5–8, SOT 2–4.
    // From A's POV: goal_diff = +2, shot_diff = +3, sot_diff = +2.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_raw_diffs_away_perspective(): void
    {
        // A is away, B is home — swap: home=B, away=A, scores: home 1, away 3
        $m = $this->makeMatch(1, 3, 0, homeTeam: $this->teamB, awayTeam: $this->teamA);

        $stat = $this->makeStat($m->id, homeShots: 5, awayShots: 8, homeSot: 2, awaySot: 4);

        $eloCtx   = [$m->id => ['home_elo' => 1500.0, 'away_elo' => 1500.0, 'league_mean_elo' => 1500.0]];
        $statsMap = collect([$m->id => $stat]);

        $result = TeamOpponentAdjustedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]), collect([$m]), collect([$m]),
            $eloCtx, $statsMap
        );

        $this->assertEqualsWithDelta(2.0, $result['last5']['goal_diff_raw_avg'],  self::DELTA);
        $this->assertEqualsWithDelta(3.0, $result['last5']['shot_diff_raw_avg'],  self::DELTA);
        $this->assertEqualsWithDelta(2.0, $result['last5']['sot_diff_raw_avg'],   self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] delta = 0 → adjusted = raw exactly
    //
    // Opponent Elo = league mean Elo → delta = 0 → adjustment = 0.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_zero_delta_adjusted_equals_raw(): void
    {
        $m    = $this->makeMatch(2, 0, 0);
        $stat = $this->makeStat($m->id, homeShots: 6, awayShots: 3, homeSot: 3, awaySot: 1);

        // opponent Elo = league mean → delta = 0
        $eloCtx   = [$m->id => ['home_elo' => 1500.0, 'away_elo' => 1500.0, 'league_mean_elo' => 1500.0]];
        $statsMap = collect([$m->id => $stat]);

        $result = TeamOpponentAdjustedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]), collect([$m]), collect([$m]),
            $eloCtx, $statsMap
        );

        foreach (['shot_diff', 'sot_diff', 'goal_diff'] as $metric) {
            $this->assertEqualsWithDelta(
                $result['last5'][$metric . '_raw_avg'],
                $result['last5'][$metric . '_adjusted_avg'],
                self::DELTA,
                "$metric: adjusted should equal raw when delta=0"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] above-average opponent → adjustment positive → adjusted > raw
    //
    // opponent_elo = 1600, league_mean = 1500 → delta = +100.
    // coeff_shot = 0.04170 → shot_adjustment = +4.17 per match.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_above_average_opponent_pushes_adjusted_upward(): void
    {
        $m    = $this->makeMatch(1, 0, 0); // A(home) wins 1-0
        $stat = $this->makeStat($m->id, homeShots: 4, awayShots: 7, homeSot: 2, awaySot: 3);

        $eloCtx   = [$m->id => ['home_elo' => 1500.0, 'away_elo' => 1600.0, 'league_mean_elo' => 1500.0]];
        $statsMap = collect([$m->id => $stat]);

        $result = TeamOpponentAdjustedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]), collect([$m]), collect([$m]),
            $eloCtx, $statsMap
        );

        $window = $result['last5'];

        $this->assertGreaterThan($window['shot_diff_raw_avg'],  $window['shot_diff_adjusted_avg']);
        $this->assertGreaterThan($window['sot_diff_raw_avg'],   $window['sot_diff_adjusted_avg']);
        $this->assertGreaterThan($window['goal_diff_raw_avg'],  $window['goal_diff_adjusted_avg']);

        // Numeric spot-check for shot_diff: raw = 4-7 = -3, adjustment = 0.04170*100 = 4.17
        $this->assertEqualsWithDelta(-3.0, $window['shot_diff_raw_avg'], self::DELTA);
        $this->assertEqualsWithDelta(-3.0 + $this->coefficients['shot_diff'] * 100.0,
            $window['shot_diff_adjusted_avg'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] below-average opponent → adjustment negative → adjusted < raw
    //
    // opponent_elo = 1400, league_mean = 1500 → delta = −100.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_below_average_opponent_pushes_adjusted_downward(): void
    {
        $m    = $this->makeMatch(3, 0, 0); // A wins big
        $stat = $this->makeStat($m->id, homeShots: 12, awayShots: 3, homeSot: 6, awaySot: 1);

        $eloCtx   = [$m->id => ['home_elo' => 1500.0, 'away_elo' => 1400.0, 'league_mean_elo' => 1500.0]];
        $statsMap = collect([$m->id => $stat]);

        $result = TeamOpponentAdjustedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]), collect([$m]), collect([$m]),
            $eloCtx, $statsMap
        );

        $window = $result['last5'];

        $this->assertLessThan($window['shot_diff_raw_avg'],  $window['shot_diff_adjusted_avg']);
        $this->assertLessThan($window['sot_diff_raw_avg'],   $window['sot_diff_adjusted_avg']);
        $this->assertLessThan($window['goal_diff_raw_avg'],  $window['goal_diff_adjusted_avg']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] null stats → shot/sot null, goal_diff always computed from FT score
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_stats_row_gives_null_shots_but_goal_diff_computed(): void
    {
        $m = $this->makeMatch(2, 1, 0);

        $eloCtx   = [$m->id => ['home_elo' => 1500.0, 'away_elo' => 1500.0, 'league_mean_elo' => 1500.0]];
        $statsMap = collect(); // no stat row

        $result = TeamOpponentAdjustedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]), collect([$m]), collect([$m]),
            $eloCtx, $statsMap
        );

        $window = $result['last5'];

        // goal_diff is always computable from FT score
        $this->assertEqualsWithDelta(1.0, $window['goal_diff_raw_avg'],      self::DELTA);
        $this->assertEqualsWithDelta(1.0, $window['goal_diff_adjusted_avg'], self::DELTA);
        $this->assertSame(1, $window['goal_diff_coverage']);

        // shot/sot null when no stat row
        $this->assertNull($window['shot_diff_raw_avg']);
        $this->assertNull($window['shot_diff_adjusted_avg']);
        $this->assertSame(0, $window['shot_diff_coverage']);

        $this->assertNull($window['sot_diff_raw_avg']);
        $this->assertNull($window['sot_diff_adjusted_avg']);
        $this->assertSame(0, $window['sot_diff_coverage']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] null adjusted when stats null — never promoted to 0
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_stats_produces_null_adjusted_not_zero(): void
    {
        $m = $this->makeMatch(1, 0, 0);

        $eloCtx   = [$m->id => ['home_elo' => 1500.0, 'away_elo' => 1600.0, 'league_mean_elo' => 1500.0]];
        $statsMap = collect(); // no stat row

        $result = TeamOpponentAdjustedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]), collect([$m]), collect([$m]),
            $eloCtx, $statsMap
        );

        $this->assertNull($result['last5']['shot_diff_adjusted_avg'],
            'adjusted_avg must be null (not 0) when raw is null');
        $this->assertNull($result['last5']['sot_diff_adjusted_avg'],
            'adjusted_avg must be null (not 0) when raw is null');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] coverage counts only non-null stat matches
    //
    // 3 matches in window, 2 have stat rows → shot_coverage = 2.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_coverage_counts_non_null_stat_matches(): void
    {
        $m1 = $this->makeMatch(2, 0, 10);
        $m2 = $this->makeMatch(1, 1, 7);
        $m3 = $this->makeMatch(0, 1, 4);

        // Only m1 and m2 have stat rows; m3 does not.
        $stat1 = $this->makeStat($m1->id, homeShots: 8, awayShots: 4, homeSot: 4, awaySot: 2);
        $stat2 = $this->makeStat($m2->id, homeShots: 6, awayShots: 5, homeSot: 3, awaySot: 2);

        $eloCtx = [
            $m1->id => ['home_elo' => 1500.0, 'away_elo' => 1500.0, 'league_mean_elo' => 1500.0],
            $m2->id => ['home_elo' => 1500.0, 'away_elo' => 1500.0, 'league_mean_elo' => 1500.0],
            $m3->id => ['home_elo' => 1500.0, 'away_elo' => 1500.0, 'league_mean_elo' => 1500.0],
        ];
        $statsMap = collect([$m1->id => $stat1, $m2->id => $stat2]);

        $result = TeamOpponentAdjustedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m1, $m2, $m3]), collect([$m1, $m2, $m3]), collect([$m1, $m2, $m3]),
            $eloCtx, $statsMap
        );

        $this->assertSame(3, $result['last5']['matches_considered']);
        $this->assertSame(2, $result['last5']['shot_diff_coverage']);
        $this->assertSame(2, $result['last5']['sot_diff_coverage']);
        $this->assertSame(3, $result['last5']['goal_diff_coverage']); // always covered
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J] matches_considered and window slicing
    //
    // last5 has 2 matches, last10 has 3, venue has 1.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_matches_considered_per_window(): void
    {
        $m1 = $this->makeMatch(1, 0, 20);
        $m2 = $this->makeMatch(1, 0, 13);
        $m3 = $this->makeMatch(1, 0, 6);

        $eloCtx = [
            $m1->id => ['home_elo' => 1500.0, 'away_elo' => 1500.0, 'league_mean_elo' => 1500.0],
            $m2->id => ['home_elo' => 1500.0, 'away_elo' => 1500.0, 'league_mean_elo' => 1500.0],
            $m3->id => ['home_elo' => 1500.0, 'away_elo' => 1500.0, 'league_mean_elo' => 1500.0],
        ];
        $statsMap = collect();

        $result = TeamOpponentAdjustedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m2, $m3]),          // last5:  2 matches
            collect([$m1, $m2, $m3]),     // last10: 3 matches
            collect([$m3]),               // venue:  1 match
            $eloCtx, $statsMap
        );

        $this->assertSame(2, $result['last5']['matches_considered']);
        $this->assertSame(3, $result['last10']['matches_considered']);
        $this->assertSame(1, $result['venue']['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [K] avg_adjustment = adjusted_avg − raw_avg
    // ─────────────────────────────────────────────────────────────────────────

    public function test_avg_adjustment_equals_adjusted_minus_raw(): void
    {
        $m    = $this->makeMatch(1, 0, 0);
        $stat = $this->makeStat($m->id, homeShots: 5, awayShots: 8, homeSot: 2, awaySot: 4);

        $eloCtx   = [$m->id => ['home_elo' => 1500.0, 'away_elo' => 1650.0, 'league_mean_elo' => 1500.0]];
        $statsMap = collect([$m->id => $stat]);

        $result = TeamOpponentAdjustedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]), collect([$m]), collect([$m]),
            $eloCtx, $statsMap
        );

        foreach (['shot_diff', 'sot_diff', 'goal_diff'] as $metric) {
            $raw = $result['last5'][$metric . '_raw_avg'];
            $adj = $result['last5'][$metric . '_adjusted_avg'];
            $exp = $result['last5'][$metric . '_avg_adjustment'];

            if ($raw !== null && $adj !== null) {
                $this->assertEqualsWithDelta(
                    round($adj - $raw, 4),
                    $exp,
                    self::DELTA,
                    "$metric: avg_adjustment must equal adjusted_avg − raw_avg"
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [L] missing eloContext entry → delta = 0 (neutral fallback)
    //
    // If $eloContext has no entry for a match_id, the adjustment is 0 and
    // adjusted = raw.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_missing_elo_context_entry_falls_back_to_zero_delta(): void
    {
        $m    = $this->makeMatch(2, 0, 0);
        $stat = $this->makeStat($m->id, homeShots: 6, awayShots: 3, homeSot: 3, awaySot: 1);

        $statsMap = collect([$m->id => $stat]);

        $result = TeamOpponentAdjustedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]), collect([$m]), collect([$m]),
            [], // empty eloContext
            $statsMap
        );

        foreach (['shot_diff', 'sot_diff', 'goal_diff'] as $metric) {
            $this->assertEqualsWithDelta(
                $result['last5'][$metric . '_raw_avg'],
                $result['last5'][$metric . '_adjusted_avg'],
                self::DELTA,
                "$metric: missing elo context must not produce adjustment"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [M] missing league_mean_elo key in context → delta = 0 (neutral fallback)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_missing_league_mean_key_falls_back_to_zero_delta(): void
    {
        $m    = $this->makeMatch(1, 0, 0);
        $stat = $this->makeStat($m->id, homeShots: 5, awayShots: 3, homeSot: 2, awaySot: 1);

        // Elo context exists but league_mean_elo key is absent.
        $eloCtx   = [$m->id => ['home_elo' => 1500.0, 'away_elo' => 1600.0]]; // no league_mean_elo
        $statsMap = collect([$m->id => $stat]);

        $result = TeamOpponentAdjustedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m]), collect([$m]), collect([$m]),
            $eloCtx, $statsMap
        );

        foreach (['shot_diff', 'sot_diff', 'goal_diff'] as $metric) {
            $this->assertEqualsWithDelta(
                $result['last5'][$metric . '_raw_avg'],
                $result['last5'][$metric . '_adjusted_avg'],
                self::DELTA,
                "$metric: missing league_mean must not produce adjustment"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [N] goal_diff numerical multi-match average
    //
    // last5 = [m1 (+2), m2 (-1)] → goal_diff_raw_avg = 0.5.
    // delta = 0 for both → adjusted_avg = 0.5, avg_adjustment = 0.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_multi_match_average_computed_correctly(): void
    {
        $m1 = $this->makeMatch(2, 0, 14);  // +2
        $m2 = $this->makeMatch(0, 1, 7);   // -1

        $eloCtx = [
            $m1->id => ['home_elo' => 1500.0, 'away_elo' => 1500.0, 'league_mean_elo' => 1500.0],
            $m2->id => ['home_elo' => 1500.0, 'away_elo' => 1500.0, 'league_mean_elo' => 1500.0],
        ];
        $statsMap = collect();

        $result = TeamOpponentAdjustedPerformanceCalculator::calculate(
            $this->teamA->id,
            collect([$m1, $m2]), collect([$m1, $m2]), collect([$m1, $m2]),
            $eloCtx, $statsMap
        );

        $this->assertEqualsWithDelta(0.5, $result['last5']['goal_diff_raw_avg'],      self::DELTA);
        $this->assertEqualsWithDelta(0.5, $result['last5']['goal_diff_adjusted_avg'], self::DELTA);
        $this->assertEqualsWithDelta(0.0, $result['last5']['goal_diff_avg_adjustment'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a finished FootballMatch with TeamA (home) vs TeamB (away) by default.
     * $daysAgo controls the kickoff_at.
     */
    private function makeMatch(
        int  $homeScore,
        int  $awayScore,
        int  $daysAgo,
        ?Team $homeTeam = null,
        ?Team $awayTeam = null
    ): FootballMatch {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => ($homeTeam ?? $this->teamA)->id,
            'away_team_id'   => ($awayTeam ?? $this->teamB)->id,
            'kickoff_at'     => Carbon::now()->subDays($daysAgo),
            'status'         => 'finished',
            'home_score_ft'  => $homeScore,
            'away_score_ft'  => $awayScore,
        ]);
    }

    /**
     * Create a MatchStatistic row for the given match.
     */
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
