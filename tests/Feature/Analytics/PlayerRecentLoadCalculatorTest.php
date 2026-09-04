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
use App\Services\Analytics\PlayerRecentLoadCalculator;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for PlayerRecentLoadCalculator.
 *
 * Rules under test:
 *  [A] minutes_last_7/14/30 correct for simple window scenarios
 *  [B] "last 5 team matches" calendar is team-based, not player-based
 *  [C] appearances_last_5_matches counts correctly
 *  [D] starts_last_5_matches counts games_substitute=false correctly
 *  [E] player not in last 5 matches → appearances=0, starts=0
 *  [F] games_minutes NULL → not treated as 0; returns null when all null
 *  [G] target match excluded (strict < cutoff)
 *  [H] future match (kickoff_at > target) excluded
 *  [I] non-definitive match excluded (e.g. 'scheduled')
 *  [J] player belonging to a different team excluded
 *  [K] same player transferred: only stats for the requested team count
 *  [L] awarded and walkover statuses included
 *  [M] no query N+1: DB query count is constant regardless of player count
 *  [N] null kickoff_at → returns empty array immediately
 */
class PlayerRecentLoadCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Team $teamA;
    private Team $teamB;
    private Competition $comp;
    private Season $season;
    private Carbon $target;

    /** Fixed target kickoff used across all tests. */
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
    // [A] Time-window minute sums
    // ─────────────────────────────────────────────────────────────────────────

    public function test_minutes_last_7_days_sums_correctly(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player = Player::create(['name' => 'Lautaro']);

        // 5 days ago → inside 7-day window
        $m5 = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(5)));
        $this->addStat($m5, $player, $this->teamA, minutes: 85);

        // 10 days ago → outside 7-day window, inside 14-day window
        $m10 = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(10)));
        $this->addStat($m10, $player, $this->teamA, minutes: 90);

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertArrayHasKey($player->id, $load);
        $p = $load[$player->id];
        $this->assertSame(85, $p['minutes_last_7_days']);
        $this->assertSame(175, $p['minutes_last_14_days']);  // 85 + 90
    }

    public function test_minutes_last_30_days_sums_all_in_window(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player = Player::create(['name' => 'Thuram']);

        // inside 7 days
        $m3 = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(3)));
        $this->addStat($m3, $player, $this->teamA, minutes: 60);

        // inside 14 days, outside 7
        $m11 = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(11)));
        $this->addStat($m11, $player, $this->teamA, minutes: 45);

        // inside 30 days, outside 14
        $m25 = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(25)));
        $this->addStat($m25, $player, $this->teamA, minutes: 90);

        // outside 30 days — should NOT appear
        $m40 = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(40)));
        $this->addStat($m40, $player, $this->teamA, minutes: 90);

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);
        $p    = $load[$player->id];

        $this->assertSame(60,  $p['minutes_last_7_days']);
        $this->assertSame(105, $p['minutes_last_14_days']); // 60 + 45
        $this->assertSame(195, $p['minutes_last_30_days']); // 60 + 45 + 90
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] "Last 5 team matches" is team-calendar-based
    // ─────────────────────────────────────────────────────────────────────────

    public function test_last_5_matches_uses_team_calendar_not_player_history(): void
    {
        $target  = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player1 = Player::create(['name' => 'Player1']); // played in all 6
        $player2 = Player::create(['name' => 'Player2']); // played only in oldest 2

        // Create 6 matches, player1 in all, player2 only in match 6 (oldest, outside last 5)
        $matches = [];
        for ($i = 1; $i <= 6; $i++) {
            $m = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays($i * 5)));
            $matches[] = $m;
            $this->addStat($m, $player1, $this->teamA, minutes: 90);
            if ($i === 6) {
                // Only the 6th (oldest) match: outside the "last 5" window
                $this->addStat($m, $player2, $this->teamA, minutes: 60);
            }
        }

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);

        // player1: appeared in all 6, but last-5 window covers only the 5 most recent
        $this->assertSame(5, $load[$player1->id]['appearances_last_5_matches']);

        // player2: appeared only in match 6 (the oldest, 6th position),
        // which is OUTSIDE the "last 5" team-calendar window → appearances = 0
        $this->assertSame(0, $load[$player2->id]['appearances_last_5_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] appearances_last_5_matches
    // ─────────────────────────────────────────────────────────────────────────

    public function test_appearances_last_5_counted_correctly(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player = Player::create(['name' => 'Barella']);

        // 6 matches; player appears in only 3 of the 5 most recent
        for ($i = 1; $i <= 6; $i++) {
            $m = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays($i * 4)));
            if ($i <= 3) {
                // 3 most recent → inside last 5
                $this->addStat($m, $player, $this->teamA, minutes: 90);
            } elseif ($i === 6) {
                // oldest, outside last 5
                $this->addStat($m, $player, $this->teamA, minutes: 90);
            }
            // matches 4, 5: no stat for player (absent from squad)
            // We still need them as "team matches", so add a dummy player
            if (in_array($i, [4, 5], true)) {
                $dummy = Player::create(['name' => "Dummy{$i}"]);
                $this->addStat($m, $dummy, $this->teamA, minutes: 90);
            }
        }

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(3, $load[$player->id]['appearances_last_5_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] starts_last_5_matches
    // ─────────────────────────────────────────────────────────────────────────

    public function test_starts_last_5_counts_only_non_substitute_rows(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player = Player::create(['name' => 'Calhanoglu']);

        // 5 matches: 3 as starter, 2 as sub
        for ($i = 1; $i <= 5; $i++) {
            $m     = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays($i * 3)));
            $isSub = $i > 3; // matches 4 and 5 (older) are subs
            $this->addStat($m, $player, $this->teamA, minutes: 90, isSub: $isSub);
        }

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);
        $p    = $load[$player->id];

        $this->assertSame(5, $p['appearances_last_5_matches']);
        $this->assertSame(3, $p['starts_last_5_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] player with no appearances in last 5 → counts = 0
    // ─────────────────────────────────────────────────────────────────────────

    public function test_player_absent_from_last_5_has_zero_appearances_and_starts(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player = Player::create(['name' => 'OldPlayer']);

        // Player appeared only 40 days ago — outside any last-5 window
        // but we need other stats to ensure 5 team matches exist
        $old = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(40)));
        $this->addStat($old, $player, $this->teamA, minutes: 90);

        // Create 5 more recent team matches (player absent), with a different player
        $regular = Player::create(['name' => 'Regular']);
        for ($i = 1; $i <= 5; $i++) {
            $m = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays($i * 4)));
            $this->addStat($m, $regular, $this->teamA, minutes: 90);
        }

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertArrayHasKey($player->id, $load);
        $this->assertSame(0, $load[$player->id]['appearances_last_5_matches']);
        $this->assertSame(0, $load[$player->id]['starts_last_5_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] NULL minutes → not treated as real data
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_minutes_not_counted_in_sum(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player = Player::create(['name' => 'NullMinutes']);

        // match with null minutes (data not available from source)
        $m1 = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(3)));
        $this->addStat($m1, $player, $this->teamA, minutes: null);

        // match with real minutes
        $m2 = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(5)));
        $this->addStat($m2, $player, $this->teamA, minutes: 72);

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);
        $p    = $load[$player->id];

        // Only 72 summed, null skipped
        $this->assertSame(72, $p['minutes_last_7_days']);
    }

    public function test_all_null_minutes_gives_null_not_zero(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player = Player::create(['name' => 'AllNullPlayer']);

        // Both appearances have null minutes
        $m1 = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(2)));
        $this->addStat($m1, $player, $this->teamA, minutes: null);
        $m2 = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(6)));
        $this->addStat($m2, $player, $this->teamA, minutes: null);

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);
        $p    = $load[$player->id];

        // All null → minutes should be null, not 0
        $this->assertNull($p['minutes_last_7_days']);
        $this->assertNull($p['minutes_last_14_days']);
        $this->assertNull($p['minutes_last_5_matches']);
        // appearances is still countable
        $this->assertSame(2, $p['appearances_last_5_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] Target match excluded (strict < cutoff)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_target_match_itself_excluded(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'finished'));
        $player = Player::create(['name' => 'Dzeko']);

        // Stat on the target match itself — must be ignored
        $this->addStat($target, $player, $this->teamA, minutes: 90);

        // Stat on a prior match — must be included
        $prev = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(7)));
        $this->addStat($prev, $player, $this->teamA, minutes: 80);

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertArrayHasKey($player->id, $load);
        // Only 80 minutes from the prior match
        $this->assertSame(80, $load[$player->id]['minutes_last_7_days']);
        $this->assertSame(1, $load[$player->id]['appearances_last_5_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] Future match excluded (kickoff_at > target)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_future_match_excluded_no_leakage(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player = Player::create(['name' => 'FuturePlayer']);

        // match after target (data leakage candidate)
        $future = FootballMatch::create($this->matchAttrs($this->target->copy()->addDays(5)));
        $this->addStat($future, $player, $this->teamA, minutes: 90);

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);

        // No previous valid matches → player not in result
        $this->assertArrayNotHasKey($player->id, $load);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] Non-definitive match excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_non_definitive_match_excluded(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player = Player::create(['name' => 'LivePlayer']);

        // Live match before target — should be excluded (not definitive)
        $live = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $this->target->copy()->subDays(3),
            'status'         => 'live',
        ]);
        $this->addStat($live, $player, $this->teamA, minutes: 60);

        // Scheduled match — also excluded
        $sched = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $this->target->copy()->subDays(5),
            'status'         => 'scheduled',
        ]);
        $this->addStat($sched, $player, $this->teamA, minutes: 90);

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);

        // Both non-definitive → player has no valid previous stats
        $this->assertArrayNotHasKey($player->id, $load);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J] Player from a different team excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_player_from_other_team_excluded(): void
    {
        $target      = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $playerTeamA = Player::create(['name' => 'PlayerA']);
        $playerTeamB = Player::create(['name' => 'PlayerB']);

        $prev = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(5)));
        $this->addStat($prev, $playerTeamA, $this->teamA, minutes: 90);
        $this->addStat($prev, $playerTeamB, $this->teamB, minutes: 80);

        // When querying for teamA
        $loadA = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertArrayHasKey($playerTeamA->id, $loadA);
        $this->assertArrayNotHasKey($playerTeamB->id, $loadA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [K] Same player transferred: only stats for the requested team count
    // ─────────────────────────────────────────────────────────────────────────

    public function test_transferred_player_only_counts_for_requested_team(): void
    {
        $target  = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player  = Player::create(['name' => 'Transferred']);

        // Match where player was playing for teamB (before transfer)
        $forB = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(10)));
        $this->addStat($forB, $player, $this->teamB, minutes: 90); // teamB stats

        // Match where player is playing for teamA (after transfer)
        $forA = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(3)));
        $this->addStat($forA, $player, $this->teamA, minutes: 70); // teamA stats

        // Ask for teamA's load — should only see 70 minutes, not 90+70
        $loadA = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertArrayHasKey($player->id, $loadA);
        $this->assertSame(70, $loadA[$player->id]['minutes_last_30_days']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [L] awarded and walkover statuses included
    // ─────────────────────────────────────────────────────────────────────────

    public function test_awarded_and_walkover_statuses_included(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player = Player::create(['name' => 'AwardedPlayer']);

        // awarded match
        $awarded = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $this->target->copy()->subDays(4),
            'status'         => 'awarded',
        ]);
        $this->addStat($awarded, $player, $this->teamA, minutes: 30);

        // walkover match
        $walkover = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamB->id,
            'away_team_id'   => $this->teamA->id,
            'kickoff_at'     => $this->target->copy()->subDays(10),
            'status'         => 'walkover',
        ]);
        $this->addStat($walkover, $player, $this->teamA, minutes: 0);

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);
        $p    = $load[$player->id];

        $this->assertSame(30, $p['minutes_last_7_days']);   // only awarded within 7 days
        $this->assertSame(30, $p['minutes_last_30_days']);  // 30 + 0 = 30
        $this->assertSame(2,  $p['appearances_last_5_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [M] No N+1: DB query count is constant regardless of player count
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_n_plus_1_queries(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));

        // 10 players in 3 matches
        $players = collect(range(1, 10))->map(fn($i) => Player::create(['name' => "Player{$i}"]));
        for ($i = 1; $i <= 3; $i++) {
            $m = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays($i * 5)));
            foreach ($players as $p) {
                $this->addStat($m, $p, $this->teamA, minutes: 90);
            }
        }

        // Warm up any lazy-loaded statics, then count
        DB::flushQueryLog();
        DB::enableQueryLog();

        PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Should be exactly 2 queries regardless of player count:
        // Q1: FootballMatch for eligible match IDs
        // Q2: MatchPlayerStatistic for all stats
        $this->assertCount(2, $queries, 'Expected exactly 2 DB queries (no N+1)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [N] null kickoff_at → returns empty array
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_kickoff_returns_empty_array(): void
    {
        $match = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => null,
            'status'         => 'tbd',
        ]);

        $load = PlayerRecentLoadCalculator::calculateForMatch($match, $this->teamA->id);

        $this->assertSame([], $load);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Strict boundary: match exactly at target kickoff excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_match_exactly_at_target_kickoff_excluded(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player = Player::create(['name' => 'BoundaryPlayer']);

        // A finished match with kickoff == target kickoff (boundary = excluded, strict <)
        $boundary = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $this->target, // same timestamp
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);
        $this->addStat($boundary, $player, $this->teamA, minutes: 90);

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertArrayNotHasKey($player->id, $load);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // No previous matches → empty result
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_previous_matches_returns_empty_array(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame([], $load);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Mixed null/int minutes in same window: sum non-null only
    // ─────────────────────────────────────────────────────────────────────────

    public function test_mixed_null_and_real_minutes_sums_non_null_only(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player = Player::create(['name' => 'Mixed']);

        $m1 = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(2)));
        $this->addStat($m1, $player, $this->teamA, minutes: null); // unknown

        $m2 = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(4)));
        $this->addStat($m2, $player, $this->teamA, minutes: 65);

        $m3 = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(6)));
        $this->addStat($m3, $player, $this->teamA, minutes: null); // unknown

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);
        $p    = $load[$player->id];

        // Only 65 should be summed; two nulls ignored
        $this->assertSame(65, $p['minutes_last_7_days']);
        $this->assertSame(3, $p['appearances_last_5_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Result keyed by player_id as int
    // ─────────────────────────────────────────────────────────────────────────

    public function test_result_keyed_by_player_id_as_int(): void
    {
        $target = FootballMatch::create($this->matchAttrs($this->target, 'scheduled'));
        $player = Player::create(['name' => 'KeyTest']);

        $m = FootballMatch::create($this->matchAttrs($this->target->copy()->subDays(3)));
        $this->addStat($m, $player, $this->teamA, minutes: 90);

        $load = PlayerRecentLoadCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertArrayHasKey($player->id, $load);
        $this->assertIsInt(array_key_first($load));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Build FootballMatch attributes. Status defaults to 'finished'. */
    private function matchAttrs(Carbon $kickoff, string $status = 'finished'): array
    {
        return [
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => $kickoff,
            'status'         => $status,
            'home_score_ft'  => $status === 'finished' ? 1 : null,
            'away_score_ft'  => $status === 'finished' ? 0 : null,
        ];
    }

    /** Create a MatchPlayerStatistic row for the given match/player/team. */
    private function addStat(
        FootballMatch $match,
        Player $player,
        Team $team,
        ?int $minutes,
        bool $isSub = false,
    ): MatchPlayerStatistic {
        return MatchPlayerStatistic::create([
            'match_id'        => $match->id,
            'player_id'       => $player->id,
            'team_id'         => $team->id,
            'data_source_id'  => $this->ds->id,
            'games_minutes'   => $minutes,
            'games_substitute' => $isSub,
        ]);
    }
}
