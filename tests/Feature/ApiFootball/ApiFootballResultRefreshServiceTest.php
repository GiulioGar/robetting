<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballResultRefreshService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiFootballResultRefreshServiceTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $competition;
    private Season $season;
    private CompetitionExternalId $cei;
    private Team $homeTeam;
    private Team $awayTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);

        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);

        $this->competition = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
            'is_active'  => true,
        ]);

        $this->season = Season::create([
            'competition_id' => $this->competition->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);

        $this->cei = CompetitionExternalId::create([
            'competition_id' => $this->competition->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '135',
            'external_name'  => 'Serie A',
        ]);

        SeasonExternalId::create([
            'season_id'      => $this->season->id,
            'competition_id' => $this->competition->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '2026',
        ]);

        $this->homeTeam = Team::create(['name' => 'Home FC', 'type' => 'club', 'is_active' => true]);
        $this->awayTeam = Team::create(['name' => 'Away FC', 'type' => 'club', 'is_active' => true]);
    }

    // -------------------------------------------------------------------------
    // Zero candidates → zero API calls
    // -------------------------------------------------------------------------

    public function test_zero_candidates_zero_api_calls(): void
    {
        // All matches are definitive — no candidates
        FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subDay(),
            'status'         => 'finished',
        ]);

        Http::fake();

        $result = app(ApiFootballResultRefreshService::class)->refresh();

        Http::assertNothingSent();
        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        $this->assertSame(0, $result['updated']);
    }

    public function test_zero_candidates_still_records_sync_run(): void
    {
        Http::fake();

        $this->assertSame(0, DataSyncRun::count());
        app(ApiFootballResultRefreshService::class)->refresh();

        $this->assertSame(1, DataSyncRun::count());
        $run = DataSyncRun::first();
        $this->assertSame('result_refresh', $run->sync_type);
        $this->assertSame('ok', $run->status);
        $this->assertSame(0, $run->api_calls);
        $this->assertNull($run->competition_id);
    }

    // -------------------------------------------------------------------------
    // Candidate eligibility — refresh window
    // -------------------------------------------------------------------------

    public function test_future_within_5min_is_candidate(): void
    {
        $this->freezeSecond();

        $match = $this->makeMatchWithExtId(now()->addMinutes(4), 'scheduled', 9001);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse(9001, 'FT', 1, 0),
            200,
            ['x-ratelimit-requests-remaining' => '7490'],
        )]);

        $result = app(ApiFootballResultRefreshService::class)->refresh();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame('finished', $match->fresh()->status);
    }

    public function test_future_beyond_5min_excluded_from_refresh(): void
    {
        $this->freezeSecond();

        $this->makeMatchWithExtId(now()->addMinutes(10), 'scheduled', 9002);

        Http::fake();

        $result = app(ApiFootballResultRefreshService::class)->refresh();

        Http::assertNothingSent();
        $this->assertSame(0, $result['candidates']);
    }

    public function test_past_non_definitive_is_candidate(): void
    {
        $match = $this->makeMatchWithExtId(now()->subHour(), 'scheduled', 9003);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse(9003, 'FT', 2, 1),
            200,
        )]);

        $result = app(ApiFootballResultRefreshService::class)->refresh();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('finished', $match->fresh()->status);
        $this->assertSame(2, $match->fresh()->home_score_ft);
        $this->assertSame(1, $match->fresh()->away_score_ft);
    }

    public function test_definitive_match_excluded(): void
    {
        $this->makeMatchWithExtId(now()->subHour(), 'finished', 9004);

        Http::fake();

        $result = app(ApiFootballResultRefreshService::class)->refresh();

        Http::assertNothingSent();
        $this->assertSame(0, $result['candidates']);
    }

    // -------------------------------------------------------------------------
    // Batching > 20 fixtures → 2 API calls
    // -------------------------------------------------------------------------

    public function test_batching_over_20_fixtures(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->makeMatchWithExtId(now()->subHour(), 'scheduled', 10000 + $i);
        }

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            ['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []],
            200,
        )]);

        $result = app(ApiFootballResultRefreshService::class)->refresh();

        $this->assertSame(25, $result['candidates']);
        $this->assertSame(2, $result['api_calls']); // batch of 20 + batch of 5
        Http::assertSentCount(2);
    }

    // -------------------------------------------------------------------------
    // Catch-up
    // -------------------------------------------------------------------------

    public function test_catch_up_recovers_past_matches(): void
    {
        // Match with kickoff 3 days ago and still 'scheduled' (PC was off)
        $match = $this->makeMatchWithExtId(now()->subDays(3), 'scheduled', 9005);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse(9005, 'FT', 1, 0),
            200,
        )]);

        $result = app(ApiFootballResultRefreshService::class)->catchUp();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('catch_up', $result['sync_type']);
        $this->assertSame('finished', $match->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // DataSyncRun recorded correctly
    // -------------------------------------------------------------------------

    public function test_data_sync_run_recorded_after_refresh(): void
    {
        $match = $this->makeMatchWithExtId(now()->subHour(), 'scheduled', 9006);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse(9006, 'FT', 0, 0),
            200,
            ['x-ratelimit-requests-remaining' => '7480'],
        )]);

        app(ApiFootballResultRefreshService::class)->refresh();

        $run = DataSyncRun::where('sync_type', 'result_refresh')->firstOrFail();
        $this->assertSame($this->ds->id, $run->data_source_id);
        $this->assertNull($run->competition_id);
        $this->assertSame('ok', $run->status);
        $this->assertSame(1, $run->updated_count);
        $this->assertSame(1, $run->api_calls);
        $this->assertSame(7480, $run->daily_remaining);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
    }

    // -------------------------------------------------------------------------
    // robetting:serve — delegates to services
    // -------------------------------------------------------------------------

    public function test_serve_command_catch_up_runs_on_startup(): void
    {
        // Calendar is fresh so no fixture_sync call will be made
        DataSyncRun::create($this->freshFixtureSyncRun());

        $match = $this->makeMatchWithExtId(now()->subDays(2), 'scheduled', 9007);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse(9007, 'FT', 2, 0),
            200,
        )]);

        $this->artisan('robetting:serve --skip-server --once --season=2026')
            ->assertSuccessful();

        $this->assertSame('finished', $match->fresh()->status);
        $this->assertDatabaseHas('data_sync_runs', ['sync_type' => 'catch_up', 'status' => 'ok']);
    }

    public function test_calendar_stale_triggers_sync(): void
    {
        // Stale fixture_sync: > 36h ago
        DataSyncRun::create(array_merge($this->freshFixtureSyncRun(), [
            'started_at'  => now()->subHours(40),
            'finished_at' => now()->subHours(40),
        ]));

        // No past non-definitive matches — catch-up makes 0 API calls.
        // Calendar refresh calls GET /fixtures?league=135&season=2026.
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            ['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []],
            200,
        )]);

        $this->artisan('robetting:serve --skip-server --once --season=2026')
            ->assertSuccessful();

        // fixture_sync DataSyncRun must have been created (one new one from calendar refresh)
        $this->assertSame(2, DataSyncRun::where('sync_type', 'fixture_sync')->count());
    }

    public function test_calendar_fresh_skips_sync(): void
    {
        // Fresh fixture_sync: 10h ago
        DataSyncRun::create($this->freshFixtureSyncRun());

        Http::fake();

        $this->artisan('robetting:serve --skip-server --once --season=2026')
            ->assertSuccessful();

        Http::assertNothingSent();
        // No new fixture_sync run — only the pre-existing one
        $this->assertSame(1, DataSyncRun::where('sync_type', 'fixture_sync')->count());
    }

    // -------------------------------------------------------------------------
    // robetting:sync-api-football-results command
    // -------------------------------------------------------------------------

    public function test_result_refresh_command_works(): void
    {
        $match = $this->makeMatchWithExtId(now()->subHour(), 'scheduled', 9008);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixtureResponse(9008, 'FT', 1, 1),
            200,
        )]);

        $this->artisan('robetting:sync-api-football-results')
            ->assertSuccessful();

        $this->assertSame('finished', $match->fresh()->status);
        $this->assertDatabaseHas('data_sync_runs', ['sync_type' => 'result_refresh', 'updated_count' => 1]);
    }

    // -------------------------------------------------------------------------
    // Calendar stale policy — only fixture_sync (full / refresh) counts
    // -------------------------------------------------------------------------

    public function test_calendar_not_stale_after_full_sync(): void
    {
        // fixture FULL ran 10h ago → calendar is fresh
        DataSyncRun::create(array_merge($this->freshFixtureSyncRun(), ['mode' => 'full']));

        Http::fake();

        $this->artisan('robetting:serve --skip-server --once --season=2026')
            ->assertSuccessful();

        Http::assertNothingSent();
        // No new fixture_sync row — the existing one remains the only one
        $this->assertSame(1, DataSyncRun::where('sync_type', 'fixture_sync')->count());
    }

    public function test_calendar_not_stale_after_calendar_refresh(): void
    {
        // calendar REFRESH ran 10h ago → still fresh
        DataSyncRun::create(array_merge($this->freshFixtureSyncRun(), ['mode' => 'refresh']));

        Http::fake();

        $this->artisan('robetting:serve --skip-server --once --season=2026')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(1, DataSyncRun::where('sync_type', 'fixture_sync')->count());
    }

    public function test_calendar_stale_when_only_result_refresh_is_recent(): void
    {
        // calendar REFRESH is old (40h), a recent result_refresh should NOT reset the timer
        DataSyncRun::create(array_merge($this->freshFixtureSyncRun(), [
            'mode'        => 'refresh',
            'started_at'  => now()->subHours(40),
            'finished_at' => now()->subHours(40),
        ]));
        DataSyncRun::create([
            'data_source_id'  => $this->ds->id,
            'sync_type'       => 'result_refresh',
            'competition_id'  => null,
            'mode'            => null,
            'started_at'      => now()->subMinutes(5),
            'finished_at'     => now()->subMinutes(5)->addSeconds(1),
            'status'          => 'ok',
            'created_count'   => 0,
            'updated_count'   => 2,
            'unchanged_count' => 0,
            'skipped_count'   => 0,
            'warnings_count'  => 0,
            'api_calls'       => 1,
        ]);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            ['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []],
            200,
        )]);

        $this->artisan('robetting:serve --skip-server --once --season=2026')
            ->assertSuccessful();

        // Calendar was stale → a new fixture_sync run created
        $this->assertSame(2, DataSyncRun::where('sync_type', 'fixture_sync')->count());
    }

    public function test_calendar_stale_when_only_catch_up_is_recent(): void
    {
        // A recent catch_up exists but no fixture_sync at all → calendar is stale
        DataSyncRun::create([
            'data_source_id'  => $this->ds->id,
            'sync_type'       => 'catch_up',
            'competition_id'  => null,
            'mode'            => null,
            'started_at'      => now()->subMinutes(1),
            'finished_at'     => now()->subMinutes(1)->addSeconds(2),
            'status'          => 'ok',
            'created_count'   => 0,
            'updated_count'   => 0,
            'unchanged_count' => 0,
            'skipped_count'   => 0,
            'warnings_count'  => 0,
            'api_calls'       => 0,
        ]);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            ['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []],
            200,
        )]);

        $this->artisan('robetting:serve --skip-server --once --season=2026')
            ->assertSuccessful();

        // No fixture_sync existed → calendar stale → new fixture_sync created
        $this->assertSame(1, DataSyncRun::where('sync_type', 'fixture_sync')->count());
    }

    public function test_zero_candidate_result_refresh_recorded_but_not_calendar(): void
    {
        // Zero-candidate refresh records a DataSyncRun (heartbeat) …
        Http::fake();
        app(ApiFootballResultRefreshService::class)->refresh();

        $run = DataSyncRun::where('sync_type', 'result_refresh')->firstOrFail();
        $this->assertSame(0, $run->api_calls);
        $this->assertSame(0, $run->updated_count);

        // … but the calendar is still stale — no fixture_sync exists
        $this->assertSame(0, DataSyncRun::where('sync_type', 'fixture_sync')->count());

        // Running serve now triggers the calendar refresh
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            ['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []],
            200,
        )]);

        $this->artisan('robetting:serve --skip-server --once --season=2026')
            ->assertSuccessful();

        $this->assertSame(1, DataSyncRun::where('sync_type', 'fixture_sync')->count());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeMatchWithExtId(mixed $kickoffAt, string $status, int $extId): FootballMatch
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => $kickoffAt,
            'status'         => $status,
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => (string) $extId,
            'external_name'  => null,
        ]);

        return $match;
    }

    private function fixtureResponse(int $extId, string $short, ?int $homeScore, ?int $awayScore): array
    {
        return [
            'errors'   => [],
            'results'  => 1,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [[
                'fixture' => [
                    'id'       => $extId,
                    'timezone' => 'UTC',
                    'date'     => now()->subHour()->toIso8601String(),
                    'status'   => ['short' => $short, 'elapsed' => 90],
                    'venue'    => ['name' => 'Test Stadium'],
                ],
                'league' => ['id' => 135, 'name' => 'Serie A', 'season' => 2026, 'round' => 'Regular Season - 1'],
                'teams'  => [
                    'home' => ['id' => 505, 'name' => 'Inter', 'winner' => true],
                    'away' => ['id' => 489, 'name' => 'Milan', 'winner' => false],
                ],
                'goals' => ['home' => $homeScore, 'away' => $awayScore],
                'score' => [
                    'halftime'  => ['home' => null,       'away' => null],
                    'fulltime'  => ['home' => $homeScore, 'away' => $awayScore],
                    'extratime' => ['home' => null,       'away' => null],
                    'penalty'   => ['home' => null,       'away' => null],
                ],
            ]],
        ];
    }

    private function freshFixtureSyncRun(): array
    {
        return [
            'data_source_id'  => $this->ds->id,
            'sync_type'       => 'fixture_sync',
            'competition_id'  => $this->competition->id,
            'mode'            => 'full',
            'started_at'      => now()->subHours(10),
            'finished_at'     => now()->subHours(10)->addSeconds(30),
            'status'          => 'ok',
            'created_count'   => 0,
            'updated_count'   => 0,
            'unchanged_count' => 0,
            'skipped_count'   => 0,
            'warnings_count'  => 0,
            'api_calls'       => 1,
        ];
    }
}
