<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchPlayerStatistic;
use App\Models\Player;
use App\Models\PlayerAbsence;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\TeamAbsenceImpactCalculator;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for TeamAbsenceImpactCalculator.
 *
 * Tests:
 *  [A] no absences → emptyResult (absences_count=0, coverage=null)
 *  [B] null kickoff → emptyResult
 *  [C] absences + no match history → noStatsResult (coverage=0.0)
 *  [D] absences + match history but zero stat rows → noStatsResult
 *  [E] absent_minutes_last_30_days sums only in-window non-null minutes
 *  [F] team_minutes_last_30_days sums ALL team members in window
 *  [G] absent_minutes_share_percentage computed correctly
 *  [H] match outside 30-day window excluded from minute sums
 *  [I] null minutes treated as missing (not counted as 0)
 *  [J] absent_appearances_last_5 counts stat rows in last-5 matches
 *  [K] absent_starts_last_5 vs substitute appearances distinguished
 *  [L] heavily_used_absences_count (≥4 starts in last 5)
 *  [M] absent_players_with_stats_count and coverage percentage
 *  [N] target match stats excluded (anti-leakage)
 *  [O] future match excluded (anti-leakage)
 *  [P] non-definitive match excluded
 *  [Q] other team stats excluded from team_minutes
 *  [R] transferred player: only requested-team stats count
 *  [S] multi-competition history included
 *  [T] no N+1: exactly 4 DB queries
 */
