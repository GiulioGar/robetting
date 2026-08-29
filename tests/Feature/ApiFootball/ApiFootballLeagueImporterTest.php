<?php

namespace Tests\Feature\ApiFootball;

use App\Services\DataSources\ApiFootball\ApiFootballLeagueImporter;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiFootballLeagueImporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        // Ensure core_leagues config is set for tests
        config(['api-football.core_leagues' => [135 => 'serie-a', 39 => 'premier-league']]);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_import_creates_country_competition_season_and_external_ids(): void
    {
        Http::fake(['v3.football.api-sports.io/leagues*' => Http::response(
            $this->serieAResponse(),
            200,
            ['x-ratelimit-requests-remaining' => '7494', 'X-RateLimit-Remaining' => '298'],
        )]);

        $report = app(ApiFootballLeagueImporter::class)->importLeague(135, 2026);

        $this->assertSame('ok', $report['status']);
        $this->assertSame('serie-a', $report['slug']);
        $this->assertSame(7494, $report['requests_remaining']);
        $this->assertSame(298,  $report['minute_remaining']);

        // Country — code goes to football_code, not iso_code_alpha2
        $this->assertDatabaseHas('countries', ['name' => 'Italy', 'football_code' => 'IT']);
        $this->assertDatabaseHas('countries', ['name' => 'Italy', 'iso_code_alpha2' => null]);

        // Competition
        $this->assertDatabaseHas('competitions', [
            'slug'   => 'serie-a',
            'name'   => 'Serie A',
            'format' => 'league',
        ]);

        // Season
        $this->assertDatabaseHas('seasons', [
            'name'       => '2026/27',
            'year_start' => 2026,
            'year_end'   => 2027,
            'start_date' => '2026-08-22',
            'end_date'   => '2027-05-30',
            'is_current' => 1,
        ]);

        // competition_external_ids
        $this->assertDatabaseHas('competition_external_ids', ['external_id' => '135']);

        // season_external_ids
        $this->assertDatabaseHas('season_external_ids', ['external_id' => '2026']);
    }

    public function test_coverage_is_persisted_correctly(): void
    {
        Http::fake(['v3.football.api-sports.io/leagues*' => Http::response($this->serieAResponse(), 200)]);

        app(ApiFootballLeagueImporter::class)->importLeague(135, 2026);

        $sei = \App\Models\SeasonExternalId::first();
        $this->assertIsArray($sei->coverage);
        $this->assertTrue($sei->coverage['standings']);
        $this->assertTrue($sei->coverage['fixtures']['events']);
        $this->assertTrue($sei->coverage['fixtures']['statistics_fixtures']);
    }

    public function test_extended_country_code_stored_as_football_code_not_iso(): void
    {
        $body = $this->serieAResponse();
        $body['response'][0]['country'] = ['name' => 'England', 'code' => 'GB-ENG'];

        Http::fake(['v3.football.api-sports.io/leagues*' => Http::response($body, 200)]);

        app(ApiFootballLeagueImporter::class)->importLeague(135, 2026);

        $this->assertDatabaseHas('countries', ['name' => 'England', 'football_code' => 'GB-ENG']);
        $this->assertDatabaseHas('countries', ['name' => 'England', 'iso_code_alpha2' => null]);
    }

    public function test_format_mapping_league_to_league(): void
    {
        Http::fake(['v3.football.api-sports.io/leagues*' => Http::response($this->serieAResponse(), 200)]);

        app(ApiFootballLeagueImporter::class)->importLeague(135, 2026);

        $this->assertDatabaseHas('competitions', ['slug' => 'serie-a', 'format' => 'league']);
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_import_is_idempotent(): void
    {
        Http::fake(['v3.football.api-sports.io/leagues*' => Http::response($this->serieAResponse(), 200)]);

        $importer = app(ApiFootballLeagueImporter::class);
        $importer->importLeague(135, 2026);
        $importer->importLeague(135, 2026);

        $this->assertSame(1, \App\Models\Country::count());
        $this->assertSame(1, \App\Models\Competition::count());
        $this->assertSame(1, \App\Models\Season::count());
        $this->assertSame(1, \App\Models\CompetitionExternalId::count());
        $this->assertSame(1, \App\Models\SeasonExternalId::count());
    }

    // -------------------------------------------------------------------------
    // Skip conditions
    // -------------------------------------------------------------------------

    public function test_empty_response_returns_skip_and_creates_nothing(): void
    {
        Http::fake(['v3.football.api-sports.io/leagues*' => Http::response([
            'errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => [],
        ], 200)]);

        $report = app(ApiFootballLeagueImporter::class)->importLeague(135, 2026);

        $this->assertSame('skipped', $report['status']);
        $this->assertStringContainsString('empty', $report['message']);
        $this->assertSame(0, \App\Models\Competition::count());
    }

    public function test_unconfigured_league_id_returns_skip(): void
    {
        Http::fake(['v3.football.api-sports.io/leagues*' => Http::response($this->serieAResponse(), 200)]);

        // league ID 999 has no slug in config
        $report = app(ApiFootballLeagueImporter::class)->importLeague(999, 2026);

        $this->assertSame('skipped', $report['status']);
        $this->assertStringContainsString('slug', $report['message']);
        $this->assertSame(0, \App\Models\Competition::count());
    }

    public function test_missing_season_in_response_returns_skip(): void
    {
        $body = $this->serieAResponse();
        // Remove season 2026 from the seasons array
        $body['response'][0]['seasons'] = [];

        Http::fake(['v3.football.api-sports.io/leagues*' => Http::response($body, 200)]);

        $report = app(ApiFootballLeagueImporter::class)->importLeague(135, 2026);

        $this->assertSame('skipped', $report['status']);
        $this->assertSame(0, \App\Models\Season::count());
    }

    // -------------------------------------------------------------------------
    // Fake response helper
    // -------------------------------------------------------------------------

    private function serieAResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 1,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                [
                    'league'  => ['id' => 135, 'name' => 'Serie A', 'type' => 'League'],
                    'country' => ['name' => 'Italy', 'code' => 'IT'],
                    'seasons' => [
                        [
                            'year'     => 2026,
                            'start'    => '2026-08-22',
                            'end'      => '2027-05-30',
                            'current'  => true,
                            'coverage' => [
                                'fixtures'    => ['events' => true, 'lineups' => true, 'statistics_fixtures' => true, 'statistics_players' => true],
                                'standings'   => true,
                                'players'     => true,
                                'top_scorers' => true,
                                'top_assists' => true,
                                'top_cards'   => true,
                                'injuries'    => true,
                                'predictions' => true,
                                'odds'        => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
