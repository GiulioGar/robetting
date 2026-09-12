<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamMarketValueSnapshot;
use App\Services\Analytics\TeamEloCalculator;
use App\Services\Analytics\TeamOpponentQualityCalculator;
use App\Services\Analytics\TeamStructuralRatingCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for TeamOpponentQualityCalculator.
 *
 * Structural uses SEASON BASELINE semantics (not strict per-match as-of):
 *   - Season window = Season.start_date..Season.end_date when set,
 *     otherwise year_start-07-01..year_end-06-30 as fallback.
 *   - First snapshot of the season → valid from season start (retroactive).
 *   - Subsequent snapshots → valid from their snapshot_date.
 *   - Snapshots outside the season window → excluded.
 *   - Elo signal → strict pre-match, unchanged.
 *
 * Tests:
 *
 *  ELO
 *  [A]  Zero matches → all null, matches_considered = 0
 *  [B]  average_opponent_elo correct (3 opponents all at INITIAL_ELO)
 *  [C]  median odd N (3 distinct Elo values → middle value)
 *  [D]  median even N (4 values → average of two middle)
 *  [E]  Elo of opponent is PRE-MATCH (opponent's Elo before that historical match)
 *  [F]  Result of the same match does NOT influence that Elo capture
 *  [G]  Results after the historical match do NOT modify that historical Elo
 *
 *  STRUCTURAL — season baseline, output scale
 *  [H]  Snapshot on same date as match kickoff → used (date equality = ≤)
 *  [I]  Snapshot AFTER kickoff but first of season → used (season baseline)
 *  [J]  Formula not duplicated: rating equals calculateFromMarketValue()
 *  [J2] Numerical: 800M → structural_rating ≈ 1811.916 in Opponent Quality output
 *  [K]  No snapshot at all → null
 *  [L]  Coverage % correct when 3 of 5 opponents have structural
 *
 *  STRUCTURAL — season baseline scenarios
 *  [SB-A]  match before first snapshot, same season → uses first snapshot
 *  [SB-B]  match between first and second snapshot → uses first snapshot
 *  [SB-C]  match after second snapshot → uses second snapshot
 *  [SB-D]  snapshot outside season window → excluded, structural = null
 *
 *  STRUCTURAL — real start_date / end_date boundaries
 *  [SD-A]  Season.start_date set: snapshot before start_date → excluded
 *  [SD-B]  Season.end_date set: snapshot after end_date → excluded
 *  [SD-C]  First snapshot = season start_date exactly → retroactive baseline
 *
 *  WINDOWS
 *  [M]  last5 uses at most 5 matches
 *  [N]  last10 uses at most 10 matches
 *  [O]  venue window: filter-before-lastN semantics preserved by caller
 *  [P]  fewer than 5 matches available → uses real count
 *  [Q]  zero matches in one window → emptyWindow for that window only
 *
 *  ANTI-LEAKAGE
 *  [R]  target match itself excluded (windows contain only prior matches)
 *  [S]  dataSourceId null → all structural fields null
 */
class TeamOpponentQualityCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Team        $team;
    private Competition $comp;
    private Season      $season;   // year_start=2026, year_end=2027, no start/end_date
    private DataSource  $ds;

    private const TARGET = '2026-09-25 20:45:00';
    private const DELTA  = 0.001;

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
        // Default season: no start_date/end_date → fallback 2026-07-01..2027-06-30
        $this->season = Season::create([
            'competition_id' => $this->comp->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);
        $this->team = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);

        $this->ds = DataSource::create([
            'slug'        => 'transfermarkt',
            'name'        => 'Transfermarkt',
            'source_type' => 'manual',
            'is_active'   => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [A] Zero matches → all null, matches_considered = 0
    // ─────────────────────────────────────────────────────────────────────────

    public function test_zero_matches_returns_empty_window(): void
    {
        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, collect(), collect(), collect(), $this->ds->id, $this->season
        );

        foreach (['last5', 'last10', 'venue'] as $window) {
            $w = $result[$window];
            $this->assertSame(0, $w['matches_considered']);
            $this->assertNull($w['average_opponent_elo']);
            $this->assertNull($w['median_opponent_elo']);
            $this->assertNull($w['average_opponent_structural']);
            $this->assertNull($w['median_opponent_structural']);
            $this->assertSame(0, $w['structural_matches_available']);
            $this->assertNull($w['structural_coverage_percentage']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] average_opponent_elo correct (all opponents at INITIAL_ELO = 1500)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_average_opponent_elo_all_initial(): void
    {
        $opponents = $this->makeOpponents(3);
        $matches   = collect([
            $this->makeMatch(Carbon::parse(self::TARGET)->subDays(21), $this->team, $opponents[0]),
            $this->makeMatch(Carbon::parse(self::TARGET)->subDays(14), $this->team, $opponents[1]),
            $this->makeMatch(Carbon::parse(self::TARGET)->subDays(7),  $opponents[2], $this->team),
        ]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $matches, $matches, collect(), $this->ds->id, $this->season
        );

        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO, $result['last5']['average_opponent_elo'], self::DELTA);
        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO, $result['last5']['median_opponent_elo'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] median odd N=3 with distinct Elo values → middle = 1500
    // ─────────────────────────────────────────────────────────────────────────

    public function test_median_odd_n_picks_middle_value(): void
    {
        [$oppA, $oppB, $oppC, $dummy] = $this->makeOpponents(4);

        $base = Carbon::parse(self::TARGET);

        $this->makeMatch($base->copy()->subDays(60), $oppA, $dummy, 2, 0);
        $this->makeMatch($base->copy()->subDays(50), $oppA, $dummy, 2, 0);
        $this->makeMatch($base->copy()->subDays(40), $dummy, $oppB, 2, 0);

        $mA = $this->makeMatch($base->copy()->subDays(21), $this->team, $oppA);
        $mB = $this->makeMatch($base->copy()->subDays(14), $this->team, $oppB);
        $mC = $this->makeMatch($base->copy()->subDays(7),  $this->team, $oppC);

        $matches = collect([$mA, $mB, $mC]);
        $result  = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $matches, $matches, collect(), $this->ds->id, $this->season
        );

        $this->assertEqualsWithDelta(
            TeamEloCalculator::INITIAL_ELO,
            $result['last5']['median_opponent_elo'],
            self::DELTA
        );
        $this->assertIsFloat($result['last5']['average_opponent_elo']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] median even N=4 → average of two middle values (all 1500 → median=1500)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_median_even_n_averages_two_middle_values(): void
    {
        $opponents = $this->makeOpponents(4);
        $base      = Carbon::parse(self::TARGET);

        $matches = collect([
            $this->makeMatch($base->copy()->subDays(28), $this->team, $opponents[0]),
            $this->makeMatch($base->copy()->subDays(21), $this->team, $opponents[1]),
            $this->makeMatch($base->copy()->subDays(14), $this->team, $opponents[2]),
            $this->makeMatch($base->copy()->subDays(7),  $this->team, $opponents[3]),
        ]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $matches, $matches, collect(), $this->ds->id, $this->season
        );

        $this->assertEqualsWithDelta(
            TeamEloCalculator::INITIAL_ELO,
            $result['last5']['median_opponent_elo'],
            self::DELTA
        );
        $this->assertSame(4, $result['last5']['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] Elo of opponent is PRE-MATCH of that historical match
    // ─────────────────────────────────────────────────────────────────────────

    public function test_opponent_elo_is_pre_match_not_current(): void
    {
        [$oppA, $dummy] = $this->makeOpponents(2);
        $base = Carbon::parse(self::TARGET);

        $this->makeMatch($base->copy()->subDays(30), $oppA, $dummy, 2, 0);
        $this->makeMatch($base->copy()->subDays(20), $oppA, $dummy, 2, 0);

        $cutoffBeforeMatch  = $base->copy()->subDays(10);
        $ratingsBeforeMatch = TeamEloCalculator::calculateRatingsBefore($cutoffBeforeMatch);
        $oppAEloBefore      = $ratingsBeforeMatch[$oppA->id] ?? TeamEloCalculator::INITIAL_ELO;
        $this->assertGreaterThan(TeamEloCalculator::INITIAL_ELO, $oppAEloBefore);

        $mTarget = $this->makeMatch($base->copy()->subDays(10), $this->team, $oppA, 3, 0);

        $this->makeMatch($base->copy()->subDays(3), $dummy, $oppA, 2, 0);

        $window = collect([$mTarget]);
        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $this->season
        );

        $this->assertEqualsWithDelta($oppAEloBefore, $result['last5']['average_opponent_elo'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] Result of the same match does NOT influence the captured Elo
    // ─────────────────────────────────────────────────────────────────────────

    public function test_same_match_result_does_not_influence_captured_elo(): void
    {
        [$oppA] = $this->makeOpponents(1);

        $m = $this->makeMatch(Carbon::parse(self::TARGET)->subDays(7), $this->team, $oppA, 0, 3);

        $window = collect([$m]);
        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $this->season
        );

        $this->assertEqualsWithDelta(
            TeamEloCalculator::INITIAL_ELO,
            $result['last5']['average_opponent_elo'],
            self::DELTA
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] Results after the historical match do NOT modify that captured Elo
    // ─────────────────────────────────────────────────────────────────────────

    public function test_results_after_historical_match_do_not_modify_captured_elo(): void
    {
        [$oppA, $dummy] = $this->makeOpponents(2);
        $base = Carbon::parse(self::TARGET);

        $mHist = $this->makeMatch($base->copy()->subDays(14), $this->team, $oppA, 1, 0);

        $this->makeMatch($base->copy()->subDays(10), $oppA, $dummy, 2, 0);
        $this->makeMatch($base->copy()->subDays(7),  $oppA, $dummy, 2, 0);
        $this->makeMatch($base->copy()->subDays(3),  $oppA, $dummy, 2, 0);

        $window = collect([$mHist]);
        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $this->season
        );

        $this->assertEqualsWithDelta(
            TeamEloCalculator::INITIAL_ELO,
            $result['last5']['average_opponent_elo'],
            self::DELTA
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] Snapshot on same date as match kickoff → used (snapshot_date ≤ kickoff)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_structural_snapshot_on_same_day_as_match_is_used(): void
    {
        [$opp] = $this->makeOpponents(1);
        $this->snap($opp->id, '2026-09-11', 500_000_000);

        $m = $this->makeMatch(
            Carbon::parse('2026-09-11 20:45:00'), $this->team, $opp
        );
        $window = collect([$m]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $this->season
        );

        $expected = TeamStructuralRatingCalculator::calculateFromMarketValue(500_000_000);
        $this->assertNotNull($result['last5']['average_opponent_structural']);
        $this->assertEqualsWithDelta(
            $expected['structural_rating'],
            $result['last5']['average_opponent_structural'],
            self::DELTA
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] Snapshot AFTER kickoff but first of season → USED (season baseline)
    //
    // match kickoff: 2026-09-10
    // snapshot_date: 2026-09-11 (first snapshot of the 2026/27 season)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_structural_first_snapshot_after_kickoff_uses_season_baseline(): void
    {
        [$opp] = $this->makeOpponents(1);
        $this->snap($opp->id, '2026-09-11', 400_000_000);

        $m = $this->makeMatch(
            Carbon::parse('2026-09-10 20:45:00'), $this->team, $opp
        );
        $window = collect([$m]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $this->season
        );

        $expected = TeamStructuralRatingCalculator::calculateFromMarketValue(400_000_000);
        $this->assertNotNull(
            $result['last5']['average_opponent_structural'],
            'first season snapshot must be available even when snapshot_date > kickoff_date'
        );
        $this->assertEqualsWithDelta(
            $expected['structural_rating'],
            $result['last5']['average_opponent_structural'],
            self::DELTA
        );
        $this->assertSame(1, $result['last5']['structural_matches_available']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J] Structural formula not duplicated: rating equals calculateFromMarketValue
    // ─────────────────────────────────────────────────────────────────────────

    public function test_structural_rating_matches_pure_calculator(): void
    {
        [$opp] = $this->makeOpponents(1);
        $mv = 750_000_000;
        $this->snap($opp->id, '2026-09-18', $mv);

        $m = $this->makeMatch(Carbon::parse(self::TARGET)->subDays(7), $this->team, $opp);
        $window = collect([$m]);

        $result   = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $this->season
        );
        $expected = TeamStructuralRatingCalculator::calculateFromMarketValue($mv);

        $this->assertEqualsWithDelta(
            $expected['structural_rating'],
            $result['last5']['average_opponent_structural'],
            self::DELTA
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J2] Numerical: 800M → structural_rating ≈ 1811.916 inside Opponent Quality
    //
    // Formula: base(1500) + beta(150) * ln(800_000_000 / 100_000_000)
    //        = 1500 + 150 * ln(8)
    //        = 1500 + 150 * 2.07944...
    //        ≈ 1811.916
    //
    // This test confirms structural uses the 1400-1900+ scale, NOT a raw or
    // normalised value.  The ≈52.3 mentioned in a prior session report was wrong.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_structural_800m_produces_expected_rating(): void
    {
        [$opp] = $this->makeOpponents(1);
        $this->snap($opp->id, '2026-09-11', 800_000_000);

        $m = $this->makeMatch(
            Carbon::parse(self::TARGET)->subDays(7), $this->team, $opp
        );
        $window = collect([$m]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $this->season
        );

        $avg = $result['last5']['average_opponent_structural'];
        $this->assertNotNull($avg);

        // 1500 + 150 * ln(8) ≈ 1811.916
        $this->assertGreaterThan(1800.0, $avg, 'structural_rating must be in the 1400-1900+ range');
        $this->assertEqualsWithDelta(
            1500.0 + 150.0 * log(8.0),
            $avg,
            self::DELTA,
            '800M → expected structural_rating ≈ 1811.916'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [K] No snapshot at all → null, excluded from avg/median
    // ─────────────────────────────────────────────────────────────────────────

    public function test_structural_null_when_no_snapshot(): void
    {
        [$opp] = $this->makeOpponents(1);

        $m      = $this->makeMatch(Carbon::parse(self::TARGET)->subDays(7), $this->team, $opp);
        $window = collect([$m]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $this->season
        );

        $this->assertNull($result['last5']['average_opponent_structural']);
        $this->assertNull($result['last5']['median_opponent_structural']);
        $this->assertSame(0, $result['last5']['structural_matches_available']);
        $this->assertEqualsWithDelta(0.0, $result['last5']['structural_coverage_percentage'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [L] Coverage % correct when 3 of 5 opponents have structural
    // ─────────────────────────────────────────────────────────────────────────

    public function test_structural_coverage_partial(): void
    {
        $opponents = $this->makeOpponents(5);
        $base      = Carbon::parse(self::TARGET);

        $this->snap($opponents[0]->id, '2026-08-01', 300_000_000);
        $this->snap($opponents[1]->id, '2026-08-01', 400_000_000);
        $this->snap($opponents[2]->id, '2026-08-01', 500_000_000);

        $matches = collect([
            $this->makeMatch($base->copy()->subDays(35), $this->team, $opponents[0]),
            $this->makeMatch($base->copy()->subDays(28), $this->team, $opponents[1]),
            $this->makeMatch($base->copy()->subDays(21), $this->team, $opponents[2]),
            $this->makeMatch($base->copy()->subDays(14), $this->team, $opponents[3]),
            $this->makeMatch($base->copy()->subDays(7),  $this->team, $opponents[4]),
        ]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $matches, $matches, collect(), $this->ds->id, $this->season
        );

        $w = $result['last5'];
        $this->assertSame(5, $w['matches_considered']);
        $this->assertSame(3, $w['structural_matches_available']);
        $this->assertEqualsWithDelta(60.0, $w['structural_coverage_percentage'], self::DELTA);

        $ratings = array_map(
            fn ($mv) => TeamStructuralRatingCalculator::calculateFromMarketValue($mv)['structural_rating'],
            [300_000_000, 400_000_000, 500_000_000]
        );
        sort($ratings);
        $this->assertEqualsWithDelta($ratings[1], $w['median_opponent_structural'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [SB-A] Season baseline: match BEFORE first snapshot → uses first snapshot
    // ─────────────────────────────────────────────────────────────────────────

    public function test_season_baseline_match_before_first_snapshot_uses_first(): void
    {
        [$opp] = $this->makeOpponents(1);
        $this->snap($opp->id, '2026-09-11', 400_000_000);

        $m = $this->makeMatch(
            Carbon::parse('2026-08-30 15:00:00'), $this->team, $opp
        );
        $window = collect([$m]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $this->season
        );

        $expected = TeamStructuralRatingCalculator::calculateFromMarketValue(400_000_000);
        $this->assertNotNull($result['last5']['average_opponent_structural']);
        $this->assertEqualsWithDelta(
            $expected['structural_rating'],
            $result['last5']['average_opponent_structural'],
            self::DELTA,
            'first season snapshot (2026-09-11) must be available for a match on 2026-08-30'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [SB-B] match between first and second snapshot → uses first snapshot
    //
    // snapshots: 2026-09-11 (400M), 2027-02-10 (600M)
    // match: 2026-10-01 → uses 2026-09-11
    // ─────────────────────────────────────────────────────────────────────────

    public function test_season_baseline_match_between_snapshots_uses_first(): void
    {
        [$opp] = $this->makeOpponents(1);
        $this->snap($opp->id, '2026-09-11', 400_000_000);
        $this->snap($opp->id, '2027-02-10', 600_000_000);

        $m = $this->makeMatch(
            Carbon::parse('2026-10-01 20:45:00'), $this->team, $opp
        );
        $window = collect([$m]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $this->season
        );

        $expected = TeamStructuralRatingCalculator::calculateFromMarketValue(400_000_000);
        $this->assertEqualsWithDelta(
            $expected['structural_rating'],
            $result['last5']['average_opponent_structural'],
            self::DELTA,
            'match on 2026-10-01 must use first snapshot (2026-09-11), not second (2027-02-10)'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [SB-C] match AFTER second snapshot → uses second snapshot
    //
    // snapshots: 2026-09-11 (400M), 2027-02-10 (600M)
    // match: 2027-02-15 → uses 2027-02-10
    // ─────────────────────────────────────────────────────────────────────────

    public function test_season_baseline_match_after_second_snapshot_uses_second(): void
    {
        [$opp] = $this->makeOpponents(1);
        $this->snap($opp->id, '2026-09-11', 400_000_000);
        $this->snap($opp->id, '2027-02-10', 600_000_000);

        $m = $this->makeMatch(
            Carbon::parse('2027-02-15 20:45:00'), $this->team, $opp
        );
        $window = collect([$m]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $this->season
        );

        $expected = TeamStructuralRatingCalculator::calculateFromMarketValue(600_000_000);
        $this->assertEqualsWithDelta(
            $expected['structural_rating'],
            $result['last5']['average_opponent_structural'],
            self::DELTA,
            'match on 2027-02-15 must use second snapshot (2027-02-10)'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [SB-D] Snapshot outside season window → excluded, structural = null
    //
    // season range fallback: [2026-07-01 .. 2027-06-30]
    // snapshot 2025-09-01 (2025/26) and 2027-09-01 (2027/28) → both excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_season_baseline_snapshot_outside_season_excluded(): void
    {
        [$opp] = $this->makeOpponents(1);
        $this->snap($opp->id, '2025-09-01', 350_000_000); // 2025/26 season
        $this->snap($opp->id, '2027-09-01', 500_000_000); // 2027/28 season

        $m = $this->makeMatch(
            Carbon::parse('2026-10-15 20:45:00'), $this->team, $opp
        );
        $window = collect([$m]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $this->season
        );

        $this->assertNull(
            $result['last5']['average_opponent_structural'],
            'snapshots outside [2026-07-01, 2027-06-30] must not be used for 2026/27 matches'
        );
        $this->assertSame(0, $result['last5']['structural_matches_available']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [SD-A] Season.start_date set: snapshot before start_date → excluded
    //
    // Season.start_date = 2026-08-22.
    // Snapshot 2026-08-10 is before start_date → outside season → excluded.
    // Snapshot 2026-09-11 is after start_date → in season → used.
    // Match 2026-08-25 → season-baseline applies: uses 2026-09-11 snapshot.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_real_start_date_snapshot_before_start_excluded(): void
    {
        $season = Season::create([
            'competition_id' => $this->comp->id,
            'name'           => '2026/27 B',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'start_date'     => '2026-08-22',
            'end_date'       => '2027-05-25',
        ]);

        [$opp] = $this->makeOpponents(1);
        $this->snap($opp->id, '2026-08-10', 300_000_000); // before start_date → excluded
        $this->snap($opp->id, '2026-09-11', 500_000_000); // in season → available

        $m = $this->makeMatch(Carbon::parse('2026-08-25 20:45:00'), $this->team, $opp);
        $window = collect([$m]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $season
        );

        // Only 2026-09-11 snapshot is in season; it's the first → season baseline
        $expected = TeamStructuralRatingCalculator::calculateFromMarketValue(500_000_000);
        $this->assertNotNull($result['last5']['average_opponent_structural']);
        $this->assertEqualsWithDelta(
            $expected['structural_rating'],
            $result['last5']['average_opponent_structural'],
            self::DELTA,
            '2026-08-10 snapshot (before start_date 2026-08-22) must be excluded'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [SD-B] Season.end_date set: snapshot after end_date → excluded
    //
    // Season.end_date = 2027-05-25.
    // Snapshot 2027-06-01 > end_date → excluded.
    // Match 2027-05-20 → uses last valid snapshot ≤ 2027-05-20.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_real_end_date_snapshot_after_end_excluded(): void
    {
        $season = Season::create([
            'competition_id' => $this->comp->id,
            'name'           => '2026/27 C',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'start_date'     => '2026-08-22',
            'end_date'       => '2027-05-25',
        ]);

        [$opp] = $this->makeOpponents(1);
        $this->snap($opp->id, '2026-09-11', 400_000_000); // in season
        $this->snap($opp->id, '2027-06-01', 700_000_000); // after end_date → excluded

        $m = $this->makeMatch(Carbon::parse('2027-05-20 20:45:00'), $this->team, $opp);
        $window = collect([$m]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $season
        );

        // Only 2026-09-11 is in season; 2027-06-01 excluded
        $expected = TeamStructuralRatingCalculator::calculateFromMarketValue(400_000_000);
        $this->assertEqualsWithDelta(
            $expected['structural_rating'],
            $result['last5']['average_opponent_structural'],
            self::DELTA,
            '2027-06-01 snapshot (after end_date 2027-05-25) must be excluded'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [SD-C] First snapshot = season start_date exactly → retroactive baseline
    //
    // Season.start_date = 2026-08-22.
    // snapshot_date = 2026-08-22 (same day as start_date).
    // match kickoff = 2026-08-22 → snapshot_date ≤ kickoff → normal ≤ path.
    // match kickoff = 2026-08-22 but snapshot_date = 2026-09-11
    //   → no ≤ snapshot → fallback first → retroactive ✓.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_first_snapshot_on_season_start_date_is_retroactive_baseline(): void
    {
        $season = Season::create([
            'competition_id' => $this->comp->id,
            'name'           => '2026/27 D',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'start_date'     => '2026-08-22',
            'end_date'       => '2027-05-25',
        ]);

        [$opp] = $this->makeOpponents(1);
        // Only snapshot in the season is 2026-09-11 — first of the season
        $this->snap($opp->id, '2026-09-11', 600_000_000);

        // Match on opening day: 2026-08-22, before snapshot
        $m = $this->makeMatch(Carbon::parse('2026-08-22 20:45:00'), $this->team, $opp);
        $window = collect([$m]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $season
        );

        // snapshot 2026-09-11 is in [2026-08-22, 2027-05-25] → in season
        // No snapshot ≤ 2026-08-22 → fallback to first = 2026-09-11
        $expected = TeamStructuralRatingCalculator::calculateFromMarketValue(600_000_000);
        $this->assertNotNull($result['last5']['average_opponent_structural']);
        $this->assertEqualsWithDelta(
            $expected['structural_rating'],
            $result['last5']['average_opponent_structural'],
            self::DELTA,
            'first in-season snapshot must be retroactive baseline for opening-day matches'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [M] last5 uses at most 5 matches
    // ─────────────────────────────────────────────────────────────────────────

    public function test_last5_window_respects_passed_collection(): void
    {
        $opponents = $this->makeOpponents(7);
        $base      = Carbon::parse(self::TARGET);

        $all = collect();
        for ($i = 0; $i < 7; $i++) {
            $all->push($this->makeMatch($base->copy()->subDays((7 - $i) * 7), $this->team, $opponents[$i]));
        }

        $last5  = $all->slice(-5)->values();
        $last10 = $all;

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $last5, $last10, collect(), $this->ds->id, $this->season
        );

        $this->assertSame(5, $result['last5']['matches_considered']);
        $this->assertSame(7, $result['last10']['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [N] last10 uses at most 10 matches
    // ─────────────────────────────────────────────────────────────────────────

    public function test_last10_window_respects_passed_collection(): void
    {
        $opponents = $this->makeOpponents(12);
        $base      = Carbon::parse(self::TARGET);

        $all = collect();
        for ($i = 0; $i < 12; $i++) {
            $all->push($this->makeMatch($base->copy()->subDays((12 - $i) * 7), $this->team, $opponents[$i]));
        }

        $last5  = $all->slice(-5)->values();
        $last10 = $all->slice(-10)->values();

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $last5, $last10, collect(), $this->ds->id, $this->season
        );

        $this->assertSame(10, $result['last10']['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [O] venue window: filter-before-lastN is the caller's responsibility
    // ─────────────────────────────────────────────────────────────────────────

    public function test_venue_window_uses_passed_collection(): void
    {
        $opponents = $this->makeOpponents(10);
        $base      = Carbon::parse(self::TARGET);

        $homeMatches = collect();
        $awayMatches = collect();

        for ($i = 0; $i < 6; $i++) {
            $homeMatches->push(
                $this->makeMatch($base->copy()->subDays((10 - $i) * 7), $this->team, $opponents[$i])
            );
        }
        for ($i = 6; $i < 10; $i++) {
            $awayMatches->push(
                $this->makeMatch($base->copy()->subDays((10 - $i) * 7), $opponents[$i], $this->team)
            );
        }

        $all       = $homeMatches->merge($awayMatches)->sortBy('kickoff_at')->values();
        $last5Home = $homeMatches->slice(-5)->values();

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id,
            $all->slice(-5)->values(),
            $all->slice(-10)->values(),
            $last5Home,
            $this->ds->id,
            $this->season
        );

        $this->assertSame(5, $result['venue']['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [P] Fewer than 5 matches → uses real count
    // ─────────────────────────────────────────────────────────────────────────

    public function test_fewer_than_5_matches_uses_real_count(): void
    {
        $opponents = $this->makeOpponents(3);
        $base      = Carbon::parse(self::TARGET);

        $matches = collect([
            $this->makeMatch($base->copy()->subDays(21), $this->team, $opponents[0]),
            $this->makeMatch($base->copy()->subDays(14), $this->team, $opponents[1]),
            $this->makeMatch($base->copy()->subDays(7),  $this->team, $opponents[2]),
        ]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $matches, $matches, collect(), $this->ds->id, $this->season
        );

        $this->assertSame(3, $result['last5']['matches_considered']);
        $this->assertNotNull($result['last5']['average_opponent_elo']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [Q] Empty venue window → emptyWindow for venue, other windows intact
    // ─────────────────────────────────────────────────────────────────────────

    public function test_empty_venue_window_returns_empty_for_that_window(): void
    {
        [$opp] = $this->makeOpponents(1);
        $m      = $this->makeMatch(Carbon::parse(self::TARGET)->subDays(7), $this->team, $opp);
        $window = collect([$m]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $this->season
        );

        $this->assertSame(1, $result['last5']['matches_considered']);
        $this->assertSame(0, $result['venue']['matches_considered']);
        $this->assertNull($result['venue']['average_opponent_elo']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [R] Anti-leakage: target match excluded (windows contain only prior matches)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_anti_leakage_target_match_not_in_windows(): void
    {
        [$opp] = $this->makeOpponents(1);
        $base  = Carbon::parse(self::TARGET);

        $prior = $this->makeMatch($base->copy()->subDays(7), $this->team, $opp, 2, 0);
        $this->makeMatch($base, $this->team, $opp, 3, 0, 'scheduled');

        $window = collect([$prior]);
        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), $this->ds->id, $this->season
        );

        $this->assertSame(1, $result['last5']['matches_considered']);
        $this->assertEqualsWithDelta(
            TeamEloCalculator::INITIAL_ELO,
            $result['last5']['average_opponent_elo'],
            self::DELTA
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [S] dataSourceId null → all structural fields null
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_data_source_id_skips_structural(): void
    {
        [$opp] = $this->makeOpponents(1);
        $this->snap($opp->id, '2026-09-11', 500_000_000);

        $m      = $this->makeMatch(Carbon::parse(self::TARGET)->subDays(7), $this->team, $opp);
        $window = collect([$m]);

        $result = TeamOpponentQualityCalculator::calculate(
            $this->team->id, $window, $window, collect(), null, $this->season
        );

        $this->assertNull($result['last5']['average_opponent_structural']);
        $this->assertNull($result['last5']['median_opponent_structural']);
        $this->assertSame(0, $result['last5']['structural_matches_available']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** @return Team[] */
    private function makeOpponents(int $count): array
    {
        $teams = [];
        for ($i = 0; $i < $count; $i++) {
            $teams[] = Team::create([
                'name'      => "Opponent{$i}",
                'type'      => 'club',
                'is_active' => true,
            ]);
        }
        return $teams;
    }

    private function makeMatch(
        Carbon $kickoff,
        Team   $home,
        Team   $away,
        int    $homeScore = 1,
        int    $awayScore = 0,
        string $status    = 'finished',
    ): FootballMatch {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $home->id,
            'away_team_id'   => $away->id,
            'kickoff_at'     => $kickoff,
            'status'         => $status,
            'home_score_ft'  => in_array($status, ['finished', 'awarded', 'walkover']) ? $homeScore : null,
            'away_score_ft'  => in_array($status, ['finished', 'awarded', 'walkover']) ? $awayScore : null,
        ]);
    }

    private function snap(int $teamId, string $date, int $marketValue): void
    {
        TeamMarketValueSnapshot::create([
            'team_id'        => $teamId,
            'data_source_id' => $this->ds->id,
            'snapshot_date'  => $date,
            'market_value'   => $marketValue,
        ]);
    }
}
