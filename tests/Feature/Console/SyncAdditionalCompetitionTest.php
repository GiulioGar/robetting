<?php

namespace Tests\Feature\Console;

use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step A5.1 — robetting:sync-additional-competition must run the 3 steps
 * (competition/season -> teams -> fixtures) in that exact order for a
 * configured additional competition (e.g. UEFA), reject anything not in
 * api-football.additional_competitions, and never call any advanced
 * endpoint (statistics/xG/lineups/player-stats/events/injuries).
 */
class SyncAdditionalCompetitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.core_leagues' => [135 => 'serie-a']]);
        config(['api-football.additional_competitions' => [2 => 'uefa-champions-league']]);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);
    }

    public function test_full_pipeline_runs_in_order_and_touches_no_advanced_endpoint(): void
    {
        Http::fake([
            'v3.football.api-sports.io/leagues*' => Http::response($this->leagueResponse(), 200),
            'v3.football.api-sports.io/teams*'   => Http::response($this->teamsResponse(), 200),
            'v3.football.api-sports.io/fixtures*' => Http::response($this->fixturesResponse(), 200),
        ]);

        $this->artisan('robetting:sync-additional-competition', ['--league' => 2, '--season' => 2026])
            ->assertExitCode(0);

        // 1. competition + season created
        $this->assertDatabaseHas('competitions', ['slug' => 'uefa-champions-league', 'format' => 'cup']);
        $this->assertDatabaseHas('seasons', ['name' => '2026/27', 'year_start' => 2026]);

        // 2. teams created
        $this->assertDatabaseHas('teams', ['name' => 'Bayern Munich']);
        $this->assertDatabaseHas('teams', ['name' => 'Benfica']);

        // 3. fixture created, correctly mapped to those teams
        $this->assertDatabaseHas('matches', ['home_score_ft' => 2, 'away_score_ft' => 1]);

        // Advanced endpoints must never be called in this step.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fixtures/statistics'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fixtures/lineups'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fixtures/players'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fixtures/events'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'injuries'));

        // Order: fixture sync must have happened after team sync (team must
        // already be mapped — see ApiFootballFixtureSyncService). Verified
        // indirectly: the match above exists with both teams resolved, which
        // is impossible if fixtures were synced before teams (fixture would
        // have been silently skipped instead).
        $this->assertSame(1, \App\Models\FootballMatch::count());
    }

    public function test_unconfigured_league_id_is_rejected_without_any_api_call(): void
    {
        Http::fake(); // any call would be unexpected

        $this->artisan('robetting:sync-additional-competition', ['--league' => 9999, '--season' => 2026])
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(0, \App\Models\Competition::count());
    }

    private function leagueResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 1,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                [
                    'league'  => ['id' => 2, 'name' => 'UEFA Champions League', 'type' => 'Cup'],
                    'country' => ['name' => 'World', 'code' => null],
                    'seasons' => [
                        [
                            'year'     => 2026,
                            'start'    => '2026-08-22',
                            'end'      => '2027-05-30',
                            'current'  => true,
                            'coverage' => [
                                'fixtures'  => ['events' => true, 'lineups' => true, 'statistics_fixtures' => true, 'statistics_players' => true],
                                'standings' => true,
                                'injuries'  => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function teamsResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 2,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                ['team' => ['id' => 157, 'name' => 'Bayern Munich', 'code' => 'BAY', 'country' => 'Germany', 'founded' => 1900, 'national' => false, 'logo' => null], 'venue' => []],
                ['team' => ['id' => 211, 'name' => 'Benfica',       'code' => 'BEN', 'country' => 'Portugal', 'founded' => 1904, 'national' => false, 'logo' => null], 'venue' => []],
            ],
        ];
    }

    private function fixturesResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 1,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                [
                    'fixture' => [
                        'id'        => 555001,
                        'referee'   => null,
                        'timezone'  => 'UTC',
                        'date'      => '2026-09-16T19:00:00+00:00',
                        'timestamp' => 1789672800,
                        'status'    => ['long' => 'Match Finished', 'short' => 'FT', 'elapsed' => 90],
                    ],
                    'league' => ['id' => 2, 'season' => 2026, 'round' => 'League Stage'],
                    'teams' => [
                        'home' => ['id' => 157, 'name' => 'Bayern Munich', 'winner' => true],
                        'away' => ['id' => 211, 'name' => 'Benfica',       'winner' => false],
                    ],
                    'goals' => ['home' => 2, 'away' => 1],
                    'score' => [
                        'halftime'  => ['home' => 1, 'away' => 0],
                        'fulltime'  => ['home' => 2, 'away' => 1],
                        'extratime' => ['home' => null, 'away' => null],
                        'penalty'   => ['home' => null, 'away' => null],
                    ],
                ],
            ],
        ];
    }
}
