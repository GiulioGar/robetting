<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchPlayerStatistic;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\TeamAgeProfileCalculator;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for TeamAgeProfileCalculator.
 *
 * Age reference convention tested throughout: TARGET KICKOFF, not now.
 *
 * Helper ageAt() replicates the calculator's own formula so tests are
 * always consistent with the implementation, regardless of the exact
 * float produced by the timestamp arithmetic.
 *
 * Rules under test:
 *  [A] average_age_used_last_5 correct (unique players)
 *  [B] weighted_average_age_last_5 correct (weighted by minutes)
 *  [C] average_starter_age_last_5 correct (per-appearance, not per-player)
 *  [D] age computed at TARGET kickoff, not at current date
 *  [E] birth_date NULL excluded from age averages, coverage counts correct
 *  [F] player duplicated across matches counts once for average_age_used
 *  [G] same player as starter in multiple matches contributes multiple times
 *  [H] target match excluded (strict < cutoff)
 *  [I] future match excluded (anti-leakage)
 *  [J] non-definitive match excluded
 *  [K] player from different team excluded
 *  [L] transferred player: only stats for requested team count
 *  [M] all competitions included
 *  [N] no N+1: exactly 3 DB queries regardless of player count
 *  [O] no previous matches / no stats → emptyResult (all null / 0)
 *  [P] all birth_dates null → averages null, coverage 0%
 *  [Q] null kickoff_at → emptyResult
 */
class TeamAgeProfileCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Team $teamA;
    private Team $teamB;
    private Competition $comp;
    private Season $season;
    private Carbon $target;

    private const TARGET = '2026-09-10 20:45:00';

    /** Seconds per year used in the calculator — must mirror the constant. */
    private const SECONDS_PER_YEAR = 365.25 * 86400;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

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
        $this->teamA  = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->teamB  = Team::create(['name' => 'Milan',  'type' => 'club', 'is_active' => true]);
        $this->target = Carbon::parse(self::TARGET);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [A] average_age_used_last_5: unique player count
    // ─────────────────────────────────────────────────────────────────────────

    public function test_average_age_used_last_5_correct(): void
    {
        $target  = $this->makeTarget();
        $pA      = $this->makePlayer('1996-09-10'); // ~30 yrs at target
        $pB      = $this->makePlayer('2001-03-15'); // ~25.5 yrs at target
        $pC      = $this->makePlayer('2004-06-01'); // ~22.3 yrs at target

        $prev = $this->makePrev($this->target->copy()->subDays(5));
        $this->addStat($prev, $pA, $this->teamA, 90);
        $this->addStat($prev, $pB, $this->teamA, 85);
        $this->addStat($prev, $pC, $this->teamA, 80);

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        $expected = ($this->ageAt('1996-09-10') + $this->ageAt('2001-03-15') + $this->ageAt('2004-06-01')) / 3;
        $this->assertEqualsWithDelta($expected, $result['average_age_used_last_5'], 0.001);
        $this->assertSame(3, $result['players_used_count']);
        $this->assertSame(3, $result['players_with_birth_date_count']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] weighted_average_age_last_5: weighted by minutes
    // ─────────────────────────────────────────────────────────────────────────

    public function test_weighted_average_age_last_5_correct(): void
    {
        $target = $this->makeTarget();
        $pA     = $this->makePlayer('1996-09-10'); // ~30 yrs
        $pB     = $this->makePlayer('2006-09-10'); // ~20 yrs

        $prev = $this->makePrev($this->target->copy()->subDays(5));
        $this->addStat($prev, $pA, $this->teamA, 90);  // 90 min
        $this->addStat($prev, $pB, $this->teamA, 30);  // 30 min

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        $ageA = $this->ageAt('1996-09-10');
        $ageB = $this->ageAt('2006-09-10');
        // weighted = (ageA*90 + ageB*30) / 120
        $expected = ($ageA * 90 + $ageB * 30) / 120;
        $this->assertEqualsWithDelta($expected, $result['weighted_average_age_last_5'], 0.001);

        // Simple average would be (ageA + ageB) / 2 — verify they differ
        $simpleAvg = ($ageA + $ageB) / 2;
        $this->assertNotEqualsWithDelta($simpleAvg, $result['weighted_average_age_last_5'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] average_starter_age_last_5: per appearance, not per unique player
    // ─────────────────────────────────────────────────────────────────────────

    public function test_average_starter_age_last_5_per_appearance(): void
    {
        $target  = $this->makeTarget();
        $pA      = $this->makePlayer('1996-09-10'); // ~30 yrs — starts 3 times
        $pB      = $this->makePlayer('2006-09-10'); // ~20 yrs — starts 1 time

        for ($i = 1; $i <= 3; $i++) {
            $prev = $this->makePrev($this->target->copy()->subDays($i * 5));
            $this->addStat($prev, $pA, $this->teamA, 90, isSub: false); // pA starts
            $this->addStat($prev, $pB, $this->teamA, 90, isSub: $i > 1); // pB starts only in match 1
        }

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        $ageA = $this->ageAt('1996-09-10');
        $ageB = $this->ageAt('2006-09-10');

        // starter entries: pA×3 + pB×1 = 4 total
        // average = (ageA + ageA + ageA + ageB) / 4
        $expected = (3 * $ageA + $ageB) / 4;
        $this->assertEqualsWithDelta($expected, $result['average_starter_age_last_5'], 0.001);

        // verify it's NOT the unique-player average
        $uniqueAvg = ($ageA + $ageB) / 2;
        $this->assertNotEqualsWithDelta($uniqueAvg, $result['average_starter_age_last_5'], 0.01);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] Age calculated at TARGET kickoff, not at current date
    // ─────────────────────────────────────────────────────────────────────────

    public function test_age_at_target_kickoff_not_current_date(): void
    {
        $target = $this->makeTarget();
        // Player born 30 years before target (same month/day)
        $born   = '1996-09-10';
        $player = $this->makePlayer($born);

        $prev = $this->makePrev($this->target->copy()->subDays(5));
        $this->addStat($prev, $player, $this->teamA, 90);

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        $ageAtTarget = $this->ageAt($born);           // ~30.000
        $ageAtNow    = $this->ageAtNow($born);         // ~29.986 (5 days before target)

        // Result must match age at TARGET, not at now
        $this->assertEqualsWithDelta($ageAtTarget, $result['average_age_used_last_5'], 0.001);

        // Verify the two values actually differ (so the test is meaningful)
        $this->assertGreaterThan(0.001, abs($ageAtTarget - $ageAtNow));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] birth_date NULL excluded from averages; coverage tracked
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_birth_date_excluded_from_averages(): void
    {
        $target    = $this->makeTarget();
        $withBirth = $this->makePlayer('1996-09-10');
        $nullBirth = Player::create(['name' => 'NoBirth']); // no birth_date

        $prev = $this->makePrev($this->target->copy()->subDays(5));
        $this->addStat($prev, $withBirth, $this->teamA, 90);
        $this->addStat($prev, $nullBirth, $this->teamA, 80);

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        // Only withBirth contributes to the average
        $this->assertEqualsWithDelta($this->ageAt('1996-09-10'), $result['average_age_used_last_5'], 0.001);
        // Both counted as "used"
        $this->assertSame(2, $result['players_used_count']);
        // Only 1 has birth_date
        $this->assertSame(1, $result['players_with_birth_date_count']);
        $this->assertEqualsWithDelta(50.0, $result['birth_date_coverage_percentage'], 0.1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] Player in multiple matches counts ONCE for average_age_used
    // ─────────────────────────────────────────────────────────────────────────

    public function test_player_in_multiple_matches_counts_once_for_average_age_used(): void
    {
        $target = $this->makeTarget();
        $player = $this->makePlayer('1996-09-10');

        // Same player in 3 of the last 5 matches
        for ($i = 1; $i <= 3; $i++) {
            $prev = $this->makePrev($this->target->copy()->subDays($i * 4));
            $this->addStat($prev, $player, $this->teamA, 90);
        }

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        // Only 1 unique player → average = that player's age
        $this->assertEqualsWithDelta($this->ageAt('1996-09-10'), $result['average_age_used_last_5'], 0.001);
        $this->assertSame(1, $result['players_used_count']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] Same player as starter multiple times → multiple entries in starter avg
    // ─────────────────────────────────────────────────────────────────────────

    public function test_same_player_multiple_starts_contributes_multiple_times(): void
    {
        $target = $this->makeTarget();
        $pA     = $this->makePlayer('1996-09-10'); // ~30 yrs
        $pB     = $this->makePlayer('2006-09-10'); // ~20 yrs

        // pA starts 4 times; pB starts 1 time
        for ($i = 1; $i <= 4; $i++) {
            $prev = $this->makePrev($this->target->copy()->subDays($i * 4));
            $this->addStat($prev, $pA, $this->teamA, 90, isSub: false);
        }
        $prev5 = $this->makePrev($this->target->copy()->subDays(20));
        $this->addStat($prev5, $pB, $this->teamA, 90, isSub: false);

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        $ageA = $this->ageAt('1996-09-10');
        $ageB = $this->ageAt('2006-09-10');
        // 4 pA entries + 1 pB entry = 5 total starter slots
        $expected = (4 * $ageA + $ageB) / 5;
        $this->assertEqualsWithDelta($expected, $result['average_starter_age_last_5'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] Target match itself excluded (strict < cutoff)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_target_match_excluded(): void
    {
        $target = $this->makeTarget();
        $player = $this->makePlayer('1996-09-10');

        // Stat on the target match itself
        $this->addStat($target, $player, $this->teamA, 90);

        // Stat on a valid prior match
        $prev = $this->makePrev($this->target->copy()->subDays(7));
        $this->addStat($prev, $player, $this->teamA, 80);

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        // Only the prior match counts → 1 appearance, not 2
        $this->assertSame(1, $result['players_used_count']);
        // Average age still valid (1 player)
        $this->assertEqualsWithDelta($this->ageAt('1996-09-10'), $result['average_age_used_last_5'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] Future match excluded (anti-leakage)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_future_match_excluded(): void
    {
        $target = $this->makeTarget();
        $player = $this->makePlayer('1996-09-10');

        // Match AFTER target kickoff
        $future = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $this->target->copy()->addDays(5),
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);
        $this->addStat($future, $player, $this->teamA, 90);

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertNull($result['average_age_used_last_5']);
        $this->assertSame(0, $result['players_used_count']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J] Non-definitive match excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_non_definitive_match_excluded(): void
    {
        $target = $this->makeTarget();
        $player = $this->makePlayer('1996-09-10');

        // 'live' match before target
        $liveMatch = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $this->target->copy()->subDays(3),
            'status'         => 'live',
        ]);
        $this->addStat($liveMatch, $player, $this->teamA, 60);

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertNull($result['average_age_used_last_5']);
        $this->assertSame(0, $result['players_used_count']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [K] Player from different team excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_player_from_other_team_excluded(): void
    {
        $target    = $this->makeTarget();
        $pTeamA    = $this->makePlayer('1996-09-10');
        $pTeamB    = $this->makePlayer('2000-01-01');

        $prev = $this->makePrev($this->target->copy()->subDays(5));
        $this->addStat($prev, $pTeamA, $this->teamA, 90);
        $this->addStat($prev, $pTeamB, $this->teamB, 90); // different team

        // Query for teamA only
        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(1, $result['players_used_count']);
        $this->assertEqualsWithDelta($this->ageAt('1996-09-10'), $result['average_age_used_last_5'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [L] Transferred player: only stats for requested team count
    // ─────────────────────────────────────────────────────────────────────────

    public function test_transferred_player_only_counts_for_requested_team(): void
    {
        $target = $this->makeTarget();
        $player = $this->makePlayer('1996-09-10');

        // Stats for teamB (before transfer)
        $prevB = $this->makePrev($this->target->copy()->subDays(20));
        $this->addStat($prevB, $player, $this->teamB, 90);

        // Stats for teamA (after transfer)
        $prevA = $this->makePrev($this->target->copy()->subDays(5));
        $this->addStat($prevA, $player, $this->teamA, 70);

        $resultA = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        // Only the teamA stat is used for teamA's profile
        $this->assertSame(1, $resultA['players_used_count']);
        $this->assertEqualsWithDelta($this->ageAt('1996-09-10'), $resultA['average_age_used_last_5'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [M] All competitions included
    // ─────────────────────────────────────────────────────────────────────────

    public function test_all_competitions_included(): void
    {
        $target = $this->makeTarget();

        // Create a second competition
        $country2 = Country::create(['name' => 'Europe', 'football_code' => 'EU']);
        $comp2    = Competition::create([
            'country_id' => $country2->id, 'name' => 'Champions League',
            'slug' => 'champions-league', 'format' => 'cup', 'is_active' => true,
        ]);
        $season2  = Season::create([
            'competition_id' => $comp2->id, 'name' => '2026/27',
            'year_start' => 2026, 'year_end' => 2027, 'is_current' => true,
        ]);

        $pA = $this->makePlayer('1996-09-10'); // Serie A match
        $pB = $this->makePlayer('2001-01-01'); // Champions League match

        $prevA = $this->makePrev($this->target->copy()->subDays(5));
        $this->addStat($prevA, $pA, $this->teamA, 90);

        $prevCL = FootballMatch::create([
            'competition_id' => $comp2->id,
            'season_id'      => $season2->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $this->target->copy()->subDays(10),
            'status'         => 'finished',
            'home_score_ft'  => 2, 'away_score_ft' => 1,
        ]);
        $this->addStat($prevCL, $pB, $this->teamA, 85);

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        // Both players (from different competitions) included
        $this->assertSame(2, $result['players_used_count']);
        $expected = ($this->ageAt('1996-09-10') + $this->ageAt('2001-01-01')) / 2;
        $this->assertEqualsWithDelta($expected, $result['average_age_used_last_5'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [N] No N+1: exactly 3 DB queries regardless of player count
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_n_plus_1_queries(): void
    {
        $target = $this->makeTarget();

        // 10 players across 3 matches
        $prev1 = $this->makePrev($this->target->copy()->subDays(5));
        $prev2 = $this->makePrev($this->target->copy()->subDays(10));
        $prev3 = $this->makePrev($this->target->copy()->subDays(15));

        for ($i = 1; $i <= 10; $i++) {
            $p = $this->makePlayer('1996-01-01');
            $this->addStat($prev1, $p, $this->teamA, 90);
            $this->addStat($prev2, $p, $this->teamA, 85);
            $this->addStat($prev3, $p, $this->teamA, 80);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Q1: FootballMatch, Q2: MatchPlayerStatistic, Q3: Player
        $this->assertCount(3, $queries, 'Expected exactly 3 DB queries (no N+1)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [O] No previous matches → emptyResult
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_previous_matches_returns_empty_result(): void
    {
        $target = $this->makeTarget();

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertNull($result['average_age_used_last_5']);
        $this->assertNull($result['weighted_average_age_last_5']);
        $this->assertNull($result['average_starter_age_last_5']);
        $this->assertSame(0, $result['players_used_count']);
        $this->assertSame(0, $result['players_with_birth_date_count']);
        $this->assertNull($result['birth_date_coverage_percentage']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [P] All birth_dates null → averages null, counts correct
    // ─────────────────────────────────────────────────────────────────────────

    public function test_all_birth_dates_null_gives_null_averages(): void
    {
        $target = $this->makeTarget();
        $p1     = Player::create(['name' => 'NoBirth1']);
        $p2     = Player::create(['name' => 'NoBirth2']);

        $prev = $this->makePrev($this->target->copy()->subDays(5));
        $this->addStat($prev, $p1, $this->teamA, 90);
        $this->addStat($prev, $p2, $this->teamA, 80);

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertNull($result['average_age_used_last_5']);
        $this->assertNull($result['weighted_average_age_last_5']);
        $this->assertNull($result['average_starter_age_last_5']);
        $this->assertSame(2, $result['players_used_count']);
        $this->assertSame(0, $result['players_with_birth_date_count']);
        $this->assertEqualsWithDelta(0.0, $result['birth_date_coverage_percentage'], 0.1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [Q] Null kickoff_at → immediate emptyResult
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_kickoff_returns_empty_result(): void
    {
        $match = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => null,
            'status'         => 'tbd',
        ]);

        $result = TeamAgeProfileCalculator::calculateForMatch($match, $this->teamA->id);

        $this->assertSame(0, $result['players_used_count']);
        $this->assertNull($result['average_age_used_last_5']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // weighted average: null minutes excluded from weight
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_minutes_excluded_from_weighted_average(): void
    {
        $target = $this->makeTarget();
        $pA     = $this->makePlayer('1996-09-10'); // ~30 yrs, real minutes
        $pB     = $this->makePlayer('2006-09-10'); // ~20 yrs, null minutes

        $prev = $this->makePrev($this->target->copy()->subDays(5));
        $this->addStat($prev, $pA, $this->teamA, 90);
        $this->addStat($prev, $pB, $this->teamA, null); // null minutes

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        // Weighted average uses only pA's 90 minutes → equals pA's age alone
        $this->assertEqualsWithDelta($this->ageAt('1996-09-10'), $result['weighted_average_age_last_5'], 0.001);
        // But average_age_used includes both
        $expected = ($this->ageAt('1996-09-10') + $this->ageAt('2006-09-10')) / 2;
        $this->assertEqualsWithDelta($expected, $result['average_age_used_last_5'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // awarded / walkover statuses included
    // ─────────────────────────────────────────────────────────────────────────

    public function test_awarded_and_walkover_included(): void
    {
        $target = $this->makeTarget();
        $pA     = $this->makePlayer('1996-09-10');
        $pB     = $this->makePlayer('2001-01-01');

        $awarded = FootballMatch::create([
            'competition_id' => $this->comp->id, 'season_id' => $this->season->id,
            'home_team_id' => $this->teamA->id, 'away_team_id' => $this->teamB->id,
            'kickoff_at' => $this->target->copy()->subDays(4), 'status' => 'awarded',
        ]);
        $this->addStat($awarded, $pA, $this->teamA, 80);

        $walkover = FootballMatch::create([
            'competition_id' => $this->comp->id, 'season_id' => $this->season->id,
            'home_team_id' => $this->teamB->id, 'away_team_id' => $this->teamA->id,
            'kickoff_at' => $this->target->copy()->subDays(10), 'status' => 'walkover',
        ]);
        $this->addStat($walkover, $pB, $this->teamA, 0);

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(2, $result['players_used_count']);
        $expected = ($this->ageAt('1996-09-10') + $this->ageAt('2001-01-01')) / 2;
        $this->assertEqualsWithDelta($expected, $result['average_age_used_last_5'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // coverage percentage computed correctly
    // ─────────────────────────────────────────────────────────────────────────

    public function test_coverage_percentage_computed_correctly(): void
    {
        $target   = $this->makeTarget();
        $withBirth = $this->makePlayer('1996-09-10');
        $p2        = Player::create(['name' => 'NoBirth2']);
        $p3        = Player::create(['name' => 'NoBirth3']);

        $prev = $this->makePrev($this->target->copy()->subDays(5));
        $this->addStat($prev, $withBirth, $this->teamA, 90);
        $this->addStat($prev, $p2, $this->teamA, 80);
        $this->addStat($prev, $p3, $this->teamA, 70);

        $result = TeamAgeProfileCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(3, $result['players_used_count']);
        $this->assertSame(1, $result['players_with_birth_date_count']);
        // 1/3 * 100 = 33.333...
        $this->assertEqualsWithDelta(33.33, $result['birth_date_coverage_percentage'], 0.1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Creates the target match for each test. */
    private function makeTarget(string $status = 'scheduled'): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $this->target,
            'status'         => $status,
        ]);
    }

    /** Creates a finished match before the target. */
    private function makePrev(Carbon $kickoff): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $kickoff,
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);
    }

    /** Creates a Player with the given birth_date string. */
    private function makePlayer(string $birthDate): Player
    {
        return Player::create(['name' => "Player_{$birthDate}", 'birth_date' => $birthDate]);
    }

    /** Creates a MatchPlayerStatistic row. */
    private function addStat(
        FootballMatch $match,
        Player $player,
        Team $team,
        ?int $minutes,
        bool $isSub = false,
    ): void {
        MatchPlayerStatistic::create([
            'match_id'         => $match->id,
            'player_id'        => $player->id,
            'team_id'          => $team->id,
            'data_source_id'   => $this->ds->id,
            'games_minutes'    => $minutes,
            'games_substitute' => $isSub,
        ]);
    }

    /**
     * Compute fractional age at the fixed target date — mirrors the calculator's
     * own formula so tests are always consistent with the implementation.
     */
    private function ageAt(string $birthDate): float
    {
        $birthTs  = Carbon::parse($birthDate)->getTimestamp();
        $targetTs = Carbon::parse(self::TARGET)->getTimestamp();
        return ($targetTs - $birthTs) / self::SECONDS_PER_YEAR;
    }

    /** Compute age at Carbon::now() — used to verify the calculator does NOT use now. */
    private function ageAtNow(string $birthDate): float
    {
        $birthTs = Carbon::parse($birthDate)->getTimestamp();
        return (now()->getTimestamp() - $birthTs) / self::SECONDS_PER_YEAR;
    }
}
