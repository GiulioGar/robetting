<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\GoalOddsSnapshot;
use App\Models\MatchExternalId;
use App\Models\OddsSnapshot;
use App\Models\Season;
use App\Models\Team;
use App\Services\DataSources\ApiFootball\ApiFootballGoalOddsSyncService;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/**
 * Odds GOAL — prospective append-only PRE-MATCH goal-market collection (all Http::fake, no real call).
 */
class ApiFootballGoalOddsSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Country $country;
    private Team $home;
    private Team $away;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        config([
            'api-football.api_key'                 => 'test-key',
            'api-football.base_url'                => 'https://v3.football.api-sports.io',
            'api-football.goal_odds.enabled'       => true,
            'api-football.core_leagues'            => [135 => 'serie-a'],
            'api-football.additional_competitions' => [2 => 'uefa-champions-league'],
        ]);

        $this->country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->home = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->away = Team::create(['name' => 'Milan', 'type' => 'club', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function competition(string $slug, int $year = 2026): array
    {
        $c = Competition::create(['country_id' => $this->country->id, 'name' => $slug, 'slug' => $slug, 'format' => 'league', 'is_active' => true]);
        $s = Season::create(['competition_id' => $c->id, 'name' => "$year", 'year_start' => $year, 'year_end' => $year + 1, 'is_current' => true]);

        return [$c, $s];
    }

    private function fixture(Competition $c, Season $s, string $ext, ?Carbon $kickoff = null, string $status = 'scheduled'): FootballMatch
    {
        $m = FootballMatch::create([
            'competition_id' => $c->id, 'season_id' => $s->id,
            'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
            'kickoff_at' => $kickoff ?? now()->addDays(2), 'status' => $status,
        ]);
        MatchExternalId::create(['match_id' => $m->id, 'data_source_id' => $this->ds->id, 'external_id' => $ext]);

        return $m;
    }

    private function bet(int $id, string $name, array $pairs): array
    {
        $values = [];
        foreach ($pairs as $label => $odd) {
            $values[] = ['value' => (string) $label, 'odd' => (string) $odd];
        }

        return ['id' => $id, 'name' => $name, 'values' => $values];
    }

    private function goalBets(string $over25 = '1.90'): array
    {
        return [
            $this->bet(1, 'Match Winner', ['Home' => '2.10', 'Draw' => '3.40', 'Away' => '3.60']),
            $this->bet(5, 'Goals Over/Under', ['Over 2.5' => $over25, 'Under 2.5' => '1.95', 'Over 2.25' => '1.70', 'Under 2.25' => '2.20']),
            $this->bet(6, 'Goals Over/Under First Half', ['Over 0.5' => '1.30', 'Under 0.5' => '3.40']),
            $this->bet(8, 'Both Teams Score', ['Yes' => '1.70', 'No' => '2.10']),
            $this->bet(16, 'Total - Home', ['Over 1.5' => '2.30', 'Under 1.5' => '1.60']),
            $this->bet(17, 'Total - Away', ['Over 0.5' => '1.35', 'Under 0.5' => '3.10']),
        ];
    }

    private function item(string $fixtureId, array $bookmakers, ?string $update = null): array
    {
        return [
            'league'  => ['id' => 135, 'season' => 2026],
            'fixture' => ['id' => (int) $fixtureId, 'timezone' => 'UTC', 'date' => '', 'timestamp' => 0],
            'update'  => $update ?? now()->subHour()->toIso8601String(),
            'bookmakers' => $bookmakers,
        ];
    }

    private function page(array $items, int $current = 1, int $total = 1): array
    {
        return ['errors' => [], 'results' => count($items), 'paging' => ['current' => $current, 'total' => $total], 'response' => $items];
    }

    private function sync(bool $force = false, ?string $slug = null): array
    {
        return app(ApiFootballGoalOddsSyncService::class)->syncPending($slug, $force);
    }

    public function test_stores_normalised_complete_pairs_with_provenance_and_ignores_other_bets(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $m = $this->fixture($c, $s, '5001');
        $update = '2026-11-03T10:00:00+00:00';
        Http::fake(['*odds*' => Http::response($this->page([$this->item('5001', [['id' => 8, 'name' => 'Bet365', 'bets' => $this->goalBets()]], $update)]), 200)]);

        $r = $this->sync();

        // total 2.5 (the 2.25 asian line is dropped), btts, home 1.5, away 0.5 => 4 observations x 2 selections
        $this->assertSame(4, $r['created']);
        $this->assertSame(8, GoalOddsSnapshot::count());
        $this->assertSame(0, OddsSnapshot::count()); // 1X2 system untouched
        $this->assertSame(0, GoalOddsSnapshot::whereIn('bet_id', [1, 6])->count());
        $this->assertSame(2, $r['discarded']);       // Over/Under 2.25

        $over = GoalOddsSnapshot::where('market', 'total_goals')->where('selection', 'over')->firstOrFail();
        $under = GoalOddsSnapshot::where('market', 'total_goals')->where('selection', 'under')->firstOrFail();
        $this->assertSame($over->observation_id, $under->observation_id);
        $this->assertSame($m->id, $over->match_id);
        $this->assertSame($this->ds->id, $over->data_source_id);
        $this->assertSame(8, $over->bookmaker_id);
        $this->assertSame('Bet365', $over->bookmaker_name);
        $this->assertSame(5, $over->bet_id);
        $this->assertSame('Over 2.5', $over->provider_label);
        $this->assertSame(2.5, $over->line);
        $this->assertEqualsWithDelta(1.90, $over->odd, 1e-9);
        $this->assertSame($update, $over->provider_updated_at->toIso8601String());
        $this->assertTrue($over->fetched_at->lt($over->kickoff_at));
        $this->assertEquals($m->kickoff_at, $over->kickoff_at);
        $this->assertSame(8, GoalOddsSnapshot::certifiedPreMatch()->count());

        $btts = GoalOddsSnapshot::where('market', 'btts')->get();
        $this->assertSame(['no', 'yes'], $btts->pluck('selection')->sort()->values()->all());
        $this->assertNull($btts->first()->line);
        $this->assertSame(1, $btts->pluck('observation_id')->unique()->count());

        $this->assertSame(1, GoalOddsSnapshot::where('market', 'team_goals_home')->where('line', 1.5)->where('selection', 'over')->count());
        $this->assertSame(1, GoalOddsSnapshot::where('market', 'team_goals_away')->where('line', 0.5)->where('selection', 'under')->count());
        $this->assertSame(4, GoalOddsSnapshot::distinct('observation_id')->count('observation_id'));
    }

    public function test_request_is_unfiltered_per_league_season_and_follows_paging_for_core_leagues_only(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $this->fixture($c, $s, '5001');
        [$cl, $sl] = $this->competition('uefa-champions-league');
        $this->fixture($cl, $sl, '6001');

        Http::fake(['*odds*' => Http::sequence()
            ->push($this->page([$this->item('5001', [['id' => 8, 'name' => 'Bet365', 'bets' => $this->goalBets()]])], 1, 2))
            ->push($this->page([], 2, 2))]);

        $r = $this->sync();

        $this->assertSame(1, $r['competitions']);
        $this->assertSame(2, $r['api_calls']);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return (int) $q['league'] === 135 && (int) $q['season'] === 2026 && ! array_key_exists('bet', $q);
        });
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'league=2&'));
    }

    public function test_identical_observation_is_a_duplicate_and_changes_append_history(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $this->fixture($c, $s, '5001');
        $update = '2026-11-03T10:00:00+00:00';
        $resp = fn (string $over, string $upd) => $this->page([$this->item('5001', [['id' => 8, 'name' => 'Bet365', 'bets' => $this->goalBets($over)]], $upd)]);

        Http::fake(['*odds*' => Http::sequence()
            ->push($resp('1.90', $update))   // first
            ->push($resp('1.90', $update))   // identical => duplicates
            ->push($resp('1.95', $update))   // odd changed => only the total 2.5 observation appended
            ->push($resp('1.95', '2026-11-03T11:00:00+00:00'))]); // provider update changed => every observation appended

        $this->assertSame(4, $this->sync(true)['created']);

        $second = $this->sync(true);
        $this->assertSame(0, $second['created']);
        $this->assertSame(4, $second['duplicates']);
        $this->assertSame(8, GoalOddsSnapshot::count());

        $third = $this->sync(true);
        $this->assertSame(1, $third['created']);
        $this->assertSame(3, $third['duplicates']);
        $this->assertSame(10, GoalOddsSnapshot::count());
        $this->assertSame(2, GoalOddsSnapshot::where('market', 'total_goals')->where('selection', 'over')->count());

        $fourth = $this->sync(true);
        $this->assertSame(4, $fourth['created']);
        $this->assertSame(18, GoalOddsSnapshot::count());
    }

    public function test_incomplete_pairs_are_not_stored(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $this->fixture($c, $s, '5001');
        $bets = [
            $this->bet(5, 'Goals Over/Under', ['Over 2.5' => '1.90']),            // Under missing
            $this->bet(8, 'Both Teams Score', ['Yes' => '1.70', 'No' => '1.00']),  // invalid odd
        ];
        Http::fake(['*odds*' => Http::response($this->page([$this->item('5001', [['id' => 8, 'name' => 'Bet365', 'bets' => $bets]])]), 200)]);

        $r = $this->sync();

        $this->assertSame(0, $r['created']);
        $this->assertSame(0, GoalOddsSnapshot::count());
        $this->assertGreaterThan(0, $r['discarded']);
    }

    public function test_only_future_scheduled_matches_and_pre_match_timestamps_are_stored(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $future = $this->fixture($c, $s, '5001', now()->addDays(2));
        $this->fixture($c, $s, '5002', now()->subHours(3), 'finished');
        $this->fixture($c, $s, '5003', now()->addDays(2));
        $bm = [['id' => 8, 'name' => 'Bet365', 'bets' => $this->goalBets()]];
        Http::fake(['*odds*' => Http::response($this->page([
            $this->item('5001', $bm),
            $this->item('5002', $bm),                                        // finished: not a candidate
            $this->item('5003', $bm, now()->addDays(3)->toIso8601String()),  // provider update AFTER kickoff
        ]), 200)]);

        $r = $this->sync();

        $this->assertSame(4, $r['created']);
        $this->assertSame(1, $r['skipped']);
        $this->assertSame([$future->id], GoalOddsSnapshot::pluck('match_id')->unique()->values()->all());
    }

    public function test_throttle_run_logging_and_api_error_handling(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $this->fixture($c, $s, '5001');
        Http::fake(['*odds*' => Http::response($this->page([$this->item('5001', [['id' => 8, 'name' => 'Bet365', 'bets' => $this->goalBets()]])]), 200)]);

        $this->sync();
        $run = DataSyncRun::where('sync_type', 'goal_odds_sync')->firstOrFail();
        $this->assertSame('ok', $run->status);
        $this->assertSame(1, $run->api_calls);
        $this->assertSame(4, $run->created_count);

        $again = $this->sync(); // within throttle
        $this->assertSame(1, $again['skipped_throttle']);
        $this->assertSame(0, $again['api_calls']);
        Http::assertSentCount(1);
    }

    public function test_api_error_is_logged_as_error_run_and_retry_is_throttled(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $this->fixture($c, $s, '5001');
        Http::fake(['*odds*' => Http::response(['errors' => ['token' => 'bad'], 'response' => [], 'paging' => ['current' => 1, 'total' => 1]], 200)]);

        $failed = $this->sync();
        $this->assertSame(1, $failed['errors']);
        $this->assertSame('error', DataSyncRun::where('sync_type', 'goal_odds_sync')->firstOrFail()->status);
    }

    public function test_snapshots_are_append_only(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $this->fixture($c, $s, '5001');
        Http::fake(['*odds*' => Http::response($this->page([$this->item('5001', [['id' => 8, 'name' => 'Bet365', 'bets' => $this->goalBets()]])]), 200)]);
        $this->sync();

        $row = GoalOddsSnapshot::firstOrFail();
        $this->expectException(LogicException::class);
        $row->update(['odd' => 9.99]);
    }
}
