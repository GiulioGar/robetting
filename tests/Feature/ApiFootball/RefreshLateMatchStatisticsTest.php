<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefreshLateMatchStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $competition;
    private Season $season;
    private Team $homeTeam;
    private Team $awayTeam;

    private const HOME_EXT_ID = '505';
    private const AWAY_EXT_ID = '489';

    private int $extIdCounter = 9700;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);

        config([
            'api-football.api_key'                      => 'test-key',
            'api-football.base_url'                     => 'https://v3.football.api-sports.io',
            'api-football.late_stats_initial_delay_days' => 2,
            'api-football.late_stats_retry_days'         => 7,
            'api-football.late_stats_max_age_days'       => 90,
        ]);

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

    // =========================================================================
    // 1. No candidates when all advanced stats are complete
    // =========================================================================

    public function test_no_candidates_when_all_advanced_stats_complete(): void
    {
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 5);

        // All four advanced fields present — NOT a candidate
        MatchStatistic::create([
            'match_id'                  => $match->id,
            'data_source_id'            => $this->ds->id,
            'fetched_at'                => now()->subDays(5),
            'stats_schema_version'      => ApiFootballMatchStatisticsSyncService::CURRENT_STATS_SCHEMA_VERSION,
            'home_expected_goals'       => 1.08,
            'away_expected_goals'       => 2.79,
            'home_goals_prevented'      => -1.34,
            'away_goals_prevented'      => 0.89,
        ]);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        Http::assertNothingSent();
    }

    // =========================================================================
    // 2. xG + goals_prevented recovered: both recovered → correct counters
    // =========================================================================

    public function test_xg_and_goals_prevented_recovered_increments_both_counters(): void
    {
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 10);
        $stat  = $this->makeV2StatMissingAdvanced($match);

        Http::fake(['*fixtures/statistics*' => Http::response($this->fullAdvancedApiResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(1, $result['xg_recovered']);
        $this->assertSame(1, $result['goals_prevented_recovered']);
        $this->assertSame(0, $result['still_missing_advanced']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['failed']);

        $stat->refresh();
        $this->assertNotNull($stat->home_expected_goals);
        $this->assertNotNull($stat->away_expected_goals);
        $this->assertNotNull($stat->home_goals_prevented);
        $this->assertNotNull($stat->away_goals_prevented);
        $this->assertNotNull($stat->advanced_stats_checked_at);
    }

    // =========================================================================
    // 3. Payload without any advanced stats → still_missing_advanced++
    // =========================================================================

    public function test_valid_payload_without_advanced_stats_counts_as_still_missing(): void
    {
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 10);
        $stat  = $this->makeV2StatMissingAdvanced($match);

        Http::fake(['*fixtures/statistics*' => Http::response($this->noAdvancedApiResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(0, $result['xg_recovered']);
        $this->assertSame(0, $result['goals_prevented_recovered']);
        $this->assertSame(1, $result['still_missing_advanced']);
        $this->assertSame(1, $result['updated']);

        $stat->refresh();
        $this->assertNull($stat->home_expected_goals);
        $this->assertNull($stat->home_goals_prevented);
        $this->assertNotNull($stat->advanced_stats_checked_at);
    }

    // =========================================================================
    // 4. Empty API response [] → checked_at set, existing shots preserved
    // =========================================================================

    public function test_empty_api_response_sets_checked_at_and_preserves_shots(): void
    {
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 10);
        $stat  = $this->makeV2StatMissingAdvanced($match, shotCount: 14);

        Http::fake(['*fixtures/statistics*' => Http::response(
            ['errors' => [], 'results' => 0, 'response' => []],
            200,
        )]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(0, $result['xg_recovered']);
        $this->assertSame(0, $result['goals_prevented_recovered']);
        $this->assertSame(1, $result['still_missing_advanced']);
        $this->assertSame(1, $result['updated']);

        $stat->refresh();
        $this->assertNull($stat->home_expected_goals, 'xG must remain null after empty response');
        $this->assertSame(14, $stat->home_shots, 'existing shots must NOT be wiped by empty response');
        $this->assertNotNull($stat->advanced_stats_checked_at);
    }

    // =========================================================================
    // 5. HTTP failure → failed++, advanced_stats_checked_at not updated
    // =========================================================================

    public function test_http_failure_counts_as_failed_does_not_set_checked_at(): void
    {
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 10);
        $stat  = $this->makeV2StatMissingAdvanced($match);

        Http::fake(['*fixtures/statistics*' => Http::response(null, 500)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['failed']);

        $stat->refresh();
        $this->assertNull($stat->advanced_stats_checked_at, 'checked_at must NOT be set on HTTP failure');
    }

    // =========================================================================
    // 6. Retry interval gate — recently re-checked (3d < 7d) → 0 candidates
    // =========================================================================

    public function test_retry_interval_gate_blocks_recently_checked_rows(): void
    {
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 10);
        // checked_at 3 days ago: retry arm, 3 < 7 → blocked
        $this->makeV2StatMissingAdvanced($match, checkedAt: now()->subDays(3));

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        Http::assertNothingSent();
    }

    // =========================================================================
    // 7. Retry interval gate — expired re-check (8d > 7d) → candidate
    // =========================================================================

    public function test_retry_interval_gate_allows_expired_rows(): void
    {
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 10);
        // checked_at 8 days ago: retry arm, 8 > 7 → allowed
        $this->makeV2StatMissingAdvanced($match, checkedAt: now()->subDays(8));

        Http::fake(['*fixtures/statistics*' => Http::response($this->fullAdvancedApiResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['api_calls']);
    }

    // =========================================================================
    // 8. Max-age gate — kickoff 100 days ago (> 90d window) → 0 candidates
    // =========================================================================

    public function test_max_age_excludes_old_matches(): void
    {
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 100); // 100 > 90 → excluded
        $this->makeV2StatMissingAdvanced($match);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        Http::assertNothingSent();
    }

    // =========================================================================
    // 9. Max-age gate — kickoff 80 days ago (< 90d window) → candidate
    // =========================================================================

    public function test_max_age_includes_match_within_window(): void
    {
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 80); // 80 < 90 → included
        $this->makeV2StatMissingAdvanced($match);

        Http::fake(['*fixtures/statistics*' => Http::response($this->fullAdvancedApiResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['api_calls']);
    }

    // =========================================================================
    // 10. Historical season excluded when no seasonYear arg
    // =========================================================================

    public function test_historical_season_excluded_when_no_season_arg(): void
    {
        $historicalSeason = Season::create([
            'competition_id' => $this->competition->id,
            'name'           => '2024/25',
            'year_start'     => 2024,
            'year_end'       => 2025,
            'is_current'     => false,
        ]);

        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $historicalSeason->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subDays(10),
            'status'         => 'finished',
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => (string) $this->nextExtId(),
            'external_name'  => null,
        ]);

        $this->makeV2StatMissingAdvanced($match);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
    }

    // =========================================================================
    // 11. Explicit seasonYear includes a non-current season
    // =========================================================================

    public function test_explicit_season_year_includes_non_current_season(): void
    {
        $season2025 = Season::create([
            'competition_id' => $this->competition->id,
            'name'           => '2025/26',
            'year_start'     => 2025,
            'year_end'       => 2026,
            'is_current'     => false,
        ]);

        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $season2025->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subDays(20),
            'status'         => 'finished',
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => (string) $this->nextExtId(),
            'external_name'  => null,
        ]);

        $this->makeV2StatMissingAdvanced($match);

        Http::fake(['*fixtures/statistics*' => Http::response($this->fullAdvancedApiResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats(2025);

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(1, $result['xg_recovered']);
        $this->assertSame(1, $result['goals_prevented_recovered']);
    }

    // =========================================================================
    // 12. Initial delay — recently fetched (today) blocks enrichment
    // =========================================================================

    public function test_initial_delay_blocks_recently_fetched_row(): void
    {
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 5);

        // fetched_at = today (0 days < initial_delay=2) + never enrichment-checked → blocked
        $this->makeV2StatMissingAdvanced($match, fetchedAt: now(), checkedAt: null);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(0, $result['candidates'], 'row fetched today must not be an enrichment candidate');
        $this->assertSame(0, $result['api_calls']);
        Http::assertNothingSent();
    }

    // =========================================================================
    // 13. Initial delay — fetched 3 days ago (> 2d delay) → candidate
    // =========================================================================

    public function test_initial_delay_allows_row_fetched_past_delay(): void
    {
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 5);

        // fetched_at = 3 days ago (3 > initial_delay=2) + never enrichment-checked → allowed
        $this->makeV2StatMissingAdvanced($match, fetchedAt: now()->subDays(3), checkedAt: null);

        Http::fake(['*fixtures/statistics*' => Http::response($this->fullAdvancedApiResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(1, $result['candidates'], 'row fetched 3 days ago must be an enrichment candidate');
        $this->assertSame(1, $result['api_calls']);
    }

    // =========================================================================
    // 14. goals_prevented missing (xG already present) → still a candidate
    // =========================================================================

    public function test_goals_prevented_missing_is_a_candidate_even_when_xg_present(): void
    {
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 10);

        // xG complete, goals_prevented null — must still be a candidate
        MatchStatistic::create([
            'match_id'                  => $match->id,
            'data_source_id'            => $this->ds->id,
            'fetched_at'                => now()->subDays(10),
            'stats_schema_version'      => ApiFootballMatchStatisticsSyncService::CURRENT_STATS_SCHEMA_VERSION,
            'home_expected_goals'       => 1.08,
            'away_expected_goals'       => 2.79,
            'home_goals_prevented'      => null,
            'away_goals_prevented'      => null,
            'advanced_stats_checked_at' => null,
        ]);

        Http::fake(['*fixtures/statistics*' => Http::response($this->fullAdvancedApiResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(1, $result['candidates'], 'missing goals_prevented must make the row a candidate');
        $this->assertSame(1, $result['api_calls']);
    }

    // =========================================================================
    // 15. Refresh recovers only goals_prevented (xG was already present)
    // =========================================================================

    public function test_refresh_recovers_goals_prevented_when_xg_already_present(): void
    {
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 10);

        $stat = MatchStatistic::create([
            'match_id'                  => $match->id,
            'data_source_id'            => $this->ds->id,
            'fetched_at'                => now()->subDays(10),
            'stats_schema_version'      => ApiFootballMatchStatisticsSyncService::CURRENT_STATS_SCHEMA_VERSION,
            'home_expected_goals'       => 1.08,
            'away_expected_goals'       => 2.79,
            'home_goals_prevented'      => null,   // missing
            'away_goals_prevented'      => null,   // missing
            'advanced_stats_checked_at' => null,
        ]);

        Http::fake(['*fixtures/statistics*' => Http::response($this->fullAdvancedApiResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(1, $result['candidates']);
        // xG was already complete → xg_recovered is determined by what the *response* contains
        $this->assertSame(1, $result['xg_recovered'],              'response contains xG → xg_recovered');
        $this->assertSame(1, $result['goals_prevented_recovered'], 'response contains GP → goals_prevented_recovered');
        $this->assertSame(0, $result['still_missing_advanced'],    'both advanced stats now complete');

        $stat->refresh();
        $this->assertNotNull($stat->home_goals_prevented);
        $this->assertNotNull($stat->away_goals_prevented);
    }

    // =========================================================================
    // 16. Command delegation
    // =========================================================================

    public function test_command_delegates_to_service_and_exits_success(): void
    {
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 5);
        $this->makeV2StatMissingAdvanced($match);

        Http::fake(['*fixtures/statistics*' => Http::response($this->fullAdvancedApiResponse(), 200)]);

        $this->artisan('robetting:refresh-late-match-statistics')
            ->assertExitCode(\Illuminate\Console\Command::SUCCESS);
    }

    // =========================================================================
    // 17. null fetched_at → kickoff_at used as reference (candidate)
    // =========================================================================

    public function test_null_fetched_at_uses_kickoff_as_reference_candidate(): void
    {
        // kickoff 3 days ago > initial_delay=2 → COALESCE(null, kickoff) passes the gate
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 3);
        $this->makeV2StatNullFetchedAt($match);

        Http::fake(['*fixtures/statistics*' => Http::response($this->fullAdvancedApiResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(1, $result['candidates'], 'null fetched_at + old kickoff → candidate');
        $this->assertSame(1, $result['api_calls']);
    }

    // =========================================================================
    // 18. null fetched_at → kickoff_at used as reference (blocked, too recent)
    // =========================================================================

    public function test_null_fetched_at_uses_kickoff_as_reference_blocked(): void
    {
        // kickoff today = 0 days ago < initial_delay=2 → COALESCE(null, kickoff) fails the gate
        $match = $this->makeFinishedMatch(kickoffDaysAgo: 0);
        $this->makeV2StatNullFetchedAt($match);

        Http::fake(['*fixtures/statistics*' => Http::response($this->fullAdvancedApiResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->refreshLateStats();

        $this->assertSame(0, $result['candidates'], 'null fetched_at + kickoff today → blocked');
        $this->assertSame(0, $result['api_calls']);
        Http::assertNothingSent();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Finished match with an API-Football external ID in the current (is_current=true) season. */
    private function makeFinishedMatch(int $kickoffDaysAgo = 5): FootballMatch
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subDays($kickoffDaysAgo),
            'status'         => 'finished',
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => (string) $this->nextExtId(),
            'external_name'  => null,
        ]);

        return $match;
    }

    /**
     * v2 MatchStatistic row with all advanced stats null.
     * Default: fetched 10 days ago (past initial_delay=2) + never enrichment-checked.
     */
    private function makeV2StatMissingAdvanced(
        FootballMatch $match,
        ?\Carbon\Carbon $fetchedAt = null,
        ?\Carbon\Carbon $checkedAt = null,
        int $shotCount = 12,
    ): MatchStatistic {
        return MatchStatistic::create([
            'match_id'                  => $match->id,
            'data_source_id'            => $this->ds->id,
            'fetched_at'                => $fetchedAt ?? now()->subDays(10),
            'stats_schema_version'      => ApiFootballMatchStatisticsSyncService::CURRENT_STATS_SCHEMA_VERSION,
            'home_shots'                => $shotCount,
            'away_shots'                => 9,
            'home_expected_goals'       => null,
            'away_expected_goals'       => null,
            'home_goals_prevented'      => null,
            'away_goals_prevented'      => null,
            'advanced_stats_checked_at' => $checkedAt,
        ]);
    }

    /** v2 row with fetched_at explicitly NULL — tests the COALESCE(fetched_at, kickoff_at) fallback. */
    private function makeV2StatNullFetchedAt(FootballMatch $match): MatchStatistic
    {
        return MatchStatistic::create([
            'match_id'                  => $match->id,
            'data_source_id'            => $this->ds->id,
            'fetched_at'                => null,
            'stats_schema_version'      => ApiFootballMatchStatisticsSyncService::CURRENT_STATS_SCHEMA_VERSION,
            'home_shots'                => 12,
            'away_shots'                => 9,
            'home_expected_goals'       => null,
            'away_expected_goals'       => null,
            'home_goals_prevented'      => null,
            'away_goals_prevented'      => null,
            'advanced_stats_checked_at' => null,
        ]);
    }

    private function nextExtId(): int
    {
        return $this->extIdCounter++;
    }

    /**
     * API response with all four advanced stats present.
     * home xG="1.08" (string), away xG=2.79 (float); home gp="-1.34", away gp=0.89.
     */
    private function fullAdvancedApiResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 2,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                [
                    'team'       => ['id' => (int) self::HOME_EXT_ID, 'name' => 'Inter'],
                    'statistics' => [
                        ['type' => 'Total Shots',     'value' => 12],
                        ['type' => 'Shots on Goal',   'value' => 5],
                        ['type' => 'Fouls',           'value' => 11],
                        ['type' => 'Corner Kicks',    'value' => 6],
                        ['type' => 'Yellow Cards',    'value' => 1],
                        ['type' => 'Red Cards',       'value' => 0],
                        ['type' => 'expected_goals',  'value' => '1.08'],
                        ['type' => 'goals_prevented', 'value' => '-1.34'],
                    ],
                ],
                [
                    'team'       => ['id' => (int) self::AWAY_EXT_ID, 'name' => 'Milan'],
                    'statistics' => [
                        ['type' => 'Total Shots',     'value' => 9],
                        ['type' => 'Shots on Goal',   'value' => 3],
                        ['type' => 'Fouls',           'value' => 14],
                        ['type' => 'Corner Kicks',    'value' => 4],
                        ['type' => 'Yellow Cards',    'value' => 2],
                        ['type' => 'Red Cards',       'value' => 0],
                        ['type' => 'expected_goals',  'value' => 2.79],
                        ['type' => 'goals_prevented', 'value' => 0.89],
                    ],
                ],
            ],
        ];
    }

    /**
     * API response with full basic stats but no advanced fields (e.g. Bundesliga before retroactive fill).
     */
    private function noAdvancedApiResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 2,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                [
                    'team'       => ['id' => (int) self::HOME_EXT_ID, 'name' => 'Inter'],
                    'statistics' => [
                        ['type' => 'Total Shots',  'value' => 14],
                        ['type' => 'Shots on Goal', 'value' => 6],
                        ['type' => 'Fouls',         'value' => 10],
                        ['type' => 'Corner Kicks',  'value' => 7],
                        ['type' => 'Yellow Cards',  'value' => 1],
                        ['type' => 'Red Cards',     'value' => 0],
                        // expected_goals and goals_prevented intentionally absent
                    ],
                ],
                [
                    'team'       => ['id' => (int) self::AWAY_EXT_ID, 'name' => 'Milan'],
                    'statistics' => [
                        ['type' => 'Total Shots',  'value' => 8],
                        ['type' => 'Shots on Goal', 'value' => 2],
                        ['type' => 'Fouls',         'value' => 13],
                        ['type' => 'Corner Kicks',  'value' => 3],
                        ['type' => 'Yellow Cards',  'value' => 2],
                        ['type' => 'Red Cards',     'value' => 0],
                    ],
                ],
            ],
        ];
    }
}
