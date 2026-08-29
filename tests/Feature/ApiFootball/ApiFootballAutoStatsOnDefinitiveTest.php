<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\Team;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballResultRefreshService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for the grace-period-based pending stats sync.
 *
 * Design rationale:
 * - The result refresh (processItem) no longer calls stats API on FT transition.
 *   This avoids the race condition where the API returns [] immediately after FT
 *   (stats not yet processed server-side), which would permanently block future retries.
 * - syncPending() uses a kickoff_at cutoff of (90 + grace) minutes to ensure we only
 *   attempt stats after the API has had reasonable time to finalize them.
 * - After the grace period, [] is accepted as "no stats ever" (fetched_at is set).
 *   HTTP failures and unparsable responses leave fetched_at null for retry.
 */
class ApiFootballAutoStatsOnDefinitiveTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $competition;
    private Season $season;
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

        CompetitionExternalId::create([
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
    // 1. live → FT: result saved immediately; stats API NOT called in processItem
    // -------------------------------------------------------------------------

    public function test_live_to_ft_saves_result_without_stats_api_call(): void
    {
        $match = $this->makeMatchWithExtId(now()->subMinutes(95), 'live', 9501);

        // Only fake the result endpoint — if stats were called, the test would panic.
        Http::fake([
            '*fixtures*' => Http::response($this->ftFixtureResponse(9501), 200),
        ]);

        $result = app(ApiFootballResultRefreshService::class)->refresh();

        $this->assertSame(1, $result['updated']);

        $match->refresh();
        $this->assertSame('finished', $match->status);
        $this->assertSame(2, $match->home_score_ft);

        // processItem must never touch the statistics endpoint.
        Http::assertNotSent(fn($req) => str_contains($req->url(), 'statistics'));
    }

    // -------------------------------------------------------------------------
    // 2. Definitivo troppo recente: kickoff 95 min fa, grace=10 → cutoff=100 min
    //    Il match non è ancora candidato per syncPending.
    // -------------------------------------------------------------------------

    public function test_definitive_too_recent_is_not_a_candidate(): void
    {
        // kickoff 95 min ago → cutoff is now() - 100 min → NOT past cutoff
        $this->makeMatchWithExtId(now()->subMinutes(95), 'finished', 9502);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncPending(gracePeriodMinutes: 10);

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 3. Definitivo oltre grace period senza fetched_at → stats richieste
    // -------------------------------------------------------------------------

    public function test_definitive_past_grace_period_gets_stats(): void
    {
        // kickoff 110 min ago → cutoff is now() - 100 min → IS past cutoff
        $match = $this->makeMatchWithExtId(now()->subMinutes(110), 'finished', 9503);

        Http::fake([
            '*fixtures/statistics*' => Http::response($this->statsResponse(), 200),
        ]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncPending(gracePeriodMinutes: 10);

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['synced']);
        $this->assertSame(1, $result['api_calls']);

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();

        $this->assertNotNull($stat);
        $this->assertNotNull($stat->fetched_at);
        $this->assertSame(8, $stat->home_shots);
    }

    // -------------------------------------------------------------------------
    // 4. HTTP error → fetched_at non impostato → ciclo successivo può ritentare
    // -------------------------------------------------------------------------

    public function test_http_error_leaves_match_retryable_on_next_cycle(): void
    {
        $match = $this->makeMatchWithExtId(now()->subMinutes(110), 'finished', 9504);

        // Single fake with a sequence: first call → 500, second call → 200.
        Http::fake([
            '*fixtures/statistics*' => Http::sequence()
                ->push([], 500)
                ->push($this->statsResponse(), 200),
        ]);

        $service = app(ApiFootballMatchStatisticsSyncService::class);

        // First cycle: stats API returns 500 → failed, fetched_at not set.
        $result1 = $service->syncPending(gracePeriodMinutes: 10);

        $this->assertSame(1, $result1['failed']);
        $this->assertSame(0, $result1['synced']);

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();
        $this->assertNull($stat?->fetched_at);

        // Second cycle: API returns real stats → match is synced.
        $result2 = $service->syncPending(gracePeriodMinutes: 10);

        $this->assertSame(1, $result2['candidates']);
        $this->assertSame(1, $result2['synced']);

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();
        $this->assertNotNull($stat);
        $this->assertNotNull($stat->fetched_at);
    }

    // -------------------------------------------------------------------------
    // 5. stats già fetched_at → zero API call
    // -------------------------------------------------------------------------

    public function test_stats_already_fetched_zero_api_calls(): void
    {
        $match = $this->makeMatchWithExtId(now()->subMinutes(110), 'finished', 9505);

        MatchStatistic::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'fetched_at'     => now()->subMinutes(30),
        ]);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncPending(gracePeriodMinutes: 10);

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 6. Nessun candidato (zero match definitivi oltre grace period) → zero API call
    // -------------------------------------------------------------------------

    public function test_no_candidates_zero_api_calls(): void
    {
        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncPending(gracePeriodMinutes: 10);

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        Http::assertNothingSent();
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

    private function ftFixtureResponse(int $extId): array
    {
        return [
            'errors'   => [],
            'results'  => 1,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [[
                'fixture' => [
                    'id'       => $extId,
                    'timezone' => 'UTC',
                    'date'     => now()->subHours(2)->toIso8601String(),
                    'status'   => ['short' => 'FT', 'elapsed' => 90],
                    'venue'    => ['name' => 'Test Stadium'],
                ],
                'league' => [
                    'id'     => 135,
                    'name'   => 'Serie A',
                    'season' => 2026,
                    'round'  => 'Regular Season - 1',
                ],
                'teams' => [
                    'home' => ['id' => 505, 'name' => 'Home FC', 'winner' => true],
                    'away' => ['id' => 489, 'name' => 'Away FC', 'winner' => false],
                ],
                'goals' => ['home' => 2, 'away' => 1],
                'score' => [
                    'halftime'  => ['home' => 1, 'away' => 0],
                    'fulltime'  => ['home' => 2, 'away' => 1],
                    'extratime' => ['home' => null, 'away' => null],
                    'penalty'   => ['home' => null, 'away' => null],
                ],
            ]],
        ];
    }

    private function statsResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 1,
            'response' => [
                [
                    'team'       => ['id' => 505, 'name' => 'Home FC'],
                    'statistics' => [
                        ['type' => 'Total Shots',   'value' => 8],
                        ['type' => 'Shots on Goal', 'value' => 4],
                        ['type' => 'Fouls',         'value' => 12],
                        ['type' => 'Corner Kicks',  'value' => 5],
                        ['type' => 'Yellow Cards',  'value' => 2],
                        ['type' => 'Red Cards',     'value' => 0],
                    ],
                ],
                [
                    'team'       => ['id' => 489, 'name' => 'Away FC'],
                    'statistics' => [
                        ['type' => 'Total Shots',   'value' => 6],
                        ['type' => 'Shots on Goal', 'value' => 2],
                        ['type' => 'Fouls',         'value' => 14],
                        ['type' => 'Corner Kicks',  'value' => 3],
                        ['type' => 'Yellow Cards',  'value' => 1],
                        ['type' => 'Red Cards',     'value' => 0],
                    ],
                ],
            ],
        ];
    }
}
