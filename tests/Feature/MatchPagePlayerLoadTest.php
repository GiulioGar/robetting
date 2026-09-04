<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchPlayerStatistic;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the "Utilizzo recente giocatori" (E2 player load) section on /matches/{match}.
 *
 * Rules under test:
 *  - homeTopPlayers / awayTopPlayers passed to view
 *  - sorted by minutes_last_30_days DESC (null last)
 *  - at most 8 players per team
 *  - player name rendered correctly
 *  - minute values (7/14/30 and last-5) shown in view
 *  - null minutes rendered as "—"
 *  - no player stats → "Dati giocatori non disponibili"
 *  - future match not included (anti-leakage)
 *  - target match not included (strict < cutoff)
 */
class MatchPagePlayerLoadTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private FootballMatch $match;
    private Team $homeTeam;
    private Team $awayTeam;
    private Competition $comp;
    private Season $season;

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
        $this->homeTeam = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->awayTeam = Team::create(['name' => 'Milan',  'type' => 'club', 'is_active' => true]);

        $this->match = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET),
            'status'         => 'scheduled',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. View receives both team arrays
    // ─────────────────────────────────────────────────────────────────────────

    public function test_view_receives_home_and_away_top_players(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertViewHas('homeTopPlayers');
        $response->assertViewHas('awayTopPlayers');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. No stats → fallback message shown
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_player_stats_shows_fallback_message(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('Dati giocatori non disponibili');
        $response->assertViewHas('homeTopPlayers', []);
        $response->assertViewHas('awayTopPlayers', []);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Section heading always visible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_heading_visible(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('Utilizzo recente giocatori');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Player name shown correctly
    // ─────────────────────────────────────────────────────────────────────────

    public function test_player_name_shown_correctly(): void
    {
        $player = Player::create(['name' => 'Lobotka']);
        $prev   = $this->makePrev(Carbon::parse(self::TARGET)->subDays(5));
        $this->addStat($prev, $player, $this->homeTeam, 85);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('Lobotka');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Minute values appear in the view
    // ─────────────────────────────────────────────────────────────────────────

    public function test_minute_values_shown_in_view(): void
    {
        $player = Player::create(['name' => 'Calhanoglu']);
        $prev   = $this->makePrev(Carbon::parse(self::TARGET)->subDays(3));
        $this->addStat($prev, $player, $this->homeTeam, 90);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        // 90 minutes rendered with apostrophe as "90'"
        $response->assertSee("90'");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Null minutes rendered as "—"
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_minutes_shown_as_dash(): void
    {
        $player = Player::create(['name' => 'NullPlayer']);
        $prev   = $this->makePrev(Carbon::parse(self::TARGET)->subDays(4));
        $this->addStat($prev, $player, $this->homeTeam, null);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('—');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Ordered by minutes_last_30_days DESC
    // ─────────────────────────────────────────────────────────────────────────

    public function test_top_players_ordered_by_30_day_minutes_desc(): void
    {
        $target = Carbon::parse(self::TARGET);
        $p1     = Player::create(['name' => 'LowUsage']);   // 45 min
        $p2     = Player::create(['name' => 'HighUsage']);  // 270 min
        $p3     = Player::create(['name' => 'MidUsage']);   // 135 min

        $prev1 = $this->makePrev($target->copy()->subDays(25));
        $prev2 = $this->makePrev($target->copy()->subDays(15));
        $prev3 = $this->makePrev($target->copy()->subDays(5));

        $this->addStat($prev1, $p1, $this->homeTeam, 45);
        $this->addStat($prev1, $p2, $this->homeTeam, 90);
        $this->addStat($prev2, $p2, $this->homeTeam, 90);
        $this->addStat($prev2, $p3, $this->homeTeam, 90);
        $this->addStat($prev3, $p2, $this->homeTeam, 90);
        $this->addStat($prev3, $p3, $this->homeTeam, 45);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $players = $response->viewData('homeTopPlayers');

        $this->assertSame('HighUsage', $players[0]['name']); // 270 min
        $this->assertSame('MidUsage',  $players[1]['name']); // 135 min
        $this->assertSame('LowUsage',  $players[2]['name']); // 45 min
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. At most 8 players per team
    // ─────────────────────────────────────────────────────────────────────────

    public function test_max_8_players_per_team(): void
    {
        $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays(5));

        for ($i = 1; $i <= 12; $i++) {
            $player = Player::create(['name' => "Player{$i}"]);
            $this->addStat($prev, $player, $this->homeTeam, $i * 10); // varying minutes
        }

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $this->assertCount(8, $response->viewData('homeTopPlayers'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9. Appearances and starts shown correctly
    // ─────────────────────────────────────────────────────────────────────────

    public function test_appearances_and_starts_in_view_data(): void
    {
        $target = Carbon::parse(self::TARGET);
        $player = Player::create(['name' => 'Barella']);

        // 3 matches: 2 as starter, 1 as sub
        $prev1 = $this->makePrev($target->copy()->subDays(3));
        $prev2 = $this->makePrev($target->copy()->subDays(10));
        $prev3 = $this->makePrev($target->copy()->subDays(17));

        $this->addStat($prev1, $player, $this->homeTeam, 90, isSub: false);
        $this->addStat($prev2, $player, $this->homeTeam, 90, isSub: false);
        $this->addStat($prev3, $player, $this->homeTeam, 60, isSub: true);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $players = $response->viewData('homeTopPlayers');
        $row     = collect($players)->firstWhere('name', 'Barella');

        $this->assertNotNull($row);
        $this->assertSame(3, $row['appearances_last_5_matches']);
        $this->assertSame(2, $row['starts_last_5_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10. Future match excluded (anti-leakage)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_future_match_not_included(): void
    {
        $player = Player::create(['name' => 'FuturePlayer']);

        // match after target kickoff — must be ignored
        $future = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET)->addDays(3),
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);
        $this->addStat($future, $player, $this->homeTeam, 90);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $players = $response->viewData('homeTopPlayers');
        $this->assertEmpty($players); // future match produces no valid previous stats
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11. Target match itself excluded (strict < cutoff)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_target_match_excluded_from_load(): void
    {
        $player = Player::create(['name' => 'TargetPlayer']);

        // stat on the target match itself
        $this->addStat($this->match, $player, $this->homeTeam, 90);

        // stat on a valid prior match
        $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStat($prev, $player, $this->homeTeam, 75);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $players = $response->viewData('homeTopPlayers');
        $row     = collect($players)->firstWhere('name', 'TargetPlayer');

        $this->assertNotNull($row);
        // Only 75 minutes from the prior match — the target match's 90 are excluded
        $this->assertSame(75, $row['minutes_last_30_days']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 12. Away team stats are separate from home team stats
    // ─────────────────────────────────────────────────────────────────────────

    public function test_home_and_away_players_are_separate(): void
    {
        $homePlayer = Player::create(['name' => 'HomePlayer']);
        $awayPlayer = Player::create(['name' => 'AwayPlayer']);

        $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays(5));
        $this->addStat($prev, $homePlayer, $this->homeTeam, 90);
        $this->addStat($prev, $awayPlayer, $this->awayTeam, 85);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $homeNames = collect($response->viewData('homeTopPlayers'))->pluck('name');
        $awayNames = collect($response->viewData('awayTopPlayers'))->pluck('name');

        $this->assertTrue($homeNames->contains('HomePlayer'));
        $this->assertFalse($homeNames->contains('AwayPlayer'));
        $this->assertTrue($awayNames->contains('AwayPlayer'));
        $this->assertFalse($awayNames->contains('HomePlayer'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 13. null minutes_last_30_days sorted last
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_minutes_sorted_last(): void
    {
        $target     = Carbon::parse(self::TARGET);
        $withMins   = Player::create(['name' => 'WithMinutes']);
        $nullMins   = Player::create(['name' => 'NullMinutes']);

        $prev = $this->makePrev($target->copy()->subDays(5));
        $this->addStat($prev, $withMins, $this->homeTeam, 60);
        $this->addStat($prev, $nullMins, $this->homeTeam, null);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $players = $response->viewData('homeTopPlayers');
        $this->assertSame('WithMinutes', $players[0]['name']);
        $this->assertSame('NullMinutes', $players[1]['name']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makePrev(Carbon $kickoff): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => $kickoff,
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
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
