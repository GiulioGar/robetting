<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\OddsSnapshot;
use App\Models\Season;
use App\Models\Team;
use App\Services\DataSources\ApiFootball\ApiFootballOddsSyncService;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Odds V1 — prospective, append-only PRE-MATCH 1X2 collection (all Http::fake, no real call).
 */
class ApiFootballOddsSyncServiceTest extends TestCase
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
            'api-football.api_key'                => 'test-key',
            'api-football.base_url'               => 'https://v3.football.api-sports.io',
            'api-football.odds.enabled'           => true,
            'api-football.core_leagues'           => [135 => 'serie-a'],
            'api-football.additional_competitions' => [2 => 'uefa-champions-league', 137 => 'coppa-italia'],
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

    private function item(string $fixtureId, array $bookmakers, ?string $update = null): array
    {
        return [
            'league'  => ['id' => 135, 'season' => 2026],
            'fixture' => ['id' => (int) $fixtureId, 'timezone' => 'UTC', 'date' => '', 'timestamp' => 0],
            'update'  => $update ?? now()->subHour()->toIso8601String(),
            'bookmakers' => $bookmakers,
        ];
    }

    private function bookmaker(int $id, string $name, float $h, float $d, float $a, int $betId = 1, string $betName = 'Match Winner'): array
    {
        return [
            'id' => $id, 'name' => $name,
            'bets' => [[
                'id' => $betId, 'name' => $betName,
                'values' => [
                    ['value' => 'Home', 'odd' => (string) $h],
                    ['value' => 'Draw', 'odd' => (string) $d],
                    ['value' => 'Away', 'odd' => (string) $a],
                ],
            ]],
        ];
    }

    private function page(array $items, int $current = 1, int $total = 1): array
    {
        return ['errors' => [], 'results' => count($items), 'paging' => ['current' => $current, 'total' => $total], 'response' => $items];
    }

    private function sync(bool $force = false, ?string $slug = null): array
    {
        return app(ApiFootballOddsSyncService::class)->syncPending($slug, $force);
    }

    public function test_parses_home_draw_away_and_stores_provenance_timestamps(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $m = $this->fixture($c, $s, '5001');
        $update = '2026-10-26T10:00:00+00:00';
        Http::fake(['*odds*' => Http::response($this->page([$this->item('5001', [$this->bookmaker(8, 'Bet365', 2.10, 3.40, 3.60)], $update)]), 200)]);

        $r = $this->sync();

        $this->assertSame(1, $r['created']);
        $row = OddsSnapshot::firstOrFail();
        $this->assertSame($m->id, $row->match_id);
        $this->assertSame($this->ds->id, $row->data_source_id);
        $this->assertSame(8, $row->bookmaker_id);
        $this->assertSame('Bet365', $row->bookmaker_name);
        $this->assertSame(1, $row->bet_id);
        $this->assertSame('Match Winner', $row->bet_name);
        $this->assertEqualsWithDelta([2.10, 3.40, 3.60], [$row->home_odd, $row->draw_odd, $row->away_odd], 1e-9);
        $this->assertSame($update, $row->provider_updated_at->toIso8601String());
        $this->assertNotNull($row->fetched_at);
        $this->assertTrue($row->fetched_at->lt($row->kickoff_at)); // certified pre-match
        $this->assertEquals($m->kickoff_at, $row->kickoff_at);
        $this->assertSame(1, OddsSnapshot::certifiedPreMatch()->count());
    }

    public function test_multiple_bookmakers_in_the_same_response(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $this->fixture($c, $s, '5001');
        Http::fake(['*odds*' => Http::response($this->page([$this->item('5001', [
            $this->bookmaker(8, 'Bet365', 2.10, 3.40, 3.60),
            $this->bookmaker(11, '1xBet', 2.15, 3.30, 3.55),
            $this->bookmaker(6, 'Bwin', 2.05, 3.45, 3.70),
        ])]), 200)]);

        $this->assertSame(3, $this->sync()['created']);
        $this->assertSame([6, 8, 11], OddsSnapshot::orderBy('bookmaker_id')->pluck('bookmaker_id')->all());
    }

    public function test_same_snapshot_received_twice_creates_no_duplicate_and_change_creates_new_row(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $this->fixture($c, $s, '5001');
        $update1 = '2026-10-26T10:00:00+00:00';
        $update2 = '2026-10-26T13:00:00+00:00';

        Http::fake(['*odds*' => Http::sequence()
            ->push($this->page([$this->item('5001', [$this->bookmaker(8, 'Bet365', 2.10, 3.40, 3.60)], $update1)]))
            ->push($this->page([$this->item('5001', [$this->bookmaker(8, 'Bet365', 2.10, 3.40, 3.60)], $update1)]))   // identical
            ->push($this->page([$this->item('5001', [$this->bookmaker(8, 'Bet365', 2.00, 3.50, 3.80)], $update2)])),  // moved
        ]);

        $this->assertSame(1, $this->sync(true)['created']);
        $second = $this->sync(true);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['duplicates']);
        $this->assertSame(1, OddsSnapshot::count());

        $this->assertSame(1, $this->sync(true)['created']);
        $this->assertSame(2, OddsSnapshot::count());
        $oldest = OddsSnapshot::orderBy('id')->first();
        $this->assertEqualsWithDelta(2.10, $oldest->home_odd, 1e-9); // previous snapshot untouched
    }

    public function test_existing_snapshots_cannot_be_updated(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $this->fixture($c, $s, '5001');
        Http::fake(['*odds*' => Http::response($this->page([$this->item('5001', [$this->bookmaker(8, 'Bet365', 2.10, 3.40, 3.60)])]), 200)]);
        $this->sync();

        $this->expectException(\LogicException::class);
        OddsSnapshot::firstOrFail()->update(['home_odd' => 9.99]);
    }

    public function test_started_or_non_scheduled_fixtures_are_not_collected(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $this->fixture($c, $s, '5001', now()->subMinutes(5));                   // already kicked off
        $this->fixture($c, $s, '5002', now()->addHours(1), 'live');             // live
        Http::fake(['*odds*' => Http::response($this->page([
            $this->item('5001', [$this->bookmaker(8, 'Bet365', 2.1, 3.4, 3.6)]),
            $this->item('5002', [$this->bookmaker(8, 'Bet365', 2.1, 3.4, 3.6)]),
        ]), 200)]);

        $r = $this->sync();

        $this->assertSame(0, $r['api_calls']);       // nothing eligible => not even queried
        $this->assertSame(0, OddsSnapshot::count());
        Http::assertNothingSent();
    }

    public function test_fetch_completed_after_kickoff_or_provider_update_after_kickoff_is_never_stored(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $kickoff = now()->addMinutes(30);
        $this->fixture($c, $s, '5001', $kickoff);
        $this->fixture($c, $s, '5002', $kickoff->copy()->addHour());

        // Response arrives AFTER 5001 kicked off (clock moves while the request is in flight)...
        Http::fake(['*odds*' => function () use ($kickoff) {
            Carbon::setTestNow($kickoff->copy()->addMinute());

            return Http::response($this->page([
                $this->item('5001', [$this->bookmaker(8, 'Bet365', 2.1, 3.4, 3.6)]),
                // ...and 5002 carries a provider update time past its own kickoff.
                $this->item('5002', [$this->bookmaker(8, 'Bet365', 2.1, 3.4, 3.6)], $kickoff->copy()->addHours(3)->toIso8601String()),
            ]), 200);
        }]);

        $r = $this->sync();

        $this->assertSame(0, OddsSnapshot::count());
        $this->assertSame(2, $r['skipped']);
    }

    public function test_scope_is_core_plus_uefa_only(): void
    {
        [$serieA, $sA] = $this->competition('serie-a');
        [$ucl, $sU]    = $this->competition('uefa-champions-league');
        [$cup, $sC]    = $this->competition('coppa-italia');
        $this->fixture($serieA, $sA, '5001');
        $this->fixture($ucl, $sU, '5002');
        $this->fixture($cup, $sC, '5003');

        Http::fake(['*odds*' => Http::response($this->page([]), 200)]);

        $r = $this->sync();

        $this->assertSame(2, $r['competitions']);
        $leagues = [];
        Http::assertSent(function (Request $req) use (&$leagues) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $leagues[] = (int) $q['league'];
            $this->assertSame('1', (string) $q['bet']);          // Match Winner only
            $this->assertSame('2026', (string) $q['season']);
            return true;
        });
        sort($leagues);
        $this->assertSame([2, 135], $leagues);                    // coppa-italia (137) never queried
    }

    public function test_throttle_avoids_unneeded_calls_and_pagination_uses_one_request_per_page(): void
    {
        [$c, $s] = $this->competition('serie-a');
        $this->fixture($c, $s, '5001');
        $this->fixture($c, $s, '5002');

        Http::fake(['*odds*' => Http::sequence()
            ->push($this->page([$this->item('5001', [$this->bookmaker(8, 'Bet365', 2.1, 3.4, 3.6)])], 1, 2))
            ->push($this->page([$this->item('5002', [$this->bookmaker(8, 'Bet365', 1.9, 3.6, 4.0)])], 2, 2))
            ->push($this->page([], 1, 1)),   // used by the post-throttle run
        ]);

        $first = $this->sync();
        $this->assertSame(2, $first['api_calls']);                // 2 pages, NOT one per bookmaker/fixture
        $this->assertSame(2, $first['created']);

        $again = $this->sync();                                   // inside the ~3h window
        $this->assertSame(0, $again['api_calls']);
        $this->assertSame(1, $again['skipped_throttle']);
        Http::assertSentCount(2);

        Carbon::setTestNow(now()->addMinutes(181));               // after the 3h throttle: due again
        $this->assertSame(1, $this->sync()['api_calls']);
    }

    public function test_api_error_is_contained_and_other_competitions_still_sync(): void
    {
        [$serieA, $sA] = $this->competition('serie-a');
        [$ucl, $sU]    = $this->competition('uefa-champions-league');
        $this->fixture($serieA, $sA, '5001');
        $this->fixture($ucl, $sU, '5002');

        Http::fake(function (Request $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return (int) $q['league'] === 135
                ? Http::response(['message' => 'boom'], 500)
                : Http::response($this->page([$this->item('5002', [$this->bookmaker(8, 'Bet365', 2.1, 3.4, 3.6)])]), 200);
        });

        $r = $this->sync();                                        // must not throw

        $this->assertSame(1, $r['errors']);
        $this->assertSame(1, $r['created']);
        $this->assertSame(1, OddsSnapshot::count());
        $this->assertSame(['error', 'ok'], DataSyncRun::where('sync_type', 'odds_sync')->pluck('status')->sort()->values()->all());
    }

    public function test_odds_failure_does_not_stop_robetting_serve(): void
    {
        DataSyncRun::create([
            'data_source_id' => $this->ds->id, 'sync_type' => 'fixture_sync', 'started_at' => now()->subMinutes(30),
            'finished_at' => now()->subMinutes(30), 'status' => 'ok',
        ]);
        $this->mock(ApiFootballOddsSyncService::class, function ($mock) {
            $mock->shouldReceive('syncPending')->andThrow(new \RuntimeException('odds boom'));
        });

        $this->artisan('robetting:serve', ['--once' => true, '--skip-server' => true])->assertSuccessful();
    }

    public function test_disabled_flag_makes_no_call(): void
    {
        config(['api-football.odds.enabled' => false]);
        [$c, $s] = $this->competition('serie-a');
        $this->fixture($c, $s, '5001');
        Http::fake();

        $this->assertFalse($this->sync()['enabled']);
        Http::assertNothingSent();
    }
}
