<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballLeagueImporter;
use App\Services\DataSources\ApiFootball\ApiFootballSelectiveFixtureSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step B1 — selective national-cup import: persist ONLY fixtures involving
 * a core-league team for that exact year_start, create ONLY the non-core
 * opponents those fixtures actually need, never call /teams, never touch
 * advanced data, never become prediction-eligible.
 */
class ApiFootballSelectiveFixtureSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $serieA;
    private Competition $coppaItalia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);
        config(['api-football.core_leagues'            => [135 => 'serie-a']]);
        config(['api-football.additional_competitions' => [137 => 'coppa-italia']]);

        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);

        $this->serieA = Competition::create([
            'country_id' => $country->id, 'name' => 'Serie A', 'slug' => 'serie-a', 'format' => 'league', 'is_active' => true,
        ]);
        $this->coppaItalia = Competition::create([
            'country_id' => $country->id, 'name' => 'Coppa Italia', 'slug' => 'coppa-italia', 'format' => 'cup', 'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Selection
    // -------------------------------------------------------------------------

    public function test_core_vs_core_is_imported(): void
    {
        $this->domesticSeasonWithCoreTeams(2026, ['Napoli' => '100', 'Inter' => '101']);
        $cei = $this->coppaItaliaCei(2026);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixturesResponse([$this->fixtureItem(9001, '100', 'Napoli', '101', 'Inter')]),
            200,
        )]);

        $service = app(ApiFootballSelectiveFixtureSyncService::class);
        $result  = $service->syncCompetition($cei, 2026);

        $this->assertSame(1, $result['relevant']);
        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('matches', ['competition_id' => $this->coppaItalia->id]);
    }

    public function test_core_vs_noncore_is_imported(): void
    {
        $this->domesticSeasonWithCoreTeams(2026, ['Napoli' => '100']);
        $cei = $this->coppaItaliaCei(2026);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixturesResponse([$this->fixtureItem(9002, '100', 'Napoli', '200', 'Serie D Team')]),
            200,
        )]);

        $result = app(ApiFootballSelectiveFixtureSyncService::class)->syncCompetition($cei, 2026);

        $this->assertSame(1, $result['relevant']);
        $this->assertSame(1, $result['created']);
    }

    public function test_noncore_vs_core_is_imported(): void
    {
        $this->domesticSeasonWithCoreTeams(2026, ['Napoli' => '100']);
        $cei = $this->coppaItaliaCei(2026);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixturesResponse([$this->fixtureItem(9003, '200', 'Serie D Team', '100', 'Napoli')]),
            200,
        )]);

        $result = app(ApiFootballSelectiveFixtureSyncService::class)->syncCompetition($cei, 2026);

        $this->assertSame(1, $result['relevant']);
        $this->assertSame(1, $result['created']);
    }

    public function test_noncore_vs_noncore_is_not_imported(): void
    {
        $this->domesticSeasonWithCoreTeams(2026, ['Napoli' => '100']);
        $cei = $this->coppaItaliaCei(2026);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixturesResponse([$this->fixtureItem(9004, '200', 'Serie D Team A', '201', 'Serie D Team B')]),
            200,
        )]);

        $result = app(ApiFootballSelectiveFixtureSyncService::class)->syncCompetition($cei, 2026);

        $this->assertSame(0, $result['relevant']);
        $this->assertSame(0, $result['created']);
        $this->assertDatabaseMissing('matches', ['competition_id' => $this->coppaItalia->id]);
    }

    // -------------------------------------------------------------------------
    // Cross-season membership
    // -------------------------------------------------------------------------

    public function test_cross_season_membership_follows_correct_year_start(): void
    {
        // "Como" is core in 2026 but NOT in 2024 — no season_team row for 2024.
        $this->domesticSeasonWithCoreTeams(2026, ['Como' => '300']);
        $this->domesticSeasonWithCoreTeams(2024, []); // Serie A 2024 season exists, Como not in it

        $cei2026 = $this->coppaItaliaCei(2026);
        $cei2024 = $this->coppaItaliaCei(2024);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixturesResponse([$this->fixtureItem(9005, '300', 'Como', '400', 'Opponent')]),
            200,
        )]);
        $resultIn2026 = app(ApiFootballSelectiveFixtureSyncService::class)->syncCompetition($cei2026, 2026);
        $this->assertSame(1, $resultIn2026['relevant'], 'Como must count as core in 2026');

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixturesResponse([$this->fixtureItem(9006, '300', 'Como', '400', 'Opponent')]),
            200,
        )]);
        $resultIn2024 = app(ApiFootballSelectiveFixtureSyncService::class)->syncCompetition($cei2024, 2024);
        $this->assertSame(0, $resultIn2024['relevant'], 'Como must NOT count as core in 2024 — no season_team row there');
    }

    // -------------------------------------------------------------------------
    // Team persistence
    // -------------------------------------------------------------------------

    public function test_only_teams_needed_by_relevant_fixtures_are_persisted(): void
    {
        $this->domesticSeasonWithCoreTeams(2026, ['Napoli' => '100', 'Inter' => '101']);
        $cei = $this->coppaItaliaCei(2026);

        $relevantItems = [
            $this->fixtureItem(9010, '100', 'Napoli', '900', 'Needed Opponent A'),
            $this->fixtureItem(9011, '101', 'Inter', '901', 'Needed Opponent B'),
            $this->fixtureItem(9012, '100', 'Napoli', '902', 'Needed Opponent C'),
        ];
        $irrelevantItems = [];
        for ($i = 0; $i < 20; $i++) {
            $irrelevantItems[] = $this->fixtureItem(9100 + $i, (string) (9500 + $i * 2), "Irrelevant {$i}A", (string) (9501 + $i * 2), "Irrelevant {$i}B");
        }

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixturesResponse(array_merge($relevantItems, $irrelevantItems)),
            200,
        )]);

        $result = app(ApiFootballSelectiveFixtureSyncService::class)->syncCompetition($cei, 2026);

        $this->assertSame(3, $result['relevant']);
        $this->assertSame(3, $result['teams_created'], 'only the 3 needed opponents, never the 20 irrelevant teams');

        $this->assertDatabaseHas('team_external_ids', ['external_id' => '900']);
        $this->assertDatabaseHas('team_external_ids', ['external_id' => '901']);
        $this->assertDatabaseHas('team_external_ids', ['external_id' => '902']);
        $this->assertDatabaseMissing('team_external_ids', ['external_id' => '9500']);
        $this->assertDatabaseMissing('team_external_ids', ['external_id' => '9501']);

        // Never calls /teams at all.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/teams'));
    }

    // -------------------------------------------------------------------------
    // Fixture persistence / idempotency
    // -------------------------------------------------------------------------

    public function test_second_run_is_idempotent_no_duplicates(): void
    {
        $this->domesticSeasonWithCoreTeams(2026, ['Napoli' => '100']);
        $cei = $this->coppaItaliaCei(2026);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixturesResponse([
                $this->fixtureItem(9020, '100', 'Napoli', '950', 'Opponent'),
                $this->fixtureItem(9021, '960', 'IrrelevantA', '961', 'IrrelevantB'),
            ]),
            200,
        )]);

        $service = app(ApiFootballSelectiveFixtureSyncService::class);
        $service->syncCompetition($cei, 2026);
        $service->syncCompetition($cei, 2026);

        $this->assertSame(1, FootballMatch::where('competition_id', $this->coppaItalia->id)->count());
        $this->assertSame(1, Team::where('name', 'Opponent')->count());
    }

    // -------------------------------------------------------------------------
    // Gate
    // -------------------------------------------------------------------------

    public function test_configured_cup_is_accepted_by_league_importer(): void
    {
        Http::fake(['v3.football.api-sports.io/leagues*' => Http::response($this->leagueResponse(137, 'Coppa Italia', 'Cup', 'Italy'), 200)]);

        $report = app(ApiFootballLeagueImporter::class)->importLeague(137, 2026);

        $this->assertSame('ok', $report['status']);
        $this->assertSame('coppa-italia', $report['slug']);
    }

    public function test_unconfigured_league_id_is_rejected(): void
    {
        Http::fake(['v3.football.api-sports.io/leagues*' => Http::response($this->leagueResponse(9999, 'Random Cup', 'Cup', 'Nowhere'), 200)]);

        $report = app(ApiFootballLeagueImporter::class)->importLeague(9999, 2026);

        $this->assertSame('skipped', $report['status']);
        $this->assertSame(0, Competition::where('slug', 'random-cup')->count());
    }

    // -------------------------------------------------------------------------
    // Prediction safety
    // -------------------------------------------------------------------------

    public function test_imported_cup_fixture_creates_no_prediction_eligibility_artifact(): void
    {
        $this->domesticSeasonWithCoreTeams(2026, ['Napoli' => '100']);
        $cei = $this->coppaItaliaCei(2026);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->fixturesResponse([$this->fixtureItem(9030, '100', 'Napoli', '970', 'Opponent')]),
            200,
        )]);

        app(ApiFootballSelectiveFixtureSyncService::class)->syncCompetition($cei, 2026);

        // No prediction machinery exists/runs here; this just confirms the match
        // was persisted under the cup competition, never under a core competition_id.
        $match = FootballMatch::where('competition_id', $this->coppaItalia->id)->first();
        $this->assertNotNull($match);
        $this->assertNotEquals($this->serieA->id, $match->competition_id);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function domesticSeasonWithCoreTeams(int $yearStart, array $teams): Season
    {
        $season = Season::create([
            'competition_id' => $this->serieA->id,
            'name'           => "{$yearStart}/" . substr((string) ($yearStart + 1), -2),
            'year_start'     => $yearStart,
            'year_end'       => $yearStart + 1,
            'is_current'     => true,
        ]);

        foreach ($teams as $name => $extId) {
            $team = Team::firstOrCreate(['name' => $name], ['type' => 'club', 'is_active' => true]);
            TeamExternalId::firstOrCreate(
                ['team_id' => $team->id, 'data_source_id' => $this->ds->id],
                ['external_id' => $extId, 'external_name' => $name],
            );
            $season->teams()->attach($team->id);
        }

        return $season;
    }

    private function coppaItaliaCei(int $season): CompetitionExternalId
    {
        $cei = CompetitionExternalId::firstOrCreate(
            ['competition_id' => $this->coppaItalia->id, 'data_source_id' => $this->ds->id],
            ['external_id' => '137', 'external_name' => 'Coppa Italia'],
        );

        SeasonExternalId::firstOrCreate(
            ['competition_id' => $this->coppaItalia->id, 'data_source_id' => $this->ds->id, 'external_id' => (string) $season],
            ['season_id' => Season::create([
                'competition_id' => $this->coppaItalia->id,
                'name'           => "{$season}/" . substr((string) ($season + 1), -2),
                'year_start'     => $season,
                'year_end'       => $season + 1,
                'is_current'     => true,
            ])->id],
        );

        return $cei;
    }

    private function fixturesResponse(array $items): array
    {
        return [
            'errors'   => [],
            'results'  => count($items),
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => $items,
        ];
    }

    private function fixtureItem(int $fixtureId, string $homeId, string $homeName, string $awayId, string $awayName): array
    {
        return [
            'fixture' => [
                'id'        => $fixtureId,
                'referee'   => null,
                'timezone'  => 'UTC',
                'date'      => now()->subDay()->toIso8601String(),
                'timestamp' => now()->subDay()->timestamp,
                'status'    => ['long' => 'Match Finished', 'short' => 'FT', 'elapsed' => 90],
            ],
            'league' => ['id' => 137, 'season' => 2026, 'round' => 'Round of 16'],
            'teams' => [
                'home' => ['id' => (int) $homeId, 'name' => $homeName, 'winner' => null],
                'away' => ['id' => (int) $awayId, 'name' => $awayName, 'winner' => null],
            ],
            'goals' => ['home' => 1, 'away' => 0],
            'score' => [
                'halftime'  => ['home' => 0, 'away' => 0],
                'fulltime'  => ['home' => 1, 'away' => 0],
                'extratime' => ['home' => null, 'away' => null],
                'penalty'   => ['home' => null, 'away' => null],
            ],
        ];
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
                    'seasons' => [[
                        'year' => 2026, 'start' => '2026-08-22', 'end' => '2027-05-30', 'current' => true,
                        'coverage' => ['fixtures' => ['events' => true, 'lineups' => true, 'statistics_fixtures' => true, 'statistics_players' => true], 'standings' => false, 'injuries' => false],
                    ]],
                ],
            ],
        ];
    }
}