class TeamAbsenceImpactCalculatorTest extends TestCase
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
        $this->teamA  = Team::create(['name' => 'Inter',  'type' => 'club', 'is_active' => true]);
        $this->teamB  = Team::create(['name' => 'Milan',  'type' => 'club', 'is_active' => true]);
        $this->target = Carbon::parse(self::TARGET);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [A] No absences → emptyResult (all nulls, absences_count=0)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_absences_returns_empty_result(): void
    {
        $target = $this->makeTarget();
        $player = $this->makePlayer();

        // A previous match with stats exists, but no PlayerAbsence rows
        $prev = $this->makePrev($this->target->copy()->subDays(7));
        $this->addStat($prev, $player, $this->teamA, 90);

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(0, $result['absences_count']);
        $this->assertNull($result['absent_minutes_last_30_days']);
        $this->assertNull($result['team_minutes_last_30_days']);
        $this->assertNull($result['absent_minutes_share_percentage']);
        $this->assertSame(0, $result['absent_appearances_last_5']);
        $this->assertSame(0, $result['absent_starts_last_5']);
        $this->assertSame(0, $result['heavily_used_absences_count']);
        $this->assertSame(0, $result['absent_players_with_stats_count']);
        $this->assertNull($result['absence_stats_coverage_percentage']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] Null kickoff → emptyResult
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

        $result = TeamAbsenceImpactCalculator::calculateForMatch($match, $this->teamA->id);

        $this->assertSame(0, $result['absences_count']);
        $this->assertNull($result['absent_minutes_last_30_days']);
        $this->assertNull($result['absence_stats_coverage_percentage']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] Absences exist but no match history → noStatsResult (coverage=0.0)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absences_with_no_match_history_returns_no_stats_result(): void
    {
        $target = $this->makeTarget();
        $player = $this->makePlayer();
        $this->addAbsence($target, $player, $this->teamA);

        // No previous FootballMatch records at all

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(1, $result['absences_count']);
        $this->assertNull($result['absent_minutes_last_30_days']);
        $this->assertNull($result['team_minutes_last_30_days']);
        $this->assertNull($result['absent_minutes_share_percentage']);
        $this->assertSame(0, $result['absent_appearances_last_5']);
        $this->assertSame(0, $result['absent_starts_last_5']);
        $this->assertSame(0, $result['heavily_used_absences_count']);
        $this->assertSame(0, $result['absent_players_with_stats_count']);
        $this->assertEqualsWithDelta(0.0, $result['absence_stats_coverage_percentage'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] Absences + match history but no stat rows → noStatsResult
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absences_with_matches_but_no_stat_rows(): void
    {
        $target = $this->makeTarget();
        $player = $this->makePlayer();
        $this->addAbsence($target, $player, $this->teamA);

        // A previous match exists but no MatchPlayerStatistic rows for it
        $this->makePrev($this->target->copy()->subDays(7));

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(1, $result['absences_count']);
        $this->assertNull($result['absent_minutes_last_30_days']);
        $this->assertSame(0, $result['absent_players_with_stats_count']);
        $this->assertEqualsWithDelta(0.0, $result['absence_stats_coverage_percentage'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] absent_minutes_last_30_days: only in-window non-null minutes
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absent_minutes_last_30_days_sums_in_window(): void
    {
        $target  = $this->makeTarget();
        $absent  = $this->makePlayer();
        $this->addAbsence($target, $absent, $this->teamA);

        // M1: 10 days before target — inside window
        $m1 = $this->makePrev($this->target->copy()->subDays(10));
        $this->addStat($m1, $absent, $this->teamA, 90);

        // M2: 40 days before target — outside window
        $m2 = $this->makePrev($this->target->copy()->subDays(40));
        $this->addStat($m2, $absent, $this->teamA, 90);

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(90, $result['absent_minutes_last_30_days']); // only M1
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] team_minutes_last_30_days: ALL team members in window (absent + present)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_team_minutes_includes_all_team_members(): void
    {
        $target  = $this->makeTarget();
        $absent  = $this->makePlayer();
        $present = $this->makePlayer();
        $this->addAbsence($target, $absent, $this->teamA);

        $m1 = $this->makePrev($this->target->copy()->subDays(5));
        $this->addStat($m1, $absent,  $this->teamA, 90);
        $this->addStat($m1, $present, $this->teamA, 80);

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(170, $result['team_minutes_last_30_days']); // 90 + 80
        $this->assertSame(90,  $result['absent_minutes_last_30_days']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] absent_minutes_share_percentage: correct ratio
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absent_minutes_share_percentage_correct(): void
    {
        $target  = $this->makeTarget();
        $absent  = $this->makePlayer();
        $this->addAbsence($target, $absent, $this->teamA);

        $m1 = $this->makePrev($this->target->copy()->subDays(5));
        // Absent: 90 min; 3 other players: 90 min each → team total = 360
        $this->addStat($m1, $absent,              $this->teamA, 90);
        $this->addStat($m1, $this->makePlayer(),  $this->teamA, 90);
        $this->addStat($m1, $this->makePlayer(),  $this->teamA, 90);
        $this->addStat($m1, $this->makePlayer(),  $this->teamA, 90);

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(360, $result['team_minutes_last_30_days']);
        $this->assertSame(90,  $result['absent_minutes_last_30_days']);
        $this->assertEqualsWithDelta(25.0, $result['absent_minutes_share_percentage'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] Match outside 30-day window excluded from minute sums
    // ─────────────────────────────────────────────────────────────────────────

    public function test_match_outside_30_day_window_excluded_from_minute_sums(): void
    {
        $target = $this->makeTarget();
        $absent = $this->makePlayer();
        $pres   = $this->makePlayer();
        $this->addAbsence($target, $absent, $this->teamA);

        // M1: 29 days before → inside window
        $m1 = $this->makePrev($this->target->copy()->subDays(29));
        $this->addStat($m1, $absent, $this->teamA, 60);
        $this->addStat($m1, $pres,   $this->teamA, 60);

        // M2: 31 days before → outside window
        $m2 = $this->makePrev($this->target->copy()->subDays(31));
        $this->addStat($m2, $absent, $this->teamA, 90);
        $this->addStat($m2, $pres,   $this->teamA, 90);

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        // Only M1 counts for the 30-day window
        $this->assertSame(60, $result['absent_minutes_last_30_days']);
        $this->assertSame(120, $result['team_minutes_last_30_days']); // 60 + 60
        $this->assertEqualsWithDelta(50.0, $result['absent_minutes_share_percentage'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] Null minutes treated as missing, never coerced to 0
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_minutes_ignored_in_sums(): void
    {
        $target = $this->makeTarget();
        $absent = $this->makePlayer();
        $pres   = $this->makePlayer();
        $this->addAbsence($target, $absent, $this->teamA);

        // Both absent and present player have null minutes in the window
        $m1 = $this->makePrev($this->target->copy()->subDays(5));
        $this->addStat($m1, $absent, $this->teamA, null); // null → skip
        $this->addStat($m1, $pres,   $this->teamA, null); // null → skip

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        // All minutes null → no reliable data
        $this->assertNull($result['absent_minutes_last_30_days']);
        $this->assertNull($result['team_minutes_last_30_days']);
        $this->assertNull($result['absent_minutes_share_percentage']);

        // Coverage: absent player still appeared in stats rows → counts
        $this->assertSame(1, $result['absent_players_with_stats_count']);
        $this->assertEqualsWithDelta(100.0, $result['absence_stats_coverage_percentage'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J] absent_appearances_last_5: counts stat rows in last-5 team matches
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absent_appearances_last_5_correct(): void
    {
        $target    = $this->makeTarget();
        $absent    = $this->makePlayer();
        $filler    = $this->makePlayer(); // ensures all 5 matches are "team matches"
        $this->addAbsence($target, $absent, $this->teamA);

        // 5 matches; absent player appears in M2, M3, M4 only
        for ($i = 1; $i <= 5; $i++) {
            $m = $this->makePrev($this->target->copy()->subDays($i * 7));
            $this->addStat($m, $filler, $this->teamA, 90); // anchor match as "team match"
            if ($i >= 2 && $i <= 4) {
                $this->addStat($m, $absent, $this->teamA, 90);
            }
        }

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(3, $result['absent_appearances_last_5']); // M2, M3, M4
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [K] absent_starts_last_5 vs substitute appearances
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absent_starts_vs_substitutes_distinguished(): void
    {
        $target = $this->makeTarget();
        $absent = $this->makePlayer();
        $this->addAbsence($target, $absent, $this->teamA);

        // M1: starter
        $m1 = $this->makePrev($this->target->copy()->subDays(7));
        $this->addStat($m1, $absent, $this->teamA, 90, isSub: false);

        // M2: substitute
        $m2 = $this->makePrev($this->target->copy()->subDays(14));
        $this->addStat($m2, $absent, $this->teamA, 45, isSub: true);

        // M3: starter
        $m3 = $this->makePrev($this->target->copy()->subDays(21));
        $this->addStat($m3, $absent, $this->teamA, 90, isSub: false);

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(3, $result['absent_appearances_last_5']); // all 3 appearances
        $this->assertSame(2, $result['absent_starts_last_5']);       // M1 + M3
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [L] heavily_used_absences_count: ≥4 starts in last 5 per player
    // ─────────────────────────────────────────────────────────────────────────

    public function test_heavily_used_absences_count_correctly(): void
    {
        $target  = $this->makeTarget();
        $pA      = $this->makePlayer(); // starts all 5 → heavily used
        $pB      = $this->makePlayer(); // starts 4 of 5 → heavily used
        $pC      = $this->makePlayer(); // starts 3 of 5 → NOT heavily used

        $this->addAbsence($target, $pA, $this->teamA);
        $this->addAbsence($target, $pB, $this->teamA);
        $this->addAbsence($target, $pC, $this->teamA);

        // 5 matches, oldest = subDays(35), newest = subDays(7)
        $offsets = [35, 28, 21, 14, 7]; // M1 (oldest) → M5 (newest)
        foreach ($offsets as $idx => $d) {
            $m = $this->makePrev($this->target->copy()->subDays($d));
            $this->addStat($m, $pA, $this->teamA, 90, isSub: false); // always
            if ($idx < 4) { // M1–M4 (indices 0–3)
                $this->addStat($m, $pB, $this->teamA, 90, isSub: false);
            }
            if ($idx < 3) { // M1–M3 (indices 0–2)
                $this->addStat($m, $pC, $this->teamA, 90, isSub: false);
            }
        }

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        // pA=5 starts, pB=4 starts → both ≥4 → heavily_used=2
        // pC=3 starts → NOT heavily used
        $this->assertSame(2, $result['heavily_used_absences_count']);
        // pA:5 + pB:4 + pC:3 = 12 total absent starts in last 5
        $this->assertSame(12, $result['absent_starts_last_5']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [M] absent_players_with_stats_count and coverage percentage
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absent_players_with_stats_count_and_coverage(): void
    {
        $target = $this->makeTarget();
        $pA     = $this->makePlayer(); // has stats in prev matches
        $pB     = $this->makePlayer(); // has stats in prev matches
        $pC     = $this->makePlayer(); // NO stats at all

        $this->addAbsence($target, $pA, $this->teamA);
        $this->addAbsence($target, $pB, $this->teamA);
        $this->addAbsence($target, $pC, $this->teamA);

        $m1 = $this->makePrev($this->target->copy()->subDays(7));
        $this->addStat($m1, $pA, $this->teamA, 90);
        $this->addStat($m1, $pB, $this->teamA, 90);
        // pC has no stat rows

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(3, $result['absences_count']);
        $this->assertSame(2, $result['absent_players_with_stats_count']);
        $this->assertEqualsWithDelta(66.667, $result['absence_stats_coverage_percentage'], 0.01);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [N] Target match stats excluded (anti-leakage)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_target_match_stats_excluded(): void
    {
        $target = $this->makeTarget('finished'); // even a finished target must be excluded
        $absent = $this->makePlayer();
        $this->addAbsence($target, $absent, $this->teamA);

        // Stat row ON the target match — must NOT be counted
        $this->addStat($target, $absent, $this->teamA, 90);

        // One legitimate previous match
        $m1 = $this->makePrev($this->target->copy()->subDays(7));
        $this->addStat($m1, $absent, $this->teamA, 60);

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        // Only M1's 60 min should count
        $this->assertSame(60, $result['absent_minutes_last_30_days']);
        $this->assertSame(60, $result['team_minutes_last_30_days']);
        $this->assertSame(1,  $result['absent_appearances_last_5']); // only M1
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [O] Future match excluded (anti-leakage)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_future_match_excluded(): void
    {
        $target = $this->makeTarget();
        $absent = $this->makePlayer();
        $this->addAbsence($target, $absent, $this->teamA);

        // Match AFTER target kickoff → must not appear in Q3
        $future = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $this->target->copy()->addDays(7),
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 1,
        ]);
        $this->addStat($future, $absent, $this->teamA, 90);

        // One legitimate previous match
        $m1 = $this->makePrev($this->target->copy()->subDays(7));
        $this->addStat($m1, $absent, $this->teamA, 45);

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(45, $result['absent_minutes_last_30_days']); // only M1
        $this->assertSame(1,  $result['absent_appearances_last_5']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [P] Non-definitive match excluded (e.g. live, suspended)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_non_definitive_match_excluded(): void
    {
        $target = $this->makeTarget();
        $absent = $this->makePlayer();
        $this->addAbsence($target, $absent, $this->teamA);

        // A 'live' match before target — must not be included
        $live = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $this->target->copy()->subDays(3),
            'status'         => 'live',
        ]);
        $this->addStat($live, $absent, $this->teamA, 90);

        // Legitimate finished match
        $m1 = $this->makePrev($this->target->copy()->subDays(10));
        $this->addStat($m1, $absent, $this->teamA, 60);

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(60, $result['absent_minutes_last_30_days']); // only M1
        $this->assertSame(1,  $result['absent_appearances_last_5']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [Q] Other team stats excluded from team_minutes and absent_appearances
    // ─────────────────────────────────────────────────────────────────────────

    public function test_other_team_stats_excluded(): void
    {
        $target  = $this->makeTarget();
        $absent  = $this->makePlayer();
        $opPl    = $this->makePlayer(); // opponent player, NOT absent
        $this->addAbsence($target, $absent, $this->teamA);

        $m1 = $this->makePrev($this->target->copy()->subDays(7));
        $this->addStat($m1, $absent, $this->teamA, 90);   // teamA row — counted
        $this->addStat($m1, $opPl,   $this->teamB, 90);   // teamB row — NOT counted

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        // Team minutes must not include opponent's 90 min
        $this->assertSame(90, $result['team_minutes_last_30_days']);
        $this->assertSame(90, $result['absent_minutes_last_30_days']);
        $this->assertEqualsWithDelta(100.0, $result['absent_minutes_share_percentage'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [R] Transferred player: only requested-team stat rows count
    // ─────────────────────────────────────────────────────────────────────────

    public function test_transferred_player_only_counts_for_requested_team(): void
    {
        $target = $this->makeTarget();
        $player = $this->makePlayer();  // absent for teamA
        $this->addAbsence($target, $player, $this->teamA);

        // M1: played for teamA → counted
        $m1 = $this->makePrev($this->target->copy()->subDays(7));
        $this->addStat($m1, $player, $this->teamA, 90, isSub: false);

        // M2: played for teamB in same match format (e.g. before transfer)
        $m2 = $this->makePrev($this->target->copy()->subDays(21));
        $this->addStat($m2, $player, $this->teamB, 90, isSub: false); // different team → excluded by Q4

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        // Only M1 (teamA) should contribute
        $this->assertSame(90, $result['absent_minutes_last_30_days']);
        $this->assertSame(1,  $result['absent_appearances_last_5']); // only M1
        $this->assertSame(1,  $result['absent_starts_last_5']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [S] Multi-competition history included
    // ─────────────────────────────────────────────────────────────────────────

    public function test_multi_competition_history_included(): void
    {
        $target = $this->makeTarget();
        $absent = $this->makePlayer();
        $this->addAbsence($target, $absent, $this->teamA);

        // Create a second competition (Champions League)
        $country2 = Country::create(['name' => 'Europe', 'football_code' => 'EU']);
        $comp2    = Competition::create([
            'country_id' => $country2->id, 'name' => 'Champions League',
            'slug' => 'champions-league', 'format' => 'cup', 'is_active' => true,
        ]);
        $season2  = Season::create([
            'competition_id' => $comp2->id, 'name' => '2026/27',
            'year_start' => 2026, 'year_end' => 2027, 'is_current' => true,
        ]);

        // M1: Serie A match (7 days before)
        $m1 = $this->makePrev($this->target->copy()->subDays(7));
        $this->addStat($m1, $absent, $this->teamA, 90);

        // M2: Champions League match (14 days before)
        $m2 = FootballMatch::create([
            'competition_id' => $comp2->id,
            'season_id'      => $season2->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $this->target->copy()->subDays(14),
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);
        $this->addStat($m2, $absent, $this->teamA, 90);

        $result = TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        // Both competitions contribute
        $this->assertSame(180, $result['absent_minutes_last_30_days']); // M1 + M2
        $this->assertSame(2,   $result['absent_appearances_last_5']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [T] No N+1: exactly 4 DB queries regardless of player/absence count
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_n_plus_1_exactly_4_queries(): void
    {
        $target  = $this->makeTarget();
        $players = $this->createPlayers(5);

        foreach ($players as $p) {
            $this->addAbsence($target, $p, $this->teamA);
        }

        for ($i = 1; $i <= 5; $i++) {
            $m = $this->makePrev($this->target->copy()->subDays($i * 7));
            foreach ($players as $p) {
                $this->addStat($m, $p, $this->teamA, 90);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        TeamAbsenceImpactCalculator::calculateForMatch($target, $this->teamA->id);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Q1: DataSource slug lookup
        // Q2: PlayerAbsence
        // Q3: FootballMatch (definitive, before target)
        // Q4: MatchPlayerStatistic
        $this->assertCount(4, $queries, 'Expected exactly 4 DB queries (no N+1)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

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

    private function makePrev(Carbon $kickoff, string $status = 'finished'): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $kickoff,
            'status'         => $status,
            'home_score_ft'  => $status === 'finished' ? 1 : null,
            'away_score_ft'  => $status === 'finished' ? 0 : null,
        ]);
    }

    private function makePlayer(): Player
    {
        static $i = 0;
        return Player::create(['name' => 'Player_' . (++$i)]);
    }

    private function createPlayers(int $n): array
    {
        $players = [];
        for ($i = 0; $i < $n; $i++) {
            $players[] = $this->makePlayer();
        }
        return $players;
    }

    private function addAbsence(FootballMatch $match, Player $player, Team $team): void
    {
        PlayerAbsence::create([
            'match_id'       => $match->id,
            'player_id'      => $player->id,
            'team_id'        => $team->id,
            'data_source_id' => $this->ds->id,
            'absence_type'   => 'Missing Fixture',
            'reason'         => 'Injury',
        ]);
    }

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
}
