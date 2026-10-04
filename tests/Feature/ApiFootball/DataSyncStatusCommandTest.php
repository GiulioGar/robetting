<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballDataSyncStatusService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P24B — `robetting:data-sync-status` targeted tests.
 *
 * Http::fake() intercepts every call to API-Football, so these tests never
 * hit the real network. The command must remain strictly read-only: no test
 * here should ever change FootballMatch/MatchExternalId/DataSyncRun row
 * counts, regardless of CURRENT/STALE/ERROR outcome.
 */
class DataSyncStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $competition;
    private Season $season;
    private CompetitionExternalId $cei;
    private int $homeTeamId;
    private int $awayTeamId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);

        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();
        $country  = Country::create(['name' => 'Italy', 'football_code' => 'IT']);

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

        $homeTeam = Team::create(['name' => 'Internazionale', 'type' => 'club', 'is_active' => true]);
        TeamExternalId::create(['team_id' => $homeTeam->id, 'data_source_id' => $this->ds->id, 'external_id' => '505']);
        $this->homeTeamId = $homeTeam->id;

        $awayTeam = Team::create(['name' => 'AC Milan', 'type' => 'club', 'is_active' => true]);
        TeamExternalId::create(['team_id' => $awayTeam->id, 'data_source_id' => $this->ds->id, 'external_id' => '489']);
        $this->awayTeamId = $awayTeam->id;
    }

    private function createDbMatch(string $extId, string $kickoff, string $status, ?int $hg = null, ?int $ag = null): FootballMatch
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeamId,
            'away_team_id'   => $this->awayTeamId,
            'kickoff_at'     => $kickoff,
            'status'         => $status,
            'home_score_ft'  => $hg,
            'away_score_ft'  => $ag,
        ]);
        MatchExternalId::create(['match_id' => $match->id, 'data_source_id' => $this->ds->id, 'external_id' => $extId]);
        return $match;
    }

    private function fixtureItem(array $overrides = []): array
    {
        $item = [
            'fixture' => [
                'id'     => 9001,
                'date'   => '2026-08-22T20:45:00+00:00',
                'status' => ['short' => 'NS'],
            ],
            'league' => ['id' => 135, 'round' => 'Regular Season - 1'],
            'teams'  => ['home' => ['id' => 505], 'away' => ['id' => 489]],
            'score'  => ['fulltime' => ['home' => null, 'away' => null]],
        ];
        foreach ($overrides as $path => $value) {
            $keys = explode('.', $path);
            $node = &$item;
            foreach ($keys as $i => $key) {
                if ($i === count($keys) - 1) {
                    $node[$key] = $value;
                } else {
                    $node = &$node[$key];
                }
            }
            unset($node);
        }
        return $item;
    }

    private function fakeFixturesResponse(array $items, int $currentPage = 1, int $totalPages = 1): array
    {
        return ['errors' => [], 'results' => count($items), 'paging' => ['current' => $currentPage, 'total' => $totalPages], 'response' => $items];
    }

    // 1. tutto uguale -> CURRENT / exit 0
    public function test_everything_matches_is_current(): void
    {
        $this->createDbMatch('9001', '2026-08-22 20:45:00', 'scheduled');

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fakeFixturesResponse([$this->fixtureItem()]), 200,
            ['x-ratelimit-requests-remaining' => '7400']
        )]);

        $this->artisan('robetting:data-sync-status')
            ->expectsOutputToContain('STATUS: CURRENT')
            ->expectsOutputToContain('API -> DB: CURRENT')
            ->assertExitCode(0);
    }

    // 2. fixture API mancante nel DB -> STALE
    public function test_fixture_missing_in_db_is_stale(): void
    {
        // No DB match created at all for external_id 9001.
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fakeFixturesResponse([$this->fixtureItem()]), 200
        )]);

        $this->artisan('robetting:data-sync-status')
            ->expectsOutputToContain('STATUS: STALE')
            ->expectsOutputToContain('API -> DB: STALE')
            ->assertExitCode(1);
    }

    // 3. kickoff diverso -> STALE
    public function test_kickoff_difference_is_stale(): void
    {
        $this->createDbMatch('9001', '2026-08-22 20:45:00', 'scheduled');

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fakeFixturesResponse([$this->fixtureItem(['fixture.date' => '2026-08-29T18:00:00+00:00'])]), 200
        )]);

        $this->artisan('robetting:data-sync-status')
            ->expectsOutputToContain('STATUS: STALE')
            ->assertExitCode(1);
    }

    // 4. status diverso -> STALE
    public function test_status_difference_is_stale(): void
    {
        $this->createDbMatch('9001', '2026-08-22 20:45:00', 'scheduled');

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fakeFixturesResponse([$this->fixtureItem(['fixture.status.short' => 'PST'])]), 200
        )]);

        $this->artisan('robetting:data-sync-status')
            ->expectsOutputToContain('STATUS: STALE')
            ->assertExitCode(1);
    }

    // 5. FT score diverso -> STALE
    public function test_ft_score_difference_is_stale(): void
    {
        $this->createDbMatch('9001', '2026-08-22 20:45:00', 'finished', 1, 1);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fakeFixturesResponse([$this->fixtureItem([
                'fixture.status.short' => 'FT',
                'score.fulltime.home'  => 2,
                'score.fulltime.away'  => 1,
            ])]), 200
        )]);

        $this->artisan('robetting:data-sync-status')
            ->expectsOutputToContain('STATUS: STALE')
            ->assertExitCode(1);
    }

    // 6. fixture DB/API equivalenti dopo normalizzazione -> CURRENT
    public function test_equivalent_after_normalization_is_current(): void
    {
        // DB stores finished match with FT 2-1, API returns PEN (also "finished"
        // canonically) with the same FT score but a different ISO offset for
        // the same UTC instant.
        $this->createDbMatch('9001', '2026-08-22 20:45:00', 'finished', 2, 1);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fakeFixturesResponse([$this->fixtureItem([
                'fixture.date'         => '2026-08-22T22:45:00+02:00', // same UTC instant as 20:45Z
                'fixture.status.short' => 'PEN',                        // also maps to "finished"
                'score.fulltime.home'  => 2,
                'score.fulltime.away'  => 1,
            ])]), 200
        )]);

        $this->artisan('robetting:data-sync-status')
            ->expectsOutputToContain('STATUS: CURRENT')
            ->expectsOutputToContain('API -> DB: CURRENT')
            ->assertExitCode(0);
    }

    // 7. errore API -> ERROR / exit 2
    public function test_api_error_is_error_exit_code(): void
    {
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(['message' => 'boom'], 500)]);

        $this->artisan('robetting:data-sync-status')
            ->expectsOutputToContain('STATUS: ERROR')
            ->expectsOutputToContain('API -> DB: ERROR')
            ->assertExitCode(2);
    }

    // 8. limite 20 chiamate -> ERROR
    public function test_call_budget_limit_aborts_with_error(): void
    {
        // Force a single league to require far more than 20 pages.
        $responses = [];
        for ($p = 1; $p <= 25; $p++) {
            $responses[] = Http::response($this->fakeFixturesResponse(
                [$this->fixtureItem(['fixture.id' => 9000 + $p])], $p, 25
            ), 200);
        }
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::sequence($responses)]);

        $this->artisan('robetting:data-sync-status')
            ->expectsOutputToContain('STATUS: ERROR')
            ->expectsOutputToContain('budget')
            ->expectsOutputToContain('API -> DB: ERROR')
            ->assertExitCode(2);

        Http::assertSentCount(ApiFootballDataSyncStatusService::MAX_API_CALLS_PER_RUN);
    }

    // 9. nessuna scrittura su matches/match_external_ids (anche in scenario STALE)
    public function test_command_never_writes_to_db(): void
    {
        $this->createDbMatch('9001', '2026-08-22 20:45:00', 'scheduled');
        $matchCountBefore = FootballMatch::count();
        $extIdCountBefore = MatchExternalId::count();
        $syncRunCountBefore = \App\Models\DataSyncRun::count();

        // STALE scenario: kickoff + status + a phantom missing fixture.
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fakeFixturesResponse([
                $this->fixtureItem(['fixture.date' => '2026-09-01T18:00:00+00:00']),
                $this->fixtureItem(['fixture.id' => 9999]), // missing in DB
            ]), 200
        )]);

        $this->artisan('robetting:data-sync-status')->assertExitCode(1);

        $this->assertSame($matchCountBefore, FootballMatch::count());
        $this->assertSame($extIdCountBefore, MatchExternalId::count());
        $this->assertSame($syncRunCountBefore, \App\Models\DataSyncRun::count());

        $match = FootballMatch::first();
        $this->assertSame('2026-08-22 20:45:00', $match->kickoff_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('scheduled', $match->status);
    }

}
