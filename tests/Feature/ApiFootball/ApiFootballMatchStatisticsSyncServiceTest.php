<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiFootballMatchStatisticsSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $competition;
    private Season $season;
    private Team $homeTeam;
    private Team $awayTeam;

    // API-Football external IDs for the test teams
    private const HOME_EXT_ID = '505';
    private const AWAY_EXT_ID = '489';

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

        $this->homeTeam = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->awayTeam = Team::create(['name' => 'Milan', 'type' => 'club', 'is_active' => true]);

        TeamExternalId::create([
            'team_id'        => $this->homeTeam->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => self::HOME_EXT_ID,
            'external_name'  => 'Inter',
        ]);

        TeamExternalId::create([
            'team_id'        => $this->awayTeam->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => self::AWAY_EXT_ID,
            'external_name'  => 'Milan',
        ]);
    }

    // -------------------------------------------------------------------------
    // 1. Definitive match without stats → imported via API call
    // -------------------------------------------------------------------------

    public function test_definitive_match_without_stats_is_imported(): void
    {
        $match = $this->makeFinishedMatch(9001);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response(
            $this->statsResponse(),
            200,
            ['x-ratelimit-requests-remaining' => '7490'],
        )]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['unchanged']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(7490, $result['daily_remaining']);

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();
        $this->assertNotNull($stat);
        $this->assertNotNull($stat->fetched_at, 'fetched_at deve essere impostato dopo un sync riuscito');
        $this->assertSame(12, $stat->home_shots);
        $this->assertSame(9, $stat->away_shots);
        $this->assertSame(5, $stat->home_shots_on_target);
        $this->assertSame(3, $stat->away_shots_on_target);
        $this->assertSame(11, $stat->home_fouls);
        $this->assertSame(14, $stat->away_fouls);
        $this->assertSame(6, $stat->home_corners);
        $this->assertSame(4, $stat->away_corners);
        $this->assertSame(1, $stat->home_yellow_cards);
        $this->assertSame(2, $stat->away_yellow_cards);
        $this->assertSame(0, $stat->home_red_cards);
        $this->assertSame(0, $stat->away_red_cards);
    }

    // -------------------------------------------------------------------------
    // 2. Complete stats → zero API call
    // -------------------------------------------------------------------------

    public function test_complete_stats_skipped_no_api_call(): void
    {
        $match = $this->makeFinishedMatch(9002);
        $this->makeCompleteStats($match->id);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['unchanged']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 3. Incomplete stats (home_shots NULL) → API called, row updated
    // -------------------------------------------------------------------------

    public function test_incomplete_stats_refreshed(): void
    {
        $match = $this->makeFinishedMatch(9003);
        $this->makeIncompleteStats($match->id);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response(
            $this->statsResponse(),
            200,
        )]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();
        $this->assertSame(12, $stat->home_shots);
    }

    // -------------------------------------------------------------------------
    // 4. Non-definitive match → excluded, no API call
    // -------------------------------------------------------------------------

    public function test_non_definitive_match_excluded(): void
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHour(),
            'status'         => 'scheduled',
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '9004',
            'external_name'  => null,
        ]);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 5. Definitive match with no external ID → skipped with warning
    // -------------------------------------------------------------------------

    public function test_match_without_external_id_skipped_with_warning(): void
    {
        // Create a finished match but NO MatchExternalId for api-football
        FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subDay(),
            'status'         => 'finished',
        ]);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(0, $result['api_calls']);
        $this->assertSame(1, $result['skipped']);
        $this->assertNotEmpty($result['warnings']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 6. Idempotent: second sync after first creates complete stats → no API call
    // -------------------------------------------------------------------------

    public function test_idempotent_second_sync_no_api_call(): void
    {
        $this->makeFinishedMatch(9005);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response(
            $this->statsResponse(),
            200,
        )]);

        // First sync imports the stats
        app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        // Second sync: stats are now complete → no API call
        Http::fake(); // reset so we can assert nothing was sent

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        $this->assertSame(1, $result['unchanged']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 7. DataSyncRun recorded with sync_type = statistics_sync
    // -------------------------------------------------------------------------

    public function test_data_sync_run_recorded(): void
    {
        Http::fake();

        app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertDatabaseHas('data_sync_runs', [
            'data_source_id' => $this->ds->id,
            'sync_type'      => 'statistics_sync',
            'status'         => 'ok',
        ]);
    }

    // -------------------------------------------------------------------------
    // 8. Admin POST action delegates to service
    // -------------------------------------------------------------------------

    public function test_admin_action_delegates_to_service(): void
    {
        $this->app['env'] = 'local';

        Http::fake();

        $response = $this->withoutMiddleware()
            ->post(route('admin.api-football.statistics.sync'));

        $response->assertRedirect(route('admin.api-football.statistics'));

        $this->assertDatabaseHas('data_sync_runs', [
            'data_source_id' => $this->ds->id,
            'sync_type'      => 'statistics_sync',
        ]);
    }

    // -------------------------------------------------------------------------
    // 9. Artisan command delegates to service
    // -------------------------------------------------------------------------

    public function test_artisan_command_delegates_to_service(): void
    {
        Http::fake();

        $this->artisan('robetting:sync-api-football-statistics')
            ->assertExitCode(0);

        $this->assertDatabaseHas('data_sync_runs', [
            'data_source_id' => $this->ds->id,
            'sync_type'      => 'statistics_sync',
        ]);
    }

    // -------------------------------------------------------------------------
    // 10. Partial stats with fetched_at set → NOT a candidate (no API call)
    // -------------------------------------------------------------------------

    public function test_partial_stats_with_fetched_at_skipped_no_api_call(): void
    {
        $match = $this->makeFinishedMatch(9010);
        // Row has home_shots but home_fouls is null — source never had that metric.
        // fetched_at IS NOT NULL → considered complete, must not re-fetch.
        $this->makePartiallyFetchedStats($match->id);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        $this->assertSame(1, $result['unchanged']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 11. Empty API response → fetched_at set → second sync does not loop
    // -------------------------------------------------------------------------

    public function test_empty_api_response_sets_fetched_at_prevents_loop(): void
    {
        $match = $this->makeFinishedMatch(9011);

        $emptyResponse = [
            'errors'   => [],
            'results'  => 0,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [],   // source has no stats for this fixture
        ];

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($emptyResponse, 200)]);

        // First sync: empty response → fetched_at should be set, skipped count = 1
        $result1 = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(1, $result1['candidates']);
        $this->assertSame(1, $result1['api_calls']);
        $this->assertSame(1, $result1['skipped']);
        $this->assertNotEmpty($result1['warnings']);

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();
        $this->assertNotNull($stat, 'Row deve essere creata anche per risposta vuota');
        $this->assertNotNull($stat->fetched_at, 'fetched_at deve essere impostato anche per risposta vuota');
        $this->assertNull($stat->home_shots, 'Nessun dato statistico deve essere presente');

        // Second sync: fetched_at is set → match is complete → no API call
        Http::fake();

        $result2 = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(0, $result2['candidates']);
        $this->assertSame(0, $result2['api_calls']);
        $this->assertSame(1, $result2['unchanged']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 12. Non-empty but unparsable response → fetched_at stays null → retriable
    // -------------------------------------------------------------------------

    public function test_unparsable_response_leaves_fetched_at_null_and_recandidates(): void
    {
        $match = $this->makeFinishedMatch(9013);

        // Response with only 1 team entry — parseResponse requires 2 to identify home/away.
        $oneTeamResponse = [
            'errors'   => [],
            'results'  => 1,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                ['team' => ['id' => (int) self::HOME_EXT_ID, 'name' => 'Inter'], 'statistics' => [
                    ['type' => 'Total Shots', 'value' => 12],
                ]],
            ],
        ];

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($oneTeamResponse, 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(1, $result['skipped']);
        $this->assertNotEmpty($result['warnings']);

        // No row created (or row without fetched_at) → must remain retriable
        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();
        $this->assertNull($stat?->fetched_at, 'fetched_at non deve essere impostato per risposta non parsabile');

        // Second sync: match is still a candidate
        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($oneTeamResponse, 200)]);

        $result2 = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(1, $result2['candidates'], 'Match deve essere ancora candidato al sync successivo');
        $this->assertSame(1, $result2['api_calls']);
    }

    // -------------------------------------------------------------------------
    // 13. home_shots present but fetched_at = null → still a candidate (old rule would have skipped)
    // -------------------------------------------------------------------------

    public function test_row_with_home_shots_but_no_fetched_at_is_candidate(): void
    {
        $match = $this->makeFinishedMatch(9012);
        // Simulates a legacy row created without going through the sync service
        // (fetched_at = null, but home_shots is set). Old completeness rule
        // would have skipped this; new rule correctly re-fetches it.
        MatchStatistic::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'fetched_at'     => null,
            'home_shots'     => 5,
            'away_shots'     => 3,
        ]);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response(
            $this->statsResponse(),
            200,
        )]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $this->assertSame(1, $result['candidates'], 'fetched_at=null deve rendere il match candidato anche se home_shots è presente');
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(1, $result['updated']);

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();
        $this->assertNotNull($stat->fetched_at);
    }

    // -------------------------------------------------------------------------
    // syncMissingHistorical — candidacy & filtering
    // -------------------------------------------------------------------------

    public function test_historical_finished_without_stats_is_candidate(): void
    {
        $match = $this->makeFinishedMatch(9100);

        Http::fake(['*fixtures/statistics*' => Http::response($this->statsResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncMissingHistorical();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['unchanged']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertNull($result['daily_remaining']);

        $stat = MatchStatistic::where('match_id', $match->id)->where('data_source_id', $this->ds->id)->first();
        $this->assertNotNull($stat->fetched_at);
        $this->assertSame(12, $stat->home_shots);
    }

    public function test_historical_match_with_complete_stats_is_excluded(): void
    {
        $match = $this->makeFinishedMatch(9101);
        $this->makeCompleteStats($match->id);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncMissingHistorical();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame(0, $result['api_calls']);

        Http::assertNothingSent();
    }

    public function test_historical_different_season_year_excludes_match(): void
    {
        // Match is in $this->season (year_start=2026). Querying for year_start=2025
        // finds no season → candidates=0.
        $this->makeFinishedMatch(9102);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncMissingHistorical(2025);

        $this->assertSame('no_season_found', $result['status']);
        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
    }

    public function test_historical_all_missing_are_processed(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->makeFinishedMatch(9110 + $i);
        }

        Http::fake(['*fixtures/statistics*' => Http::response($this->statsResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncMissingHistorical();

        $this->assertSame(4, $result['candidates']);
        $this->assertSame(4, $result['created']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(4, $result['api_calls']);
        $this->assertSame('ok', $result['status']);
    }

    public function test_historical_single_error_does_not_block_others(): void
    {
        $this->makeFinishedMatch(9120);
        $this->makeFinishedMatch(9121);

        Http::fake([
            '*fixtures/statistics*' => Http::sequence()
                ->push(null, 500)
                ->push($this->statsResponse(), 200),
        ]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncMissingHistorical();

        $this->assertSame(2, $result['candidates']);
        $this->assertSame(2, $result['created'] + $result['failed']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['failed']);
    }

    public function test_historical_second_run_processes_only_remaining(): void
    {
        $this->makeFinishedMatch(9130);

        Http::fake(['*fixtures/statistics*' => Http::response($this->statsResponse(), 200)]);

        $first = app(ApiFootballMatchStatisticsSyncService::class)->syncMissingHistorical();
        $this->assertSame(1, $first['candidates']);
        $this->assertSame(1, $first['created']);

        // fetched_at is now set → match excluded on second run.
        Http::fake();

        $second = app(ApiFootballMatchStatisticsSyncService::class)->syncMissingHistorical();
        $this->assertSame(0, $second['candidates']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(0, $second['api_calls']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeFinishedMatch(int $extId): FootballMatch
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subDay(),
            'status'         => 'finished',
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => (string) $extId,
            'external_name'  => null,
        ]);

        return $match;
    }

    private function makeCompleteStats(int $matchId): MatchStatistic
    {
        return MatchStatistic::create([
            'match_id'             => $matchId,
            'data_source_id'       => $this->ds->id,
            'fetched_at'           => now(),
            'home_shots'           => 10,
            'away_shots'           => 8,
            'home_shots_on_target' => 4,
            'away_shots_on_target' => 3,
            'home_fouls'           => 12,
            'away_fouls'           => 14,
            'home_corners'         => 5,
            'away_corners'         => 3,
            'home_yellow_cards'    => 1,
            'away_yellow_cards'    => 2,
            'home_red_cards'       => 0,
            'away_red_cards'       => 0,
        ]);
    }

    /** A row that was previously fetched but some metrics came back null from the source. */
    private function makePartiallyFetchedStats(int $matchId): MatchStatistic
    {
        return MatchStatistic::create([
            'match_id'       => $matchId,
            'data_source_id' => $this->ds->id,
            'fetched_at'     => now(),   // already fetched
            'home_shots'     => 10,
            'away_shots'     => 8,
            'home_fouls'     => null,    // source did not provide this metric
            'away_fouls'     => null,
        ]);
    }

    /** A row that was never successfully fetched (fetched_at = null). */
    private function makeIncompleteStats(int $matchId): MatchStatistic
    {
        return MatchStatistic::create([
            'match_id'       => $matchId,
            'data_source_id' => $this->ds->id,
            'fetched_at'     => null,    // not yet fetched → candidate
            'home_shots'     => null,
            'away_shots'     => null,
        ]);
    }

    // =========================================================================
    // Extended metrics — parsing tests
    // =========================================================================

    public function test_all_extended_metrics_parsed_and_saved(): void
    {
        $match = $this->makeFinishedMatch(9200);

        Http::fake(['*fixtures/statistics*' => Http::response($this->extendedStatsResponse(), 200)]);

        app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();

        $this->assertNotNull($stat);

        // --- shots ---
        $this->assertSame(12,  $stat->home_shots);
        $this->assertSame(9,   $stat->away_shots);
        $this->assertSame(5,   $stat->home_shots_on_target);
        $this->assertSame(3,   $stat->away_shots_on_target);
        $this->assertSame(4,   $stat->home_shots_off_target);
        $this->assertSame(3,   $stat->away_shots_off_target);
        $this->assertSame(2,   $stat->home_blocked_shots);
        $this->assertSame(1,   $stat->away_blocked_shots);
        $this->assertSame(8,   $stat->home_shots_insidebox);
        $this->assertSame(5,   $stat->away_shots_insidebox);
        $this->assertSame(4,   $stat->home_shots_outsidebox);
        $this->assertSame(4,   $stat->away_shots_outsidebox);

        // --- discipline / set pieces ---
        $this->assertSame(11, $stat->home_fouls);
        $this->assertSame(14, $stat->away_fouls);
        $this->assertSame(6,  $stat->home_corners);
        $this->assertSame(4,  $stat->away_corners);
        $this->assertSame(1,  $stat->home_yellow_cards);
        $this->assertSame(2,  $stat->away_yellow_cards);
        $this->assertSame(0,  $stat->home_red_cards);
        $this->assertSame(0,  $stat->away_red_cards);
        $this->assertSame(2,  $stat->home_offsides);
        $this->assertSame(1,  $stat->away_offsides);

        // --- possession (percentage string → float) ---
        $this->assertEqualsWithDelta(55.0, $stat->home_possession, 0.001);
        $this->assertEqualsWithDelta(45.0, $stat->away_possession, 0.001);

        // --- goalkeeper saves ---
        $this->assertSame(3, $stat->home_goalkeeper_saves);
        $this->assertSame(5, $stat->away_goalkeeper_saves);

        // --- passes ---
        $this->assertSame(512, $stat->home_passes_total);
        $this->assertSame(389, $stat->away_passes_total);
        $this->assertSame(447, $stat->home_passes_accurate);
        $this->assertSame(321, $stat->away_passes_accurate);
        $this->assertEqualsWithDelta(87.0, $stat->home_passes_percentage, 0.001);
        $this->assertEqualsWithDelta(82.0, $stat->away_passes_percentage, 0.001);
    }

    public function test_possession_percentage_string_parsed_to_float(): void
    {
        $match = $this->makeFinishedMatch(9201);

        $response = $this->extendedStatsResponse();
        // Replace possession with unusual formats
        $response['response'][0]['statistics'] = array_map(function ($s) {
            return $s['type'] === 'Ball Possession' ? ['type' => 'Ball Possession', 'value' => '63%'] : $s;
        }, $response['response'][0]['statistics']);
        $response['response'][1]['statistics'] = array_map(function ($s) {
            return $s['type'] === 'Ball Possession' ? ['type' => 'Ball Possession', 'value' => '37%'] : $s;
        }, $response['response'][1]['statistics']);

        Http::fake(['*fixtures/statistics*' => Http::response($response, 200)]);

        app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $stat = MatchStatistic::where('match_id', $match->id)->where('data_source_id', $this->ds->id)->first();
        $this->assertEqualsWithDelta(63.0, $stat->home_possession, 0.001);
        $this->assertEqualsWithDelta(37.0, $stat->away_possession, 0.001);
    }

    public function test_passes_percentage_string_parsed_to_float(): void
    {
        $match = $this->makeFinishedMatch(9202);

        Http::fake(['*fixtures/statistics*' => Http::response($this->extendedStatsResponse(), 200)]);

        app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $stat = MatchStatistic::where('match_id', $match->id)->where('data_source_id', $this->ds->id)->first();
        $this->assertEqualsWithDelta(87.0, $stat->home_passes_percentage, 0.001);
        $this->assertEqualsWithDelta(82.0, $stat->away_passes_percentage, 0.001);
    }

    public function test_null_extended_metrics_preserved_as_null(): void
    {
        $match = $this->makeFinishedMatch(9203);

        // Response that completely omits the new metrics (only core 6 present)
        Http::fake(['*fixtures/statistics*' => Http::response($this->statsResponse(), 200)]);

        app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $stat = MatchStatistic::where('match_id', $match->id)->where('data_source_id', $this->ds->id)->first();

        // Core metrics still populated
        $this->assertSame(12, $stat->home_shots);

        // Extended metrics absent from response → null in DB, not 0
        $this->assertNull($stat->home_shots_off_target);
        $this->assertNull($stat->away_shots_off_target);
        $this->assertNull($stat->home_possession);
        $this->assertNull($stat->away_possession);
        $this->assertNull($stat->home_goalkeeper_saves);
        $this->assertNull($stat->home_passes_total);
        $this->assertNull($stat->home_passes_percentage);
    }

    public function test_raw_stats_captures_all_api_keys_including_unknown(): void
    {
        $match = $this->makeFinishedMatch(9204);

        Http::fake(['*fixtures/statistics*' => Http::response(
            $this->extendedStatsResponse(withUnknownMetric: true),
            200,
        )]);

        app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $stat = MatchStatistic::where('match_id', $match->id)->where('data_source_id', $this->ds->id)->first();
        $this->assertNotNull($stat->raw_stats);

        // Known metrics present in raw_stats
        $this->assertSame(12, $stat->raw_stats['home']['Total Shots']);
        $this->assertSame(9,  $stat->raw_stats['away']['Total Shots']);
        $this->assertSame('55%', $stat->raw_stats['home']['Ball Possession']);

        // Unknown metric also captured
        $this->assertArrayHasKey('Future Unknown Metric', $stat->raw_stats['home']);
        $this->assertSame(42, $stat->raw_stats['home']['Future Unknown Metric']);
        $this->assertArrayHasKey('Future Unknown Metric', $stat->raw_stats['away']);
        $this->assertSame(17, $stat->raw_stats['away']['Future Unknown Metric']);
    }

    public function test_invalid_percentage_value_stored_as_null(): void
    {
        $match = $this->makeFinishedMatch(9205);

        $response = $this->extendedStatsResponse();
        // Replace Ball Possession with a non-numeric string
        foreach ($response['response'] as &$team) {
            foreach ($team['statistics'] as &$stat) {
                if ($stat['type'] === 'Ball Possession') {
                    $stat['value'] = 'N/A';
                }
            }
        }

        Http::fake(['*fixtures/statistics*' => Http::response($response, 200)]);

        app(ApiFootballMatchStatisticsSyncService::class)->syncAll();

        $stat = MatchStatistic::where('match_id', $match->id)->where('data_source_id', $this->ds->id)->first();
        $this->assertNull($stat->home_possession);
        $this->assertNull($stat->away_possession);
        // Other metrics still correctly parsed
        $this->assertSame(12, $stat->home_shots);
    }

    // =========================================================================
    // Live sync — extended metrics updated, fetched_at untouched
    // =========================================================================

    public function test_live_sync_updates_extended_metrics_without_setting_fetched_at(): void
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHour(),
            'status'         => 'live',
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '9210',
        ]);

        Http::fake(['*fixtures/statistics*' => Http::response($this->extendedStatsResponse(), 200)]);

        $service = app(ApiFootballMatchStatisticsSyncService::class);
        $result  = $service->syncLiveSingle($match, '9210');

        $this->assertSame('synced', $result['outcome']);

        $stat = MatchStatistic::where('match_id', $match->id)->where('data_source_id', $this->ds->id)->first();
        $this->assertNotNull($stat);

        // Extended metrics written
        $this->assertSame(4, $stat->home_shots_off_target);
        $this->assertSame(3, $stat->home_goalkeeper_saves);
        $this->assertEqualsWithDelta(55.0, $stat->home_possession, 0.001);
        $this->assertSame(512, $stat->home_passes_total);

        // fetched_at must NOT be set by live sync
        $this->assertNull($stat->fetched_at);
    }

    public function test_live_sync_does_not_overwrite_existing_fetched_at(): void
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHour(),
            'status'         => 'live',
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '9211',
        ]);

        // Pre-existing row with fetched_at (e.g. post-match sync ran already)
        $existingFetchedAt = now()->subMinutes(30);
        MatchStatistic::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'fetched_at'     => $existingFetchedAt,
            'home_shots'     => 8,
            'away_shots'     => 6,
        ]);

        Http::fake(['*fixtures/statistics*' => Http::response($this->extendedStatsResponse(), 200)]);

        app(ApiFootballMatchStatisticsSyncService::class)->syncLiveSingle($match, '9211');

        $stat = MatchStatistic::where('match_id', $match->id)->where('data_source_id', $this->ds->id)->first();

        // Live sync updates metrics
        $this->assertSame(12, $stat->home_shots);

        // But does NOT touch fetched_at
        $this->assertNotNull($stat->fetched_at);
        $this->assertEqualsWithDelta(
            $existingFetchedAt->timestamp,
            $stat->fetched_at->timestamp,
            2,
        );
    }

    // =========================================================================
    // backfillExtendedHistorical
    // =========================================================================

    public function test_extended_backfill_fetches_even_when_fetched_at_already_set(): void
    {
        $match = $this->makeFinishedMatch(9300);

        // Row already "complete" by existing sentinel
        MatchStatistic::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'fetched_at'     => now()->subDay(),
            'home_shots'     => 10,
            'away_shots'     => 8,
        ]);

        Http::fake(['*fixtures/statistics*' => Http::response($this->extendedStatsResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->backfillExtendedHistorical(2026);

        // Fetched despite existing fetched_at
        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['failed']);

        $stat = MatchStatistic::where('match_id', $match->id)->where('data_source_id', $this->ds->id)->first();
        // Extended columns now populated
        $this->assertSame(4,  $stat->home_shots_off_target);
        $this->assertSame(3,  $stat->home_goalkeeper_saves);
        $this->assertEqualsWithDelta(55.0, $stat->home_possession, 0.001);
    }

    public function test_extended_backfill_is_idempotent_no_duplicate_rows(): void
    {
        $match = $this->makeFinishedMatch(9301);

        Http::fake(['*fixtures/statistics*' => Http::response($this->extendedStatsResponse(), 200)]);

        $service = app(ApiFootballMatchStatisticsSyncService::class);

        $first  = $service->backfillExtendedHistorical(2026);
        $second = $service->backfillExtendedHistorical(2026);

        $this->assertSame(1, $first['candidates']);
        $this->assertSame(1, $first['updated']);
        $this->assertSame(1, $second['candidates']);
        $this->assertSame(1, $second['updated']);

        // Exactly one row in DB
        $this->assertSame(
            1,
            MatchStatistic::where('match_id', $match->id)->where('data_source_id', $this->ds->id)->count(),
        );
    }

    public function test_extended_backfill_error_on_one_fixture_does_not_block_others(): void
    {
        $match1 = $this->makeFinishedMatch(9310);
        $match2 = $this->makeFinishedMatch(9311);

        Http::fake([
            '*fixtures/statistics*' => Http::sequence()
                ->push(null, 500)
                ->push($this->extendedStatsResponse(), 200),
        ]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->backfillExtendedHistorical(2026);

        $this->assertSame(2, $result['candidates']);
        $this->assertSame(1, $result['api_calls']); // HTTP failures don't count: exception thrown before return
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['updated']);
    }

    public function test_extended_backfill_no_season_found(): void
    {
        $result = app(ApiFootballMatchStatisticsSyncService::class)->backfillExtendedHistorical(1899);

        $this->assertSame('no_season_found', $result['status']);
        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
    }

    public function test_extended_backfill_excludes_matches_from_different_season(): void
    {
        // Match lives in the 2026 season (year_start=2026), not 2025
        $this->makeFinishedMatch(9320);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->backfillExtendedHistorical(2025);

        $this->assertSame('no_season_found', $result['status']);
        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);

        Http::assertNothingSent();
    }

    public function test_extended_backfill_command_requires_season_option(): void
    {
        $this->artisan('robetting:backfill-extended-statistics')
            ->assertExitCode(\Illuminate\Console\Command::FAILURE);
    }

    public function test_extended_backfill_command_delegates_to_service(): void
    {
        $this->makeFinishedMatch(9330);

        Http::fake(['*fixtures/statistics*' => Http::response($this->extendedStatsResponse(), 200)]);

        $this->artisan('robetting:backfill-extended-statistics --season=2026')
            ->assertExitCode(\Illuminate\Console\Command::SUCCESS);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Fake statistics response: home team ext_id=505 (home), ext_id=489 (away). */
    private function statsResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 2,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                [
                    'team'       => ['id' => (int) self::HOME_EXT_ID, 'name' => 'Inter'],
                    'statistics' => [
                        ['type' => 'Total Shots',   'value' => 12],
                        ['type' => 'Shots on Goal', 'value' => 5],
                        ['type' => 'Fouls',         'value' => 11],
                        ['type' => 'Corner Kicks',  'value' => 6],
                        ['type' => 'Yellow Cards',  'value' => 1],
                        ['type' => 'Red Cards',     'value' => 0],
                    ],
                ],
                [
                    'team'       => ['id' => (int) self::AWAY_EXT_ID, 'name' => 'Milan'],
                    'statistics' => [
                        ['type' => 'Total Shots',   'value' => 9],
                        ['type' => 'Shots on Goal', 'value' => 3],
                        ['type' => 'Fouls',         'value' => 14],
                        ['type' => 'Corner Kicks',  'value' => 4],
                        ['type' => 'Yellow Cards',  'value' => 2],
                        ['type' => 'Red Cards',     'value' => 0],
                    ],
                ],
            ],
        ];
    }

    /**
     * Extended fake response with all mapped metrics + optional unknown metric.
     * Possession and Passes % are delivered as percentage strings ("55%", "87%").
     */
    private function extendedStatsResponse(bool $withUnknownMetric = false): array
    {
        $homeStats = [
            ['type' => 'Total Shots',      'value' => 12],
            ['type' => 'Shots on Goal',    'value' => 5],
            ['type' => 'Shots off Goal',   'value' => 4],
            ['type' => 'Blocked Shots',    'value' => 2],
            ['type' => 'Shots insidebox',  'value' => 8],
            ['type' => 'Shots outsidebox', 'value' => 4],
            ['type' => 'Fouls',            'value' => 11],
            ['type' => 'Corner Kicks',     'value' => 6],
            ['type' => 'Offsides',         'value' => 2],
            ['type' => 'Ball Possession',  'value' => '55%'],
            ['type' => 'Yellow Cards',     'value' => 1],
            ['type' => 'Red Cards',        'value' => 0],
            ['type' => 'Goalkeeper Saves', 'value' => 3],
            ['type' => 'Total passes',     'value' => 512],
            ['type' => 'Passes accurate',  'value' => 447],
            ['type' => 'Passes %',         'value' => '87%'],
        ];

        $awayStats = [
            ['type' => 'Total Shots',      'value' => 9],
            ['type' => 'Shots on Goal',    'value' => 3],
            ['type' => 'Shots off Goal',   'value' => 3],
            ['type' => 'Blocked Shots',    'value' => 1],
            ['type' => 'Shots insidebox',  'value' => 5],
            ['type' => 'Shots outsidebox', 'value' => 4],
            ['type' => 'Fouls',            'value' => 14],
            ['type' => 'Corner Kicks',     'value' => 4],
            ['type' => 'Offsides',         'value' => 1],
            ['type' => 'Ball Possession',  'value' => '45%'],
            ['type' => 'Yellow Cards',     'value' => 2],
            ['type' => 'Red Cards',        'value' => 0],
            ['type' => 'Goalkeeper Saves', 'value' => 5],
            ['type' => 'Total passes',     'value' => 389],
            ['type' => 'Passes accurate',  'value' => 321],
            ['type' => 'Passes %',         'value' => '82%'],
        ];

        if ($withUnknownMetric) {
            $homeStats[] = ['type' => 'Future Unknown Metric', 'value' => 42];
            $awayStats[] = ['type' => 'Future Unknown Metric', 'value' => 17];
        }

        return [
            'errors'   => [],
            'results'  => 2,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                ['team' => ['id' => (int) self::HOME_EXT_ID, 'name' => 'Inter'], 'statistics' => $homeStats],
                ['team' => ['id' => (int) self::AWAY_EXT_ID, 'name' => 'Milan'], 'statistics' => $awayStats],
            ],
        ];
    }
}
