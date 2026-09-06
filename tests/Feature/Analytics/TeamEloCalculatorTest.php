<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\TeamEloCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for TeamEloCalculator.
 *
 * Numeric expectations are derived directly from the Elo formula with the
 * constants INITIAL_ELO=1500, K_FACTOR=20, HOME_ADVANTAGE=60.  Where values
 * are computed from the formula they are stated inline so the test doubles
 * as documentation.
 *
 * Tests:
 *  [A]  Both teams new → calculateForMatch returns INITIAL_ELO, diff = 0
 *  [B]  Home win → correct numeric update for both teams
 *  [C]  Away win → correct numeric update for both teams
 *  [D]  Draw → smaller delta, home team penalised (expected_home > 0.5)
 *  [E]  Upset: underdog away wins → larger delta than K/2
 *  [F]  Favourite home wins → smaller delta than K/2
 *  [G]  Zero-sum: delta_home + delta_away = 0 for every match
 *  [H]  Home advantage shifts expected_home > 0.5 when ratings are equal
 *  [I]  Home advantage does NOT permanently modify stored ratings
 *  [J]  Target match excluded (calculateForMatch anti-leakage)
 *  [K]  Future match excluded (calculateRatingsBefore anti-leakage)
 *  [L]  Non-definitive match excluded (status = 'scheduled')
 *  [M]  Match without FT score excluded (home_score_ft IS NULL)
 *  [N]  All competitions contribute to ratings (cross-competition scope)
 *  [O]  Chronological order respected (kickoff_at ASC)
 *  [P]  Stable tie-breaker: id ASC when same kickoff_at
 *  [Q]  New team entering mid-sequence starts at INITIAL_ELO
 *  [R]  Exact cutoff boundary: match at cutoff is excluded (strict <)
 *  [S]  calculateForMatch: null kickoff returns INITIAL_ELO for both
 *  [T]  One DB query for calculateRatingsBefore (no N+1)
 */
class TeamEloCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Team $teamA;
    private Team $teamB;
    private Competition $comp;
    private Season $season;

    private const TARGET = '2026-09-10 20:45:00';
    private const DELTA  = 0.001; // float comparison tolerance

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
    // [A] Both teams new → INITIAL_ELO
    // ─────────────────────────────────────────────────────────────────────────

    public function test_new_teams_start_at_initial_elo(): void
    {
        $match = $this->makeMatch(
            Carbon::parse(self::TARGET),
            $this->teamA, $this->teamB, 0, 0, 'scheduled'
        );

        $result = TeamEloCalculator::calculateForMatch($match);

        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO, $result['home_elo'], self::DELTA);
        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO, $result['away_elo'], self::DELTA);
        $this->assertEqualsWithDelta(0.0, $result['elo_difference'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] Home win → correct numeric update
    //
    // Both at 1500. HOME_ADVANTAGE = 60.
    // expected_home = 1/(1+10^((1500−1500−60)/400)) = 1/(1+10^−0.15) ≈ 0.585408
    // delta_home    = 20*(1.0−0.585408) =  8.2918
    // delta_away    = 20*(0.0−0.414592) = −8.2918
    // ─────────────────────────────────────────────────────────────────────────

    public function test_home_win_updates_ratings_correctly(): void
    {
        $this->makeMatch(Carbon::parse(self::TARGET)->subDays(7), $this->teamA, $this->teamB, 2, 0);

        $cutoff  = Carbon::parse(self::TARGET);
        $ratings = TeamEloCalculator::calculateRatingsBefore($cutoff);

        $expectedHome = 1.0 / (1.0 + 10.0 ** (-60.0 / 400.0)); // = ≈ 0.585408
        $deltaHome    = TeamEloCalculator::K_FACTOR * (1.0 - $expectedHome);

        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO + $deltaHome, $ratings[$this->teamA->id], self::DELTA);
        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO - $deltaHome, $ratings[$this->teamB->id], self::DELTA);
        $this->assertGreaterThan(TeamEloCalculator::INITIAL_ELO, $ratings[$this->teamA->id]);
        $this->assertLessThan(TeamEloCalculator::INITIAL_ELO, $ratings[$this->teamB->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] Away win → correct numeric update
    //
    // Both at 1500. Away wins → away gains points.
    // expected_away = 1 − expected_home ≈ 0.414592
    // delta_away    = 20*(1.0−0.414592) = 11.7082
    // delta_home    = 20*(0.0−0.585408) = −11.7082
    // ─────────────────────────────────────────────────────────────────────────

    public function test_away_win_updates_ratings_correctly(): void
    {
        $this->makeMatch(Carbon::parse(self::TARGET)->subDays(7), $this->teamA, $this->teamB, 0, 2);

        $cutoff  = Carbon::parse(self::TARGET);
        $ratings = TeamEloCalculator::calculateRatingsBefore($cutoff);

        $expectedHome = 1.0 / (1.0 + 10.0 ** (-60.0 / 400.0));
        $expectedAway = 1.0 - $expectedHome;
        $deltaAway    = TeamEloCalculator::K_FACTOR * (1.0 - $expectedAway);

        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO - $deltaAway, $ratings[$this->teamA->id], self::DELTA);
        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO + $deltaAway, $ratings[$this->teamB->id], self::DELTA);
        $this->assertLessThan(TeamEloCalculator::INITIAL_ELO, $ratings[$this->teamA->id]);
        $this->assertGreaterThan(TeamEloCalculator::INITIAL_ELO, $ratings[$this->teamB->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] Draw → smaller delta, home team loses points
    //
    // Both at 1500. Draw.
    // expected_home ≈ 0.585408  (home advantage → home expected to win)
    // delta_home    = 20*(0.5 − 0.585408) = −1.7082   (home underperformed)
    // delta_away    = 20*(0.5 − 0.414592) = +1.7082   (away overperformed)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_draw_penalises_home_team_when_ratings_equal(): void
    {
        $this->makeMatch(Carbon::parse(self::TARGET)->subDays(7), $this->teamA, $this->teamB, 1, 1);

        $cutoff  = Carbon::parse(self::TARGET);
        $ratings = TeamEloCalculator::calculateRatingsBefore($cutoff);

        $expectedHome = 1.0 / (1.0 + 10.0 ** (-60.0 / 400.0));
        $deltaHome    = TeamEloCalculator::K_FACTOR * (0.5 - $expectedHome); // negative

        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO + $deltaHome, $ratings[$this->teamA->id], self::DELTA);
        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO - $deltaHome, $ratings[$this->teamB->id], self::DELTA);
        $this->assertLessThan(TeamEloCalculator::INITIAL_ELO, $ratings[$this->teamA->id]);
        $this->assertGreaterThan(TeamEloCalculator::INITIAL_ELO, $ratings[$this->teamB->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] Upset: away underdog beats home favourite → delta_away > K_FACTOR/2
    //
    // Build: teamA (home) beats teamC in 4 previous matches → A has Elo > 1500.
    // Upset match: A (home, stronger) vs B (away, still ≈ 1500). B wins.
    // Since expected_B < 0.5, delta_B = K*(1 − expected_B) > K/2 = 10.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_upset_win_gives_larger_than_half_k_delta(): void
    {
        $teamC = Team::create(['name' => 'TeamC', 'type' => 'club', 'is_active' => true]);

        for ($i = 0; $i < 4; $i++) {
            $this->makeMatch(
                Carbon::parse(self::TARGET)->subDays(60 - $i * 7),
                $this->teamA, $teamC, 2, 0
            );
        }

        $setupCutoff = Carbon::parse(self::TARGET)->subDays(10);
        $before      = TeamEloCalculator::calculateRatingsBefore($setupCutoff);
        $aEloBefore  = $before[$this->teamA->id] ?? TeamEloCalculator::INITIAL_ELO;
        $bEloBefore  = $before[$this->teamB->id] ?? TeamEloCalculator::INITIAL_ELO;

        $this->assertGreaterThan($bEloBefore, $aEloBefore); // A is the favourite

        // Upset: B (away, weaker) beats A (home, stronger)
        $this->makeMatch(Carbon::parse(self::TARGET)->subDays(5), $this->teamA, $this->teamB, 0, 1);

        $cutoff    = Carbon::parse(self::TARGET);
        $after     = TeamEloCalculator::calculateRatingsBefore($cutoff);
        $bEloAfter = $after[$this->teamB->id] ?? TeamEloCalculator::INITIAL_ELO;

        $deltaB = $bEloAfter - $bEloBefore;
        $this->assertGreaterThan(TeamEloCalculator::K_FACTOR / 2.0, $deltaB);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] Favourite home wins → delta_home < K_FACTOR/2
    //
    // Same setup as [E] but A wins (expected outcome). Since expected_A > 0.5,
    // delta_A = K*(1 − expected_A) < K/2 = 10.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_expected_win_gives_smaller_than_half_k_delta(): void
    {
        $teamC = Team::create(['name' => 'TeamC', 'type' => 'club', 'is_active' => true]);

        for ($i = 0; $i < 4; $i++) {
            $this->makeMatch(
                Carbon::parse(self::TARGET)->subDays(60 - $i * 7),
                $this->teamA, $teamC, 2, 0
            );
        }

        $setupCutoff = Carbon::parse(self::TARGET)->subDays(10);
        $before      = TeamEloCalculator::calculateRatingsBefore($setupCutoff);
        $aEloBefore  = $before[$this->teamA->id] ?? TeamEloCalculator::INITIAL_ELO;
        $bEloBefore  = $before[$this->teamB->id] ?? TeamEloCalculator::INITIAL_ELO;

        $this->assertGreaterThan($bEloBefore, $aEloBefore); // A is the favourite

        // Expected win: A (home, stronger) beats B (away, weaker)
        $this->makeMatch(Carbon::parse(self::TARGET)->subDays(5), $this->teamA, $this->teamB, 1, 0);

        $cutoff    = Carbon::parse(self::TARGET);
        $after     = TeamEloCalculator::calculateRatingsBefore($cutoff);
        $aEloAfter = $after[$this->teamA->id] ?? TeamEloCalculator::INITIAL_ELO;

        $deltaA = $aEloAfter - $aEloBefore;
        $this->assertLessThan(TeamEloCalculator::K_FACTOR / 2.0, $deltaA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] Zero-sum: delta_home + delta_away = 0 for every match
    // ─────────────────────────────────────────────────────────────────────────

    public function test_rating_changes_are_zero_sum(): void
    {
        $cutoffBefore = Carbon::parse(self::TARGET)->subDays(8);
        $cutoffAfter  = Carbon::parse(self::TARGET);

        $ratingsBefore = TeamEloCalculator::calculateRatingsBefore($cutoffBefore);
        $aElo0 = $ratingsBefore[$this->teamA->id] ?? TeamEloCalculator::INITIAL_ELO;
        $bElo0 = $ratingsBefore[$this->teamB->id] ?? TeamEloCalculator::INITIAL_ELO;

        $this->makeMatch(Carbon::parse(self::TARGET)->subDays(7), $this->teamA, $this->teamB, 3, 1);

        $ratingsAfter = TeamEloCalculator::calculateRatingsBefore($cutoffAfter);
        $aElo1 = $ratingsAfter[$this->teamA->id] ?? TeamEloCalculator::INITIAL_ELO;
        $bElo1 = $ratingsAfter[$this->teamB->id] ?? TeamEloCalculator::INITIAL_ELO;

        $deltaTotal = ($aElo1 - $aElo0) + ($bElo1 - $bElo0);
        $this->assertEqualsWithDelta(0.0, $deltaTotal, self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] Home advantage shifts expected_home > 0.5 when ratings are equal
    //
    // Consequence: a draw causes home to lose points (they were expected to do
    // better).  Verified via the draw update in calculateRatingsBefore.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_home_advantage_makes_draw_hurt_home_team(): void
    {
        $this->makeMatch(Carbon::parse(self::TARGET)->subDays(7), $this->teamA, $this->teamB, 0, 0);

        $ratings = TeamEloCalculator::calculateRatingsBefore(Carbon::parse(self::TARGET));

        // Home team drew against equal opponent — they should have been expected
        // to win, so a draw is a negative result → home Elo drops.
        $this->assertLessThan(TeamEloCalculator::INITIAL_ELO, $ratings[$this->teamA->id]);
        $this->assertGreaterThan(TeamEloCalculator::INITIAL_ELO, $ratings[$this->teamB->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] Home advantage does NOT permanently modify stored ratings
    //
    // With no prior matches, both teams are at INITIAL_ELO regardless of which
    // one will be "home".  The advantage exists only in the expected-score
    // formula, not in the stored rating.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_home_advantage_does_not_permanently_modify_rating(): void
    {
        $cutoff  = Carbon::parse(self::TARGET)->subDays(7); // before any match
        $ratings = TeamEloCalculator::calculateRatingsBefore($cutoff);

        // No matches → both absent from the map → default is INITIAL_ELO.
        $aElo = $ratings[$this->teamA->id] ?? TeamEloCalculator::INITIAL_ELO;
        $bElo = $ratings[$this->teamB->id] ?? TeamEloCalculator::INITIAL_ELO;

        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO, $aElo, self::DELTA);
        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO, $bElo, self::DELTA);
        $this->assertEqualsWithDelta($aElo, $bElo, self::DELTA); // no asymmetry in stored ratings
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J] Target match is excluded from calculateForMatch (anti-leakage)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_target_match_result_is_excluded(): void
    {
        // Give the target match a finished result — it must NOT affect the Elo
        // calculation, which should still see both teams at INITIAL_ELO.
        $match = $this->makeMatch(
            Carbon::parse(self::TARGET),
            $this->teamA, $this->teamB, 5, 0, 'finished'
        );

        $result = TeamEloCalculator::calculateForMatch($match);

        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO, $result['home_elo'], self::DELTA);
        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO, $result['away_elo'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [K] Future match excluded from calculateRatingsBefore
    // ─────────────────────────────────────────────────────────────────────────

    public function test_future_match_is_excluded(): void
    {
        $cutoff = Carbon::parse(self::TARGET);

        // Match after cutoff — must be ignored.
        $this->makeMatch($cutoff->copy()->addDays(7), $this->teamA, $this->teamB, 3, 0);

        $ratings = TeamEloCalculator::calculateRatingsBefore($cutoff);

        $this->assertArrayNotHasKey($this->teamA->id, $ratings);
        $this->assertArrayNotHasKey($this->teamB->id, $ratings);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [L] Non-definitive match excluded (status = 'scheduled')
    // ─────────────────────────────────────────────────────────────────────────

    public function test_non_definitive_match_is_excluded(): void
    {
        $this->makeMatch(
            Carbon::parse(self::TARGET)->subDays(7),
            $this->teamA, $this->teamB, 2, 0, 'scheduled'
        );

        $ratings = TeamEloCalculator::calculateRatingsBefore(Carbon::parse(self::TARGET));

        $this->assertArrayNotHasKey($this->teamA->id, $ratings);
        $this->assertArrayNotHasKey($this->teamB->id, $ratings);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [M] Match without FT score excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_match_without_ft_score_is_excluded(): void
    {
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => Carbon::parse(self::TARGET)->subDays(7),
            'status'         => 'finished',
            // home_score_ft and away_score_ft intentionally omitted (null)
        ]);

        $ratings = TeamEloCalculator::calculateRatingsBefore(Carbon::parse(self::TARGET));

        $this->assertArrayNotHasKey($this->teamA->id, $ratings);
        $this->assertArrayNotHasKey($this->teamB->id, $ratings);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [N] All competitions contribute to ratings (cross-competition scope)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_all_competitions_included(): void
    {
        $country2 = Country::create(['name' => 'England', 'football_code' => 'EN']);
        $comp2    = Competition::create([
            'country_id' => $country2->id,
            'name'       => 'Premier League',
            'slug'       => 'premier-league',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $season2 = Season::create([
            'competition_id' => $comp2->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);

        // Win in domestic competition
        $this->makeMatch(Carbon::parse(self::TARGET)->subDays(14), $this->teamA, $this->teamB, 1, 0);

        // Win in different competition
        FootballMatch::create([
            'competition_id' => $comp2->id,
            'season_id'      => $season2->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => Carbon::parse(self::TARGET)->subDays(7),
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 0,
        ]);

        $ratings      = TeamEloCalculator::calculateRatingsBefore(Carbon::parse(self::TARGET));
        $ratingsOnly1 = TeamEloCalculator::calculateRatingsBefore(Carbon::parse(self::TARGET)->subDays(8));

        // After both competitions teamA should be higher than after just one
        $this->assertGreaterThan(
            $ratingsOnly1[$this->teamA->id] ?? TeamEloCalculator::INITIAL_ELO,
            $ratings[$this->teamA->id] ?? TeamEloCalculator::INITIAL_ELO
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [O] Chronological order respected (kickoff_at ASC)
    //
    // Two sequential matches between the same teams.  The second match's
    // expected outcome depends on the ratings established in the first.
    // Processing them in reverse would yield a different final Elo for teamA.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_chronological_order_respected(): void
    {
        // Match 1 (earlier): A beats B → A rises to ~1508.29, B drops to ~1491.71
        $this->makeMatch(Carbon::parse(self::TARGET)->subDays(14), $this->teamA, $this->teamB, 1, 0);
        // Match 2 (later): B beats A → B benefits from underdog rating now
        $this->makeMatch(Carbon::parse(self::TARGET)->subDays(7), $this->teamA, $this->teamB, 0, 1);

        $ratings = TeamEloCalculator::calculateRatingsBefore(Carbon::parse(self::TARGET));

        // After M1 then M2: compute expected values sequentially.
        $expHome1 = 1.0 / (1.0 + 10.0 ** (-60.0 / 400.0)); // both at 1500
        $aAfterM1 = TeamEloCalculator::INITIAL_ELO + TeamEloCalculator::K_FACTOR * (1.0 - $expHome1);
        $bAfterM1 = TeamEloCalculator::INITIAL_ELO - TeamEloCalculator::K_FACTOR * (1.0 - $expHome1);

        // M2: A (home, aAfterM1) vs B (away, bAfterM1), B wins.
        $expHome2 = 1.0 / (1.0 + 10.0 ** (($bAfterM1 - $aAfterM1 - 60.0) / 400.0));
        $aAfterM2 = $aAfterM1 + TeamEloCalculator::K_FACTOR * (0.0 - $expHome2);
        $bAfterM2 = $bAfterM1 + TeamEloCalculator::K_FACTOR * (1.0 - (1.0 - $expHome2));

        $this->assertEqualsWithDelta($aAfterM2, $ratings[$this->teamA->id], self::DELTA);
        $this->assertEqualsWithDelta($bAfterM2, $ratings[$this->teamB->id], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [P] Stable tie-breaker: id ASC when same kickoff_at
    //
    // TeamA appears in two matches at the identical kickoff_at.  The first
    // created (lower auto-increment id) is processed first, affecting teamA's
    // Elo going into the second.  The final Elo is computed for the id-
    // ascending ordering and asserted to match the calculator output.
    //
    // Setup:
    //  M1 (lower id, created first): A (home) beats B (away)  → A += delta1
    //  M2 (higher id, created after): A (home) beats C (away) → A += delta2 (from new base)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_tie_breaker_by_id_ascending(): void
    {
        $teamC  = Team::create(['name' => 'TeamC', 'type' => 'club', 'is_active' => true]);
        $sameKo = Carbon::parse(self::TARGET)->subDays(7);

        // Create M1 first (it will get the lower auto-increment id).
        $this->makeMatch($sameKo, $this->teamA, $this->teamB, 1, 0); // M1: A beats B
        $this->makeMatch($sameKo, $this->teamA, $teamC,       1, 0); // M2: A beats C

        $ratings = TeamEloCalculator::calculateRatingsBefore(Carbon::parse(self::TARGET));

        // Expected: M1 processed before M2.
        // M1: both at 1500. expected_A = 1/(1+10^(-60/400)).
        $expA_M1  = 1.0 / (1.0 + 10.0 ** (-60.0 / 400.0));
        $aAfterM1 = TeamEloCalculator::INITIAL_ELO + TeamEloCalculator::K_FACTOR * (1.0 - $expA_M1);

        // M2: A (aAfterM1) vs C (1500). A is home.
        $expA_M2  = 1.0 / (1.0 + 10.0 ** ((TeamEloCalculator::INITIAL_ELO - $aAfterM1 - 60.0) / 400.0));
        $aAfterM2 = $aAfterM1 + TeamEloCalculator::K_FACTOR * (1.0 - $expA_M2);

        $this->assertEqualsWithDelta($aAfterM2, $ratings[$this->teamA->id], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [Q] New team entering mid-sequence starts at INITIAL_ELO
    // ─────────────────────────────────────────────────────────────────────────

    public function test_new_team_entering_mid_sequence_starts_at_initial_elo(): void
    {
        $teamC = Team::create(['name' => 'TeamC', 'type' => 'club', 'is_active' => true]);

        // TeamA and TeamB build up Elo in 3 earlier matches.
        for ($i = 0; $i < 3; $i++) {
            $this->makeMatch(Carbon::parse(self::TARGET)->subDays(30 - $i * 7), $this->teamA, $this->teamB, 1, 0);
        }

        // TeamC appears for the first time, immediately playing TeamA.
        $this->makeMatch(Carbon::parse(self::TARGET)->subDays(7), $this->teamA, $teamC, 1, 0);

        $ratings = TeamEloCalculator::calculateRatingsBefore(Carbon::parse(self::TARGET)->subDays(8));

        // Just before TeamC's debut — it is absent from the ratings map.
        $this->assertArrayNotHasKey($teamC->id, $ratings);

        // TeamC's Elo used in the match computation is INITIAL_ELO (not a different value).
        $ratingsAfter = TeamEloCalculator::calculateRatingsBefore(Carbon::parse(self::TARGET));
        $cEloAfter    = $ratingsAfter[$teamC->id] ?? TeamEloCalculator::INITIAL_ELO;

        // TeamC lost as away team against TeamA (higher Elo).  Their Elo should have
        // started at INITIAL_ELO and dropped after the loss.
        $this->assertLessThan(TeamEloCalculator::INITIAL_ELO, $cEloAfter);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [R] Exact cutoff boundary: match AT cutoff is excluded (strict <)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_match_at_exact_cutoff_is_excluded(): void
    {
        $cutoff = Carbon::parse(self::TARGET);

        // Match exactly at cutoff.
        $this->makeMatch($cutoff, $this->teamA, $this->teamB, 3, 0);

        $ratings = TeamEloCalculator::calculateRatingsBefore($cutoff);

        $this->assertArrayNotHasKey($this->teamA->id, $ratings);
        $this->assertArrayNotHasKey($this->teamB->id, $ratings);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [S] calculateForMatch with null kickoff returns INITIAL_ELO for both
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_kickoff_returns_initial_elo_for_both(): void
    {
        $match = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => null,
            'status'         => 'scheduled',
        ]);

        $result = TeamEloCalculator::calculateForMatch($match);

        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO, $result['home_elo'], self::DELTA);
        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO, $result['away_elo'], self::DELTA);
        $this->assertEqualsWithDelta(0.0, $result['elo_difference'], self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [T] One DB query for calculateRatingsBefore (no N+1)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_calculates_with_single_db_query(): void
    {
        $teamC = Team::create(['name' => 'TeamC', 'type' => 'club', 'is_active' => true]);

        $this->makeMatch(Carbon::parse(self::TARGET)->subDays(14), $this->teamA, $this->teamB, 1, 0);
        $this->makeMatch(Carbon::parse(self::TARGET)->subDays(7),  $this->teamA, $teamC,       2, 1);

        DB::enableQueryLog();
        TeamEloCalculator::calculateRatingsBefore(Carbon::parse(self::TARGET));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

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
}
