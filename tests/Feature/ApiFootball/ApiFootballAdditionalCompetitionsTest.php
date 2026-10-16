<?php

namespace Tests\Feature\ApiFootball;

use App\Services\DataSources\ApiFootball\ApiFootballLeagueImporter;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step A5.1 — the league importer gate must accept core_leagues AND the new
 * additional_competitions bucket (UEFA), while still rejecting any ID
 * present in neither. importCoreLeagues() must stay scoped to core_leagues
 * only, even when additional_competitions is configured.
 */
class ApiFootballAdditionalCompetitionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.core_leagues' => [135 => 'serie-a', 39 => 'premier-league']]);
        config(['api-football.additional_competitions' => [2 => 'uefa-champions-league', 3 => 'uefa-europa-league']]);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);
    }

    public function test_core_league_is_still_accepted(): void
    {
        Http::fake(['v3.football.api-sports.io/leagues*' => Http::response(
            $this->leagueResponse(135, 'Serie A', 'League', 'Italy'),
            200,
        )]);

        $report = app(ApiFootballLeagueImporter::class)->importLeague(135, 2026);

        $this->assertSame('ok', $report['status']);
        $this->assertSame('serie-a', $report['slug']);
        $this->assertDatabaseHas('competitions', ['slug' => 'serie-a', 'format' => 'league']);
    }

    public function test_uefa_competition_is_accepted(): void
    {
        Http::fake(['v3.football.api-sports.io/leagues*' => Http::response(
            $this->leagueResponse(2, 'UEFA Champions League', 'Cup', 'World'),
            200,
        )]);

        $report = app(ApiFootballLeagueImporter::class)->importLeague(2, 2026);

        $this->assertSame('ok', $report['status']);
        $this->assertSame('uefa-champions-league', $report['slug']);
        $this->assertDatabaseHas('competitions', ['slug' => 'uefa-champions-league', 'format' => 'cup']);
    }

    public function test_unconfigured_league_id_is_still_rejected(): void
    {
        Http::fake(['v3.football.api-sports.io/leagues*' => Http::response(
            $this->leagueResponse(999, 'Some Random League', 'League', 'Nowhere'),
            200,
        )]);

        $report = app(ApiFootballLeagueImporter::class)->importLeague(999, 2026);

        $this->assertSame('skipped', $report['status']);
        $this->assertStringContainsString('slug', $report['message']);
        $this->assertSame(0, \App\Models\Competition::count());
    }

    public function test_import_core_leagues_still_ignores_additional_competitions(): void
    {
        // importCoreLeagues() must only ever iterate core_leagues, even though
        // additional_competitions is configured in this test's setUp().
        Http::fake(['v3.football.api-sports.io/leagues*' => Http::sequence()
            ->push($this->leagueResponse(135, 'Serie A', 'League', 'Italy'))
            ->push($this->leagueResponse(39, 'Premier League', 'League', 'England'))
        ]);

        $result = app(ApiFootballLeagueImporter::class)->importCoreLeagues(2026);

        $this->assertCount(2, $result['results']);
        $this->assertSame(2, \App\Models\Competition::count());
        $this->assertDatabaseHas('competitions', ['slug' => 'serie-a']);
        $this->assertDatabaseHas('competitions', ['slug' => 'premier-league']);
        $this->assertDatabaseMissing('competitions', ['slug' => 'uefa-champions-league']);
    }

    private function leagueResponse(int $id, string $name, string $type, string $country): array
    {
        return [
            'errors'   => [],
            'results'  => 1,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                [
                    'league'  => ['id' => $id, 'name' => $name, 'type' => $type],
                    'country' => ['name' => $country, 'code' => null],
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
}
