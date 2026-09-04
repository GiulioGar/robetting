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
use App\Services\Analytics\TeamStarterContinuityCalculator;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for TeamStarterContinuityCalculator.
 *
 * Consecutive-pair model:
 *   retained = |XI_i ∩ XI_{i+1}|
 *   changed  = min(|XI_i|, |XI_{i+1}|) − retained
 *
 * Tests:
 *  [A] 5 identical XI → retained=11 every pair, changed=0
 *  [B] 1 change per match → retained=10, changed=1
 *  [C] strong rotation → retained=0, changed=11
 *  [D] players_started_4_of_last_5 and players_started_5_of_last_5
 *  [E] distinct_starters_last_5
 *  [F] fewer than 5 matches
 *  [G] incomplete XI handled robustly
 *  [H] coverage metrics correct
 *  [I] target match excluded
 *  [J] future match excluded
 *  [K] non-definitive match excluded
 *  [L] other team excluded
 *  [M] transferred player only counts for requested team
 *  [N] all competitions included
 *  [O] no N+1: exactly 2 DB queries
 *  [P] no history → emptyResult
 *  [Q] null kickoff_at → emptyResult
 *  [R] single match → averages null, per-player counts work
 */
class TeamStarterContinuityCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Team $teamA;
    private Team $teamB;
    private Competition $comp;
    private Season $season;
    private Carbon $target;

    private const TARGET = '2026-09-10 20:45:00';

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
    // [A] 5 identical XI → max retained, zero changed
    // ─────────────────────────────────────────────────────────────────────────

    public function test_five_identical_xi_max_retained_zero_changed(): void
    {
        $target  = $this->makeTarget();
        $players = $this->createPlayers(11);

        for ($i = 1; $i <= 5; $i++) {
            $match = $this->makePrev($this->target->copy()->subDays($i * 7));
            $this->addStarters($match, $this->teamA, $players);
        }

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertEqualsWithDelta(11.0, $result['average_starters_retained'], 0.001);
        $this->assertEqualsWithDelta(0.0,  $result['average_starters_changed'],  0.001);
        $this->assertSame(4,  $result['complete_transitions_count']); // 4 pairs from 5 matches
        $this->assertSame(11, $result['players_started_5_of_last_5']);
        $this->assertSame(11, $result['players_started_4_of_last_5']);
        $this->assertSame(11, $result['distinct_starters_last_5']);
        $this->assertSame(5,  $result['matches_considered']);
        $this->assertSame(5,  $result['matches_with_complete_starting_xi']);
        $this->assertEqualsWithDelta(100.0, $result['lineup_coverage_percentage'], 0.1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] 1 change per match
    // ─────────────────────────────────────────────────────────────────────────

    public function test_one_change_per_match(): void
    {
        $target = $this->makeTarget();
        $core   = $this->createPlayers(10);   // players 1-10 always start
        $extra  = $this->createPlayers(5);    // players 11-15 rotate in one per match

        // Chronological order: M1 (oldest) → M5 (newest)
        for ($i = 1; $i <= 5; $i++) {
            $match = $this->makePrev($this->target->copy()->subDays((6 - $i) * 7));
            $this->addStarters($match, $this->teamA, array_merge($core, [$extra[$i - 1]]));
        }

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        // Each pair: 10 core retained, 1 changed (out of 11) — all 4 pairs complete
        $this->assertEqualsWithDelta(10.0, $result['average_starters_retained'], 0.001);
        $this->assertEqualsWithDelta(1.0,  $result['average_starters_changed'],  0.001);
        $this->assertSame(4, $result['complete_transitions_count']);
        // Core 10 players started all 5
        $this->assertSame(10, $result['players_started_5_of_last_5']);
        $this->assertSame(10, $result['players_started_4_of_last_5']);
        // 10 core + 5 rotating
        $this->assertSame(15, $result['distinct_starters_last_5']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] Strong rotation — two groups alternating
    // ─────────────────────────────────────────────────────────────────────────

    public function test_strong_rotation(): void
    {
        $target  = $this->makeTarget();
        $groupA  = $this->createPlayers(11); // M1, M3, M5
        $groupB  = $this->createPlayers(11); // M2, M4

        $groups = [$groupA, $groupB, $groupA, $groupB, $groupA]; // M1→M5

        for ($i = 0; $i < 5; $i++) {
            $match = $this->makePrev($this->target->copy()->subDays((5 - $i) * 7));
            $this->addStarters($match, $this->teamA, $groups[$i]);
        }

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        // Every consecutive pair has zero overlap
        $this->assertEqualsWithDelta(0.0,  $result['average_starters_retained'], 0.001);
        $this->assertEqualsWithDelta(11.0, $result['average_starters_changed'],  0.001);
        // GroupA appears 3 times, GroupB 2 times — nobody reaches 4
        $this->assertSame(0,  $result['players_started_4_of_last_5']);
        $this->assertSame(0,  $result['players_started_5_of_last_5']);
        $this->assertSame(22, $result['distinct_starters_last_5']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] players_started_4_of_last_5 and players_started_5_of_last_5
    // ─────────────────────────────────────────────────────────────────────────

    public function test_players_started_4_and_5_of_last_5(): void
    {
        $target = $this->makeTarget();
        $pA     = $this->makePlayer(); // starts all 5
        $pB     = $this->makePlayer(); // starts 4
        $pC     = $this->makePlayer(); // starts 3

        // 5 matches chronologically; make kickoffs spread out
        for ($i = 1; $i <= 5; $i++) {
            $match    = $this->makePrev($this->target->copy()->subDays((6 - $i) * 5));
            $starters = [];
            if ($i <= 5) { $starters[] = $pA; }  // always
            if ($i <= 4) { $starters[] = $pB; }  // M1–M4
            if ($i <= 3) { $starters[] = $pC; }  // M1–M3
            $this->addStarters($match, $this->teamA, $starters);
        }

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(1, $result['players_started_5_of_last_5']); // only pA
        $this->assertSame(2, $result['players_started_4_of_last_5']); // pA (5) and pB (4)
        $this->assertSame(3, $result['distinct_starters_last_5']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] distinct_starters_last_5 distinct across all matches
    // ─────────────────────────────────────────────────────────────────────────

    public function test_distinct_starters_last_5_correct(): void
    {
        $target = $this->makeTarget();

        // 3 matches with 3 unique players each, no overlap
        $groups = [
            $this->createPlayers(3),
            $this->createPlayers(3),
            $this->createPlayers(3),
        ];
        foreach ($groups as $i => $players) {
            $match = $this->makePrev($this->target->copy()->subDays(($i + 1) * 7));
            $this->addStarters($match, $this->teamA, $players);
        }

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(9, $result['distinct_starters_last_5']);
        $this->assertSame(3, $result['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] Fewer than 5 matches available
    // ─────────────────────────────────────────────────────────────────────────

    public function test_fewer_than_5_matches_available(): void
    {
        $target  = $this->makeTarget();
        $players = $this->createPlayers(11);

        // Only 3 matches
        for ($i = 1; $i <= 3; $i++) {
            $match = $this->makePrev($this->target->copy()->subDays($i * 7));
            $this->addStarters($match, $this->teamA, $players);
        }

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(3, $result['matches_considered']);
        // 2 pairs instead of 4
        $this->assertEqualsWithDelta(11.0, $result['average_starters_retained'], 0.001);
        $this->assertEqualsWithDelta(0.0,  $result['average_starters_changed'],  0.001);
        // No one can have started 5 matches (only 3 available)
        $this->assertSame(0,  $result['players_started_5_of_last_5']);
        // No one can have started 4 matches (only 3 available)
        $this->assertSame(0,  $result['players_started_4_of_last_5']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] Incomplete pair excluded; adjacent complete pair still counted
    // ─────────────────────────────────────────────────────────────────────────

    public function test_incomplete_xi_handled_robustly(): void
    {
        $target = $this->makeTarget();

        // 3 matches chronologically: M1(11) → M2(11) → M3(9)
        // Pair (M1, M2): COMPLETE → retained = 9, changed = 2
        // Pair (M2, M3): M3 incomplete → SKIPPED
        // complete_transitions_count = 1
        $core    = $this->createPlayers(9); // 9 core players in all three matches
        $m1Extra = $this->createPlayers(2); // only in M1 → M1 = 11
        $m2Extra = $this->createPlayers(2); // only in M2 → M2 = 11

        $m1 = $this->makePrev($this->target->copy()->subDays(21));
        $this->addStarters($m1, $this->teamA, array_merge($core, $m1Extra)); // 11 ✓

        $m2 = $this->makePrev($this->target->copy()->subDays(14));
        $this->addStarters($m2, $this->teamA, array_merge($core, $m2Extra)); // 11 ✓

        $m3 = $this->makePrev($this->target->copy()->subDays(7));
        $this->addStarters($m3, $this->teamA, $core); // 9 ✗ incomplete

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        // Complete pair (M1,M2): retained=9 core, changed=11-9=2
        $this->assertEqualsWithDelta(9.0, $result['average_starters_retained'], 0.001);
        $this->assertEqualsWithDelta(2.0, $result['average_starters_changed'],  0.001);
        $this->assertSame(1, $result['complete_transitions_count']);
        // M1 and M2 are complete, M3 is not
        $this->assertSame(2, $result['matches_with_complete_starting_xi']);
        $this->assertSame(3, $result['matches_considered']);
        $this->assertEqualsWithDelta(66.67, $result['lineup_coverage_percentage'], 0.1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G2] No complete transitions → averages null
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_complete_transitions_returns_null_averages(): void
    {
        $target = $this->makeTarget();

        // M1 complete (11), M2 incomplete (9) → only one pair, not complete
        $core    = $this->createPlayers(9);
        $m1Extra = $this->createPlayers(2); // M1 = 11

        $m1 = $this->makePrev($this->target->copy()->subDays(14));
        $this->addStarters($m1, $this->teamA, array_merge($core, $m1Extra)); // 11 ✓

        $m2 = $this->makePrev($this->target->copy()->subDays(7));
        $this->addStarters($m2, $this->teamA, $core); // 9 ✗

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        // The single pair is incomplete → no data for the means
        $this->assertNull($result['average_starters_retained']);
        $this->assertNull($result['average_starters_changed']);
        $this->assertSame(0, $result['complete_transitions_count']);
        // Coverage still reflects reality
        $this->assertSame(1, $result['matches_with_complete_starting_xi']); // only M1
        $this->assertSame(2, $result['matches_considered']);
        $this->assertEqualsWithDelta(50.0, $result['lineup_coverage_percentage'], 0.1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] Coverage percentage correct
    // ─────────────────────────────────────────────────────────────────────────

    public function test_coverage_percentage_correct(): void
    {
        $target  = $this->makeTarget();
        $full11  = $this->createPlayers(11);
        $partial = $this->createPlayers(7);

        // 3 complete + 2 incomplete out of 5 matches
        for ($i = 1; $i <= 5; $i++) {
            $match = $this->makePrev($this->target->copy()->subDays($i * 7));
            $this->addStarters($match, $this->teamA, $i <= 3 ? $full11 : $partial);
        }

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(5, $result['matches_considered']);
        $this->assertSame(3, $result['matches_with_complete_starting_xi']);
        $this->assertEqualsWithDelta(60.0, $result['lineup_coverage_percentage'], 0.1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] Target match excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_target_match_excluded(): void
    {
        $target  = $this->makeTarget();
        $players = $this->createPlayers(11);

        // Stat on the target match itself → must be ignored
        $this->addStarters($target, $this->teamA, $players);

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(0, $result['matches_considered']);
        $this->assertNull($result['average_starters_retained']);
        $this->assertSame(0, $result['distinct_starters_last_5']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J] Future match excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_future_match_excluded(): void
    {
        $target  = $this->makeTarget();
        $players = $this->createPlayers(11);

        $future = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $this->target->copy()->addDays(7),
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);
        $this->addStarters($future, $this->teamA, $players);

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(0, $result['matches_considered']);
        $this->assertNull($result['average_starters_retained']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [K] Non-definitive match excluded (status = 'live')
    // ─────────────────────────────────────────────────────────────────────────

    public function test_non_definitive_match_excluded(): void
    {
        $target  = $this->makeTarget();
        $players = $this->createPlayers(11);

        $live = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $this->target->copy()->subDays(3),
            'status'         => 'live',
        ]);
        $this->addStarters($live, $this->teamA, $players);

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(0, $result['matches_considered']);
        $this->assertNull($result['average_starters_retained']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [L] Other team's starters excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_other_team_starters_excluded(): void
    {
        $target    = $this->makeTarget();
        $aPlayers  = $this->createPlayers(11);
        $bPlayers  = $this->createPlayers(11);

        $match = $this->makePrev($this->target->copy()->subDays(7));
        $this->addStarters($match, $this->teamA, $aPlayers);
        $this->addStarters($match, $this->teamB, $bPlayers); // opponent starters

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        // Only teamA's starters count; only 1 match → no pairs → averages null
        $this->assertSame(1,  $result['matches_considered']);
        $this->assertSame(11, $result['distinct_starters_last_5']);
        $this->assertNull($result['average_starters_retained']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [M] Transferred player: counted only for the requested team
    // ─────────────────────────────────────────────────────────────────────────

    public function test_transferred_player_counted_only_for_requested_team(): void
    {
        $target = $this->makeTarget();
        $player = $this->makePlayer();

        // Player starts for teamB in one match
        $matchB = $this->makePrev($this->target->copy()->subDays(14));
        $this->addStarters($matchB, $this->teamB, [$player]);

        // Player starts for teamA in another match (after transfer)
        $matchA = $this->makePrev($this->target->copy()->subDays(7));
        $this->addStarters($matchA, $this->teamA, [$player]);

        $resultA = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        // Only 1 match for teamA → player started once → 1 distinct starter
        $this->assertSame(1, $resultA['matches_considered']);
        $this->assertSame(1, $resultA['distinct_starters_last_5']);

        $resultB = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamB->id);
        $this->assertSame(1, $resultB['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [N] All competitions included
    // ─────────────────────────────────────────────────────────────────────────

    public function test_all_competitions_included(): void
    {
        $target = $this->makeTarget();

        $country2 = Country::create(['name' => 'Europe', 'football_code' => 'EU']);
        $comp2    = Competition::create([
            'country_id' => $country2->id, 'name' => 'Champions League',
            'slug' => 'champions-league', 'format' => 'cup', 'is_active' => true,
        ]);
        $season2  = Season::create([
            'competition_id' => $comp2->id, 'name' => '2026/27',
            'year_start' => 2026, 'year_end' => 2027, 'is_current' => true,
        ]);

        $playersA = $this->createPlayers(11); // Serie A
        $playersB = $this->createPlayers(11); // Champions League — fully different XI

        $matchSA = $this->makePrev($this->target->copy()->subDays(14));
        $this->addStarters($matchSA, $this->teamA, $playersA);

        $matchCL = FootballMatch::create([
            'competition_id' => $comp2->id,
            'season_id'      => $season2->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $this->target->copy()->subDays(7),
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 0,
        ]);
        $this->addStarters($matchCL, $this->teamA, $playersB);

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(2,  $result['matches_considered']);
        $this->assertSame(22, $result['distinct_starters_last_5']);
        // Completely different XIs → retained=0 for the one pair
        $this->assertEqualsWithDelta(0.0,  $result['average_starters_retained'], 0.001);
        $this->assertEqualsWithDelta(11.0, $result['average_starters_changed'],  0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [O] No N+1: exactly 2 DB queries
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_n_plus_1_queries(): void
    {
        $target  = $this->makeTarget();
        $players = $this->createPlayers(11);

        for ($i = 1; $i <= 5; $i++) {
            $match = $this->makePrev($this->target->copy()->subDays($i * 7));
            $this->addStarters($match, $this->teamA, $players);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Q1: FootballMatch, Q2: MatchPlayerStatistic
        $this->assertCount(2, $queries, 'Expected exactly 2 DB queries (no N+1)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [P] No history → emptyResult
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_history_returns_empty_result(): void
    {
        $target = $this->makeTarget();

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertNull($result['average_starters_retained']);
        $this->assertNull($result['average_starters_changed']);
        $this->assertSame(0, $result['complete_transitions_count']);
        $this->assertSame(0, $result['players_started_4_of_last_5']);
        $this->assertSame(0, $result['players_started_5_of_last_5']);
        $this->assertSame(0, $result['distinct_starters_last_5']);
        $this->assertSame(0, $result['matches_considered']);
        $this->assertSame(0, $result['matches_with_complete_starting_xi']);
        $this->assertNull($result['lineup_coverage_percentage']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [Q] Null kickoff_at → emptyResult
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

        $result = TeamStarterContinuityCalculator::calculateForMatch($match, $this->teamA->id);

        $this->assertSame(0, $result['matches_considered']);
        $this->assertNull($result['average_starters_retained']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [R] Single match → averages null, per-player counts still valid
    // ─────────────────────────────────────────────────────────────────────────

    public function test_single_match_averages_null_counts_valid(): void
    {
        $target  = $this->makeTarget();
        $players = $this->createPlayers(11);

        $match = $this->makePrev($this->target->copy()->subDays(7));
        $this->addStarters($match, $this->teamA, $players);

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        // No pairs to compare → averages undefined
        $this->assertNull($result['average_starters_retained']);
        $this->assertNull($result['average_starters_changed']);
        // Per-player counts from the 1 match are valid
        $this->assertSame(11, $result['distinct_starters_last_5']);
        $this->assertSame(0,  $result['players_started_4_of_last_5']); // need ≥4, only 1
        $this->assertSame(0,  $result['players_started_5_of_last_5']); // need ≥5, only 1
        $this->assertSame(1,  $result['matches_considered']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Awarded and walkover statuses included
    // ─────────────────────────────────────────────────────────────────────────

    public function test_awarded_and_walkover_statuses_included(): void
    {
        $target   = $this->makeTarget();
        $players1 = $this->createPlayers(11);
        $players2 = $this->createPlayers(11);

        $awarded = FootballMatch::create([
            'competition_id' => $this->comp->id, 'season_id' => $this->season->id,
            'home_team_id' => $this->teamA->id, 'away_team_id' => $this->teamB->id,
            'kickoff_at' => $this->target->copy()->subDays(14), 'status' => 'awarded',
        ]);
        $this->addStarters($awarded, $this->teamA, $players1);

        $walkover = FootballMatch::create([
            'competition_id' => $this->comp->id, 'season_id' => $this->season->id,
            'home_team_id' => $this->teamB->id, 'away_team_id' => $this->teamA->id,
            'kickoff_at' => $this->target->copy()->subDays(7), 'status' => 'walkover',
        ]);
        $this->addStarters($walkover, $this->teamA, $players2);

        $result = TeamStarterContinuityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(2,  $result['matches_considered']);
        // Two completely different XIs
        $this->assertEqualsWithDelta(0.0, $result['average_starters_retained'], 0.001);
        $this->assertSame(22, $result['distinct_starters_last_5']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Creates the target match (default: scheduled). */
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

    /** Creates a finished match before target. */
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

    /** Creates a single player. */
    private function makePlayer(): Player
    {
        static $i = 0;
        return Player::create(['name' => 'Player_' . (++$i)]);
    }

    /** Creates N players and returns them as an array. */
    private function createPlayers(int $n): array
    {
        $players = [];
        for ($i = 0; $i < $n; $i++) {
            $players[] = $this->makePlayer();
        }
        return $players;
    }

    /** Inserts a starter row for each player in $players for $match / $team. */
    private function addStarters(FootballMatch $match, Team $team, array $players): void
    {
        foreach ($players as $player) {
            MatchPlayerStatistic::create([
                'match_id'         => $match->id,
                'player_id'        => $player->id,
                'team_id'          => $team->id,
                'data_source_id'   => $this->ds->id,
                'games_substitute' => false,
                'games_minutes'    => 90,
            ]);
        }
    }
}
