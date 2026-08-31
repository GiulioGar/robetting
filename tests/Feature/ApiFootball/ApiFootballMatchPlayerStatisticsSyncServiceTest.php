<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\MatchPlayerStatistic;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballMatchPlayerStatisticsSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Player statistics sync via API-Football /fixtures/players.
 *
 * Sentinel: player_stats_fetched_at on matches (not per row) — one call covers both teams.
 * Set on any valid 2xx response (including []). Not set on HTTP error or all-unmapped payload.
 * Idempotency: UNIQUE(match_id, player_id, data_source_id) + updateOrCreate.
 */
class ApiFootballMatchPlayerStatisticsSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOME_API_ID = 505;
    private const AWAY_API_ID = 489;
    private const FIXTURE_EXT_ID = '654321';

    private DataSource   $ds;
    private Competition  $competition;
    private Season       $season;
    private Team         $homeTeam;
    private Team         $awayTeam;
    private FootballMatch $match;

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

        $this->homeTeam = Team::create(['name' => 'Internazionale', 'type' => 'club', 'is_active' => true]);
        $this->awayTeam = Team::create(['name' => 'AC Milan',       'type' => 'club', 'is_active' => true]);

        TeamExternalId::create([
            'team_id'        => $this->homeTeam->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => (string) self::HOME_API_ID,
            'external_name'  => 'Internazionale',
        ]);
        TeamExternalId::create([
            'team_id'        => $this->awayTeam->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => (string) self::AWAY_API_ID,
            'external_name'  => 'AC Milan',
        ]);

        $this->match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHours(3),
            'status'         => 'finished',
            'definitive_at'  => now()->subHours(2),
            'home_score_ft'  => 2,
            'away_score_ft'  => 1,
        ]);

        MatchExternalId::create([
            'match_id'       => $this->match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => self::FIXTURE_EXT_ID,
        ]);
    }

    // =========================================================================
    // 1. Parsing
    // =========================================================================

    public function test_parsing_complete_payload_stores_all_fields(): void
    {
        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$this->playerItem(19241, 'L. Martinez')], []),
            200,
        )]);

        $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $stat = MatchPlayerStatistic::firstOrFail();

        // utilization
        $this->assertSame(90,   $stat->games_minutes);
        $this->assertSame(10,   $stat->games_number);
        $this->assertSame('F',  $stat->games_position);
        $this->assertEqualsWithDelta(7.5, $stat->games_rating, 0.001);
        $this->assertFalse($stat->games_captain);
        $this->assertFalse($stat->games_substitute);

        // shots
        $this->assertSame(3, $stat->shots_total);
        $this->assertSame(2, $stat->shots_on_target);

        // goals
        $this->assertSame(1, $stat->goals_scored);
        $this->assertSame(0, $stat->goals_conceded);

        // passes
        $this->assertSame(45, $stat->passes_total);
        $this->assertSame(2,  $stat->passes_key);
        $this->assertEqualsWithDelta(87.0, $stat->passes_accuracy, 0.001);

        // tackles
        $this->assertSame(1, $stat->tackles_total);

        // duels
        $this->assertSame(8, $stat->duels_total);
        $this->assertSame(4, $stat->duels_won);

        // dribbling
        $this->assertSame(3, $stat->dribbles_attempts);
        $this->assertSame(2, $stat->dribbles_success);

        // fouls
        $this->assertSame(1, $stat->fouls_drawn);
        $this->assertSame(2, $stat->fouls_committed);

        // discipline
        $this->assertSame(0, $stat->cards_yellow);
        $this->assertSame(0, $stat->cards_red);

        // penalties
        $this->assertNull($stat->penalty_won);
        $this->assertNull($stat->penalty_committed);
        $this->assertSame(0, $stat->penalty_scored);
        $this->assertSame(0, $stat->penalty_missed);
        $this->assertNull($stat->penalty_saved);
    }

    public function test_both_teams_processed_with_one_api_call(): void
    {
        Http::fake(['*fixtures/players*' => Http::response(
            $this->response(
                [$this->playerItem(19241, 'L. Martinez')],
                [$this->playerItem(9895,  'R. Lukaku')],
            ),
            200,
        )]);

        $result = $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(2, $result['players_count']);
        $this->assertSame(2, MatchPlayerStatistic::count());

        // Each row tied to its team
        $this->assertDatabaseHas('match_player_statistics', ['team_id' => $this->homeTeam->id]);
        $this->assertDatabaseHas('match_player_statistics', ['team_id' => $this->awayTeam->id]);
    }

    // =========================================================================
    // 2. Player resolution
    // =========================================================================

    public function test_existing_player_resolved_from_player_external_ids(): void
    {
        $player = Player::create(['name' => 'Lautaro Martinez']);
        PlayerExternalId::create([
            'player_id'      => $player->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '19241',
            'external_name'  => 'L. Martinez',
        ]);

        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$this->playerItem(19241, 'L. Martinez')], []),
            200,
        )]);

        $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        // No new player created
        $this->assertSame(1, Player::count());
        $stat = MatchPlayerStatistic::firstOrFail();
        $this->assertSame($player->id, $stat->player_id);
    }

    public function test_missing_player_created_minimally_without_extra_api_call(): void
    {
        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$this->playerItem(99999, 'Unknown Player')], []),
            200,
        )]);

        $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        // Exactly 1 API call (to /fixtures/players), no extra player-lookup calls
        Http::assertSentCount(1);

        $this->assertSame(1, Player::count());
        $player = Player::firstOrFail();
        $this->assertSame('Unknown Player', $player->name);
        $this->assertNull($player->birth_date);
        $this->assertNull($player->height_cm);

        $this->assertDatabaseHas('player_external_ids', [
            'external_id'    => '99999',
            'data_source_id' => $this->ds->id,
        ]);
    }

    // =========================================================================
    // 3. Team mapping
    // =========================================================================

    public function test_team_mapping_missing_for_one_team_skips_that_team(): void
    {
        // Remove away team mapping
        TeamExternalId::where('team_id', $this->awayTeam->id)->delete();

        Http::fake(['*fixtures/players*' => Http::response(
            $this->response(
                [$this->playerItem(19241, 'L. Martinez')],
                [$this->playerItem(9895,  'R. Lukaku')],
            ),
            200,
        )]);

        $result = $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $this->assertSame('synced', $result['outcome']);
        $this->assertSame(1, $result['players_count']); // only home team
        $this->assertSame(1, MatchPlayerStatistic::count());
        // Sentinel IS set (home team was processed)
        $this->match->refresh();
        $this->assertNotNull($this->match->player_stats_fetched_at);
    }

    public function test_all_teams_unmapped_leaves_sentinel_null(): void
    {
        TeamExternalId::query()->delete(); // remove all team external IDs

        Http::fake(['*fixtures/players*' => Http::response(
            $this->response(
                [$this->playerItem(19241, 'L. Martinez')],
                [$this->playerItem(9895,  'R. Lukaku')],
            ),
            200,
        )]);

        $result = $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $this->assertSame('unparsable', $result['outcome']);
        $this->assertSame(0, MatchPlayerStatistic::count());
        $this->match->refresh();
        $this->assertNull($this->match->player_stats_fetched_at);
    }

    public function test_mixed_payload_saves_valid_team_sets_sentinel(): void
    {
        // Away team unmapped; home team maps and saves 1 player
        TeamExternalId::where('team_id', $this->awayTeam->id)->delete();

        Http::fake(['*fixtures/players*' => Http::response(
            $this->response(
                [$this->playerItem(19241, 'L. Martinez')],
                [$this->playerItem(9895,  'R. Lukaku')],
            ),
            200,
        )]);

        $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $this->assertSame(1, MatchPlayerStatistic::count());
        $this->match->refresh();
        $this->assertNotNull($this->match->player_stats_fetched_at);
    }

    // =========================================================================
    // 4. Null preservation
    // =========================================================================

    public function test_null_values_are_preserved_not_converted_to_zero(): void
    {
        $item                               = $this->playerItem(19241, 'L. Martinez');
        $item['statistics'][0]['goals']     = ['total' => null, 'conceded' => null, 'assists' => null, 'saves' => null];
        $item['statistics'][0]['tackles']   = ['total' => null, 'blocks' => null, 'interceptions' => null];
        $item['statistics'][0]['dribbles']  = ['attempts' => null, 'success' => null, 'past' => null];
        $item['statistics'][0]['penalty']   = ['won' => null, 'commited' => null, 'scored' => null, 'missed' => null, 'saved' => null];

        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$item], []),
            200,
        )]);

        $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $stat = MatchPlayerStatistic::firstOrFail();
        $this->assertNull($stat->goals_scored);
        $this->assertNull($stat->goals_conceded);
        $this->assertNull($stat->goals_assists);
        $this->assertNull($stat->goals_saves);
        $this->assertNull($stat->tackles_total);
        $this->assertNull($stat->dribbles_attempts);
        $this->assertNull($stat->penalty_committed);
        $this->assertNull($stat->penalty_saved);
    }

    // =========================================================================
    // 5. Rating and accuracy parsing
    // =========================================================================

    public function test_games_rating_parsed_from_string(): void
    {
        $item                                      = $this->playerItem(19241, 'L. Martinez');
        $item['statistics'][0]['games']['rating']  = '8.25';

        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$item], []),
            200,
        )]);

        $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $stat = MatchPlayerStatistic::firstOrFail();
        $this->assertEqualsWithDelta(8.25, $stat->games_rating, 0.001);
    }

    public function test_games_rating_null_on_invalid_or_null_value(): void
    {
        $item                                     = $this->playerItem(19241, 'L. Martinez');
        $item['statistics'][0]['games']['rating'] = null;

        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$item], []),
            200,
        )]);

        $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $this->assertNull(MatchPlayerStatistic::value('games_rating'));
    }

    public function test_passes_accuracy_parsed_as_integer_string(): void
    {
        // API returns "87" (integer string), not "87%"
        $item                                          = $this->playerItem(19241, 'L. Martinez');
        $item['statistics'][0]['passes']['accuracy']   = '87';

        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$item], []),
            200,
        )]);

        $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $stat = MatchPlayerStatistic::firstOrFail();
        $this->assertEqualsWithDelta(87.0, $stat->passes_accuracy, 0.001);
    }

    public function test_passes_accuracy_also_accepts_bare_integer(): void
    {
        $item                                        = $this->playerItem(19241, 'L. Martinez');
        $item['statistics'][0]['passes']['accuracy'] = 92;

        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$item], []),
            200,
        )]);

        $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $this->assertEqualsWithDelta(92.0, MatchPlayerStatistic::value('passes_accuracy'), 0.001);
    }

    // =========================================================================
    // 6. penalty.commited (API typo — single t)
    // =========================================================================

    public function test_penalty_commited_single_t_maps_to_penalty_committed_column(): void
    {
        $item = $this->playerItem(19241, 'L. Martinez');
        $item['statistics'][0]['penalty'] = [
            'won'      => null,
            'commited' => 1,   // single t — actual API field name
            'scored'   => 1,
            'missed'   => 0,
            'saved'    => null,
        ];

        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$item], []),
            200,
        )]);

        $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $stat = MatchPlayerStatistic::firstOrFail();
        $this->assertSame(1, $stat->penalty_committed); // column spelled correctly
        $this->assertSame(1, $stat->penalty_scored);
    }

    // =========================================================================
    // 7. raw_stats
    // =========================================================================

    public function test_raw_stats_preserves_unknown_fields(): void
    {
        $item                                             = $this->playerItem(19241, 'L. Martinez');
        $item['statistics'][0]['future_metric_xyz']       = ['value' => 42];

        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$item], []),
            200,
        )]);

        $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $stat = MatchPlayerStatistic::firstOrFail();
        $this->assertNotNull($stat->raw_stats);
        $this->assertArrayHasKey('future_metric_xyz', $stat->raw_stats);
        $this->assertSame(42, $stat->raw_stats['future_metric_xyz']['value']);
    }

    // =========================================================================
    // 8. Idempotency
    // =========================================================================

    public function test_second_sync_updates_same_rows_without_duplicates(): void
    {
        $body = $this->response([$this->playerItem(19241, 'L. Martinez')], []);

        Http::fake(['*fixtures/players*' => Http::sequence()
            ->push($body, 200)
            ->push($body, 200),
        ]);

        $service = $this->service();
        $service->syncSingle($this->match, self::FIXTURE_EXT_ID);

        // Reset sentinel so second syncSingle doesn't skip
        $this->match->update(['player_stats_fetched_at' => null]);

        $service->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $this->assertSame(1, MatchPlayerStatistic::count());
    }

    // =========================================================================
    // 9. Sentinel semantics
    // =========================================================================

    public function test_post_match_sync_sets_player_stats_fetched_at(): void
    {
        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$this->playerItem(19241, 'L. Martinez')], []),
            200,
        )]);

        $result = $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $this->assertSame('synced', $result['outcome']);
        $this->match->refresh();
        $this->assertNotNull($this->match->player_stats_fetched_at);
    }

    public function test_empty_response_post_match_sets_sentinel(): void
    {
        Http::fake(['*fixtures/players*' => Http::response(
            ['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []],
            200,
        )]);

        $result = $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $this->assertSame('empty', $result['outcome']);
        $this->match->refresh();
        $this->assertNotNull($this->match->player_stats_fetched_at);
        $this->assertSame(0, MatchPlayerStatistic::count());
    }

    public function test_http_error_leaves_sentinel_null(): void
    {
        Http::fake(['*fixtures/players*' => Http::response([], 500)]);

        $threw = false;
        try {
            $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);
        } catch (\App\Services\DataSources\ApiFootball\ApiFootballException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected ApiFootballException was not thrown');
        $this->match->refresh();
        $this->assertNull($this->match->player_stats_fetched_at);
    }

    public function test_live_sync_updates_stats_but_does_not_set_sentinel(): void
    {
        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$this->playerItem(19241, 'L. Martinez')], []),
            200,
        )]);

        $liveMatch = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subMinutes(45),
            'status'         => 'live',
        ]);
        MatchExternalId::create([
            'match_id'       => $liveMatch->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '999888',
        ]);

        $result = $this->service()->syncLiveSingle($liveMatch, '999888');

        $this->assertSame('synced', $result['outcome']);
        $this->assertSame(1, MatchPlayerStatistic::count());
        $liveMatch->refresh();
        $this->assertNull($liveMatch->player_stats_fetched_at);
    }

    public function test_live_sync_does_not_clear_existing_sentinel(): void
    {
        $alreadySet = now()->subHour();
        $this->match->update(['player_stats_fetched_at' => $alreadySet]);

        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$this->playerItem(19241, 'L. Martinez')], []),
            200,
        )]);

        // Live sync never touches the sentinel
        $this->service()->syncLiveSingle($this->match, self::FIXTURE_EXT_ID);

        $this->match->refresh();
        $this->assertEquals($alreadySet->timestamp, $this->match->player_stats_fetched_at->timestamp);
    }

    public function test_sync_single_skips_when_sentinel_already_set(): void
    {
        $this->match->update(['player_stats_fetched_at' => now()]);

        Http::fake(); // no call should be made

        $result = $this->service()->syncSingle($this->match, self::FIXTURE_EXT_ID);

        $this->assertSame('skipped_complete', $result['outcome']);
        $this->assertSame(0, $result['api_calls']);
        Http::assertNothingSent();
    }

    // =========================================================================
    // 10. syncPending — grace period
    // =========================================================================

    public function test_sync_pending_fetches_match_past_grace_period(): void
    {
        // Match is definitive and past the grace period (definitive_at set 30 min ago)
        // player_stats_fetched_at IS NULL (default from setUp)
        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$this->playerItem(19241, 'L. Martinez')], []),
            200,
        )]);

        $result = $this->service()->syncPending(gracePeriodMinutes: 10);

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['synced']);
        $this->assertSame(1, MatchPlayerStatistic::count());
    }

    public function test_sync_pending_skips_match_within_grace_period(): void
    {
        // definitive_at just 2 minutes ago — still within 10-min grace
        $this->match->update(['definitive_at' => now()->subMinutes(2)]);

        Http::fake();

        $result = $this->service()->syncPending(gracePeriodMinutes: 10);

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
    }

    // =========================================================================
    // 11. syncMissingHistorical — season filter
    // =========================================================================

    public function test_historical_season_filter_targets_correct_year(): void
    {
        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$this->playerItem(19241, 'L. Martinez')], []),
            200,
        )]);

        // Target year_start=2026 — matches our season
        $result = $this->service()->syncMissingHistorical(2026);

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['synced']);
    }

    public function test_historical_wrong_year_finds_no_candidates(): void
    {
        Http::fake();

        $result = $this->service()->syncMissingHistorical(2020); // no season for 2020

        $this->assertSame('no_season_found', $result['status']);
        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
    }

    public function test_historical_second_run_returns_zero_candidates(): void
    {
        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$this->playerItem(19241, 'L. Martinez')], []),
            200,
        )]);

        $service = $this->service();
        $service->syncMissingHistorical(2026); // first run sets sentinel

        Http::fake(); // should not be called again
        $result2 = $service->syncMissingHistorical(2026);

        $this->assertSame(0, $result2['candidates']);
        Http::assertNothingSent();
    }

    // =========================================================================
    // 12. Artisan command
    // =========================================================================

    public function test_artisan_command_delegates_to_service(): void
    {
        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$this->playerItem(19241, 'L. Martinez')], []),
            200,
        )]);

        $this->artisan('robetting:backfill-player-statistics --season=2026')
            ->assertSuccessful();

        $this->assertSame(1, MatchPlayerStatistic::count());
    }

    public function test_artisan_command_without_season_uses_current_seasons(): void
    {
        Http::fake(['*fixtures/players*' => Http::response(
            $this->response([$this->playerItem(19241, 'L. Martinez')], []),
            200,
        )]);

        // Season is_current=true (set in setUp)
        $this->artisan('robetting:backfill-player-statistics')
            ->assertSuccessful();

        $this->assertSame(1, MatchPlayerStatistic::count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): ApiFootballMatchPlayerStatisticsSyncService
    {
        return app(ApiFootballMatchPlayerStatisticsSyncService::class);
    }

    private function response(array $homePlayers, array $awayPlayers): array
    {
        return [
            'errors'   => [],
            'results'  => 2,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                [
                    'team'    => ['id' => self::HOME_API_ID, 'name' => 'Internazionale', 'logo' => ''],
                    'players' => $homePlayers,
                ],
                [
                    'team'    => ['id' => self::AWAY_API_ID, 'name' => 'AC Milan', 'logo' => ''],
                    'players' => $awayPlayers,
                ],
            ],
        ];
    }

    private function playerItem(int $playerId, string $playerName): array
    {
        return [
            'player' => [
                'id'    => $playerId,
                'name'  => $playerName,
                'photo' => "https://media.api-sports.io/football/players/{$playerId}.jpg",
            ],
            'statistics' => [
                [
                    'games' => [
                        'minutes'   => 90,
                        'number'    => 10,
                        'position'  => 'F',
                        'rating'    => '7.5',
                        'captain'   => false,
                        'substitute'=> false,
                    ],
                    'shots'    => ['total' => 3,  'on'  => 2],
                    'goals'    => ['total' => 1,  'conceded' => 0, 'assists' => null, 'saves' => null],
                    'passes'   => ['total' => 45, 'key' => 2,  'accuracy' => '87'],
                    'tackles'  => ['total' => 1,  'blocks' => null, 'interceptions' => null],
                    'duels'    => ['total' => 8,  'won' => 4],
                    'dribbles' => ['attempts' => 3, 'success' => 2, 'past' => null],
                    'fouls'    => ['drawn' => 1, 'committed' => 2],
                    'cards'    => ['yellow' => 0, 'red' => 0],
                    'penalty'  => ['won' => null, 'commited' => null, 'scored' => 0, 'missed' => 0, 'saved' => null],
                ],
            ],
        ];
    }
}
