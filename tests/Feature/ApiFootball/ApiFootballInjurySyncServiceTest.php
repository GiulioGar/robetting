<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\Player;
use App\Models\PlayerAbsence;
use App\Models\PlayerExternalId;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballInjurySyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Injury / availability sync via API-Football /injuries?fixture={id}.
 *
 * Snapshot semantics: each valid response REPLACES the injury state for that fixture+source.
 * Recovered players (absent before, fit now) are deleted from player_absences.
 * Empty response [] = "everyone fit" — deletes all existing absences for the fixture.
 *
 * Sentinels:
 *   injuries_last_attempt_at — updated before every API call, even on errors.
 *   injuries_fetched_at      — updated only on valid 2xx response (including []).
 *
 * Throttle (syncPending only):
 *   > 48 h to kickoff  → max 1 refresh / 24 h
 *   12–48 h to kickoff → max 1 refresh /  6 h
 *   ≤ 12 h to kickoff  → max 1 refresh /  2 h
 */
class ApiFootballInjurySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOME_API_ID      = 505;
    private const AWAY_API_ID      = 489;
    private const FUTURE_EXT_ID    = '111111';
    private const FINISHED_EXT_ID  = '222222';

    private DataSource    $ds;
    private Competition   $competition;
    private Season        $season;
    private Team          $homeTeam;
    private Team          $awayTeam;
    private FootballMatch $futureMatch;   // not_started, kickoff +72h — for pending tests
    private FootballMatch $finishedMatch; // finished, kickoff past — for historical tests

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
            'team_id' => $this->homeTeam->id, 'data_source_id' => $this->ds->id,
            'external_id' => (string) self::HOME_API_ID, 'external_name' => 'Internazionale',
        ]);
        TeamExternalId::create([
            'team_id' => $this->awayTeam->id, 'data_source_id' => $this->ds->id,
            'external_id' => (string) self::AWAY_API_ID, 'external_name' => 'AC Milan',
        ]);

        $this->futureMatch = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->addHours(72),
            'status'         => 'not_started',
        ]);
        MatchExternalId::create([
            'match_id' => $this->futureMatch->id, 'data_source_id' => $this->ds->id,
            'external_id' => self::FUTURE_EXT_ID,
        ]);

        $this->finishedMatch = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHours(3),
            'definitive_at'  => now()->subHours(2),
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);
        MatchExternalId::create([
            'match_id' => $this->finishedMatch->id, 'data_source_id' => $this->ds->id,
            'external_id' => self::FINISHED_EXT_ID,
        ]);
    }

    // =========================================================================
    // 1. Creates absence
    // =========================================================================

    public function test_creates_new_absence_with_type_and_reason(): void
    {
        Http::fake(['*injuries*' => Http::response(
            $this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID, 'Missing Fixture', 'Knee Injury')]),
            200,
        )]);

        $result = $this->service()->syncPending();

        $this->assertSame(1, $result['created']);
        $absence = PlayerAbsence::firstOrFail();
        $this->assertSame('Missing Fixture', $absence->absence_type);
        $this->assertSame('Knee Injury', $absence->reason);
        $this->assertSame($this->homeTeam->id, $absence->team_id);
        $this->assertSame($this->futureMatch->id, $absence->match_id);
    }

    public function test_both_teams_injuries_processed_correctly(): void
    {
        Http::fake(['*injuries*' => Http::response(
            $this->response([
                $this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID),
                $this->injuryItem(9895,  'R. Lukaku',   self::AWAY_API_ID),
            ]),
            200,
        )]);

        $result = $this->service()->syncPending();

        $this->assertSame(2, $result['created']);
        $this->assertSame(2, PlayerAbsence::count());
        $this->assertDatabaseHas('player_absences', ['team_id' => $this->homeTeam->id]);
        $this->assertDatabaseHas('player_absences', ['team_id' => $this->awayTeam->id]);
    }

    // =========================================================================
    // 2. Player resolution
    // =========================================================================

    public function test_existing_player_resolved_from_player_external_ids(): void
    {
        $player = Player::create(['name' => 'Lautaro Martinez']);
        PlayerExternalId::create([
            'player_id' => $player->id, 'data_source_id' => $this->ds->id,
            'external_id' => '19241', 'external_name' => 'L. Martinez',
        ]);

        Http::fake(['*injuries*' => Http::response(
            $this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID)]),
            200,
        )]);

        $this->service()->syncPending();

        $this->assertSame(1, Player::count());
        $this->assertSame($player->id, PlayerAbsence::value('player_id'));
    }

    public function test_missing_player_created_minimally_without_extra_api_call(): void
    {
        Http::fake(['*injuries*' => Http::response(
            $this->response([$this->injuryItem(99999, 'New Player', self::HOME_API_ID)]),
            200,
        )]);

        $this->service()->syncPending();

        Http::assertSentCount(1); // only /injuries, no separate player lookup

        $this->assertSame(1, Player::count());
        $this->assertSame('New Player', Player::value('name'));
        $this->assertDatabaseHas('player_external_ids', [
            'external_id' => '99999', 'data_source_id' => $this->ds->id,
        ]);
    }

    // =========================================================================
    // 3. Team mapping
    // =========================================================================

    public function test_team_mapping_missing_skips_record_with_warning(): void
    {
        TeamExternalId::where('team_id', $this->awayTeam->id)->delete();

        Http::fake(['*injuries*' => Http::response(
            $this->response([
                $this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID),
                $this->injuryItem(9895,  'R. Lukaku',   self::AWAY_API_ID),
            ]),
            200,
        )]);

        $result = $this->service()->syncPending();

        $this->assertSame(1, $result['created']);   // home team processed
        $this->assertSame(1, $result['warnings']);  // away team skipped
        $this->assertSame(1, PlayerAbsence::count());
        $this->assertDatabaseHas('player_absences', ['team_id' => $this->homeTeam->id]);
    }

    // =========================================================================
    // 4. Idempotency and update
    // =========================================================================

    public function test_idempotency_second_sync_no_duplicate(): void
    {
        $body = $this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID, 'Missing Fixture', 'Knee')]);

        Http::fake(['*injuries*' => Http::sequence()
            ->push($body, 200)
            ->push($body, 200),
        ]);

        $service = $this->service();
        $service->syncPending();

        // Reset attempt sentinel so second call is not throttled
        $this->futureMatch->update(['injuries_last_attempt_at' => null]);

        $service->syncPending();

        $this->assertSame(1, PlayerAbsence::count()); // no duplicates
    }

    public function test_subsequent_sync_updates_reason_and_type(): void
    {
        Http::fake(['*injuries*' => Http::sequence()
            ->push($this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID, 'Missing Fixture', 'Knee Injury')]), 200)
            ->push($this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID, 'Questionable',     'Ankle Injury')]), 200),
        ]);

        $service = $this->service();
        $r1 = $service->syncPending();
        $this->assertSame(1, $r1['created']);

        $this->futureMatch->update(['injuries_last_attempt_at' => null]);

        $r2 = $service->syncPending();
        $this->assertSame(0, $r2['created']);
        $this->assertSame(1, $r2['updated']);

        $absence = PlayerAbsence::firstOrFail();
        $this->assertSame('Questionable',  $absence->absence_type);
        $this->assertSame('Ankle Injury', $absence->reason);
    }

    // =========================================================================
    // 5. Snapshot — recovered player
    // =========================================================================

    public function test_recovered_player_removed_from_snapshot(): void
    {
        // Create two absences for the home team
        $playerA = Player::create(['name' => 'Player A']);
        PlayerExternalId::create(['player_id' => $playerA->id, 'data_source_id' => $this->ds->id, 'external_id' => '1001', 'external_name' => 'Player A']);
        $playerB = Player::create(['name' => 'Player B']);
        PlayerExternalId::create(['player_id' => $playerB->id, 'data_source_id' => $this->ds->id, 'external_id' => '1002', 'external_name' => 'Player B']);
        PlayerAbsence::create(['match_id' => $this->futureMatch->id, 'player_id' => $playerA->id, 'team_id' => $this->homeTeam->id, 'data_source_id' => $this->ds->id, 'absence_type' => 'Missing Fixture', 'reason' => 'Knee Injury']);
        PlayerAbsence::create(['match_id' => $this->futureMatch->id, 'player_id' => $playerB->id, 'team_id' => $this->homeTeam->id, 'data_source_id' => $this->ds->id, 'absence_type' => 'Missing Fixture', 'reason' => 'Knee Injury']);

        // New snapshot: only Player A still absent (Player B recovered)
        Http::fake(['*injuries*' => Http::response(
            $this->response([$this->injuryItem(1001, 'Player A', self::HOME_API_ID)]),
            200,
        )]);

        $result = $this->service()->syncPending();

        $this->assertSame(1, $result['updated']); // raw_data fills in from the API item
        $this->assertSame(1, $result['removed']);
        $this->assertSame(1, PlayerAbsence::count()); // only Player A
        $this->assertDatabaseHas('player_absences',    ['player_id' => $playerA->id]);
        $this->assertDatabaseMissing('player_absences', ['player_id' => $playerB->id]);
    }

    public function test_empty_response_removes_all_absences_for_fixture(): void
    {
        $player = Player::create(['name' => 'Player A']);
        PlayerAbsence::create(['match_id' => $this->futureMatch->id, 'player_id' => $player->id, 'team_id' => $this->homeTeam->id, 'data_source_id' => $this->ds->id]);

        Http::fake(['*injuries*' => Http::response(
            $this->emptyApiResponse(),
            200,
        )]);

        $result = $this->service()->syncPending();

        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['empty']);
        $this->assertSame(1, $result['removed']);
        $this->assertSame(0, PlayerAbsence::count());
    }

    // =========================================================================
    // 6. HTTP error handling
    // =========================================================================

    public function test_http_error_does_not_clear_existing_absences(): void
    {
        $player = Player::create(['name' => 'Player A']);
        PlayerAbsence::create([
            'match_id' => $this->futureMatch->id, 'player_id' => $player->id,
            'team_id'  => $this->homeTeam->id, 'data_source_id' => $this->ds->id,
        ]);

        Http::fake(['*injuries*' => Http::response([], 500)]);

        $result = $this->service()->syncPending();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, PlayerAbsence::count()); // unchanged
    }

    // =========================================================================
    // 7. Sentinel semantics
    // =========================================================================

    public function test_injuries_last_attempt_at_set_on_successful_sync(): void
    {
        Http::fake(['*injuries*' => Http::response(
            $this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID)]),
            200,
        )]);

        $this->service()->syncPending();

        $this->futureMatch->refresh();
        $this->assertNotNull($this->futureMatch->injuries_last_attempt_at);
    }

    public function test_injuries_last_attempt_at_set_even_on_http_error(): void
    {
        Http::fake(['*injuries*' => Http::response([], 500)]);

        $this->service()->syncPending();

        $this->futureMatch->refresh();
        $this->assertNotNull($this->futureMatch->injuries_last_attempt_at);
    }

    public function test_injuries_fetched_at_set_on_valid_response_with_data(): void
    {
        Http::fake(['*injuries*' => Http::response(
            $this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID)]),
            200,
        )]);

        $this->service()->syncPending();

        $this->futureMatch->refresh();
        $this->assertNotNull($this->futureMatch->injuries_fetched_at);
    }

    public function test_injuries_fetched_at_set_on_empty_valid_response(): void
    {
        Http::fake(['*injuries*' => Http::response($this->emptyApiResponse(), 200)]);

        $this->service()->syncPending();

        $this->futureMatch->refresh();
        $this->assertNotNull($this->futureMatch->injuries_fetched_at);
    }

    public function test_injuries_fetched_at_not_set_on_http_error(): void
    {
        Http::fake(['*injuries*' => Http::response([], 500)]);

        $this->service()->syncPending();

        $this->futureMatch->refresh();
        $this->assertNull($this->futureMatch->injuries_fetched_at);
    }

    // =========================================================================
    // 8. syncPending candidate selection
    // =========================================================================

    public function test_sync_pending_includes_future_fixture(): void
    {
        Http::fake(['*injuries*' => Http::response(
            $this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID)]),
            200,
        )]);

        $result = $this->service()->syncPending();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['synced']);
        Http::assertSentCount(1);
    }

    public function test_sync_pending_excludes_fixture_with_past_kickoff(): void
    {
        $this->futureMatch->update(['kickoff_at' => now()->subHour()]);

        Http::fake();

        $result = $this->service()->syncPending();

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
    }

    public function test_sync_pending_excludes_fixture_beyond_window(): void
    {
        $this->futureMatch->update(['kickoff_at' => now()->addDays(8)]);

        Http::fake();

        $result = $this->service()->syncPending(windowDays: 7);

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
    }

    // =========================================================================
    // 9. Throttle
    // =========================================================================

    public function test_throttle_beyond_48h_skips_if_attempted_within_24h(): void
    {
        // kickoff in 72h (>48h threshold → min 24h between attempts)
        // last attempt 12h ago → still within the 24h window → skip
        $this->futureMatch->update([
            'kickoff_at'               => now()->addHours(72),
            'injuries_last_attempt_at' => now()->subHours(12),
        ]);

        Http::fake();

        $result = $this->service()->syncPending();

        $this->assertSame(1, $result['skipped_throttle']);
        $this->assertSame(0, $result['synced']);
        Http::assertNothingSent();
    }

    public function test_throttle_beyond_48h_fetches_after_24h(): void
    {
        // Last attempt 25h ago → gap exceeds 24h → should fetch
        $this->futureMatch->update([
            'kickoff_at'               => now()->addHours(72),
            'injuries_last_attempt_at' => now()->subHours(25),
        ]);

        Http::fake(['*injuries*' => Http::response(
            $this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID)]),
            200,
        )]);

        $result = $this->service()->syncPending();

        $this->assertSame(0, $result['skipped_throttle']);
        $this->assertSame(1, $result['synced']);
    }

    public function test_throttle_between_48h_and_12h_skips_if_attempted_within_6h(): void
    {
        // kickoff in 24h (12–48h range → min 6h between attempts)
        // last attempt 3h ago → within 6h window → skip
        $this->futureMatch->update([
            'kickoff_at'               => now()->addHours(24),
            'injuries_last_attempt_at' => now()->subHours(3),
        ]);

        Http::fake();

        $result = $this->service()->syncPending();

        $this->assertSame(1, $result['skipped_throttle']);
        Http::assertNothingSent();
    }

    public function test_throttle_within_12h_skips_if_attempted_within_2h(): void
    {
        // kickoff in 6h (≤12h range → min 2h between attempts)
        // last attempt 1h ago → within 2h window → skip
        $this->futureMatch->update([
            'kickoff_at'               => now()->addHours(6),
            'injuries_last_attempt_at' => now()->subHour(),
        ]);

        Http::fake();

        $result = $this->service()->syncPending();

        $this->assertSame(1, $result['skipped_throttle']);
        Http::assertNothingSent();
    }

    public function test_http_error_prevents_immediate_retry(): void
    {
        // First call: HTTP 500 → sets injuries_last_attempt_at, injuries_fetched_at stays null
        Http::fake(['*injuries*' => Http::response([], 500)]);
        $this->service()->syncPending();

        // Immediately after: last_attempt_at is < 24h ago (kickoff in 72h → 24h window)
        // The fixture must be throttled — no second API call
        Http::fake(); // expects nothing
        $result = $this->service()->syncPending();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['skipped_throttle']);
        Http::assertNothingSent();

        // And injuries_fetched_at must still be null (error never produces a valid snapshot)
        $this->futureMatch->refresh();
        $this->assertNull($this->futureMatch->injuries_fetched_at);
    }

    public function test_fixture_retried_after_throttle_period_following_error(): void
    {
        // Simulate a previous failed attempt that is now older than the throttle window
        // kickoff in 72h → >48h → 24h window; last attempt 25h ago → eligible
        $this->futureMatch->update([
            'kickoff_at'               => now()->addHours(72),
            'injuries_last_attempt_at' => now()->subHours(25),
        ]);

        Http::fake(['*injuries*' => Http::response(
            $this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID)]),
            200,
        )]);

        $result = $this->service()->syncPending();

        $this->assertSame(0, $result['skipped_throttle']);
        $this->assertSame(1, $result['synced']);
        Http::assertSentCount(1);
    }

    // =========================================================================
    // 10. syncMissingHistorical — season / league filter
    // =========================================================================

    public function test_historical_season_filter_targets_correct_year(): void
    {
        Http::fake(['*injuries*' => Http::response(
            $this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID)]),
            200,
        )]);

        $result = $this->service()->syncMissingHistorical(2026);

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['synced']);
    }

    public function test_historical_wrong_year_returns_no_season_found(): void
    {
        Http::fake();

        $result = $this->service()->syncMissingHistorical(2019);

        $this->assertSame('no_season_found', $result['status']);
        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
    }

    public function test_league_filter_excludes_other_competitions(): void
    {
        // Create a second competition (Premier League)
        $country = Country::create(['name' => 'England', 'football_code' => 'GB-ENG']);
        $plComp  = Competition::create(['country_id' => $country->id, 'name' => 'Premier League', 'slug' => 'premier-league', 'format' => 'league', 'is_active' => true]);
        $plSeason = Season::create(['competition_id' => $plComp->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);

        $plTeamH = Team::create(['name' => 'Arsenal', 'type' => 'club', 'is_active' => true]);
        $plTeamA = Team::create(['name' => 'Chelsea', 'type' => 'club', 'is_active' => true]);
        $plMatch = FootballMatch::create([
            'competition_id' => $plComp->id, 'season_id' => $plSeason->id,
            'home_team_id' => $plTeamH->id, 'away_team_id' => $plTeamA->id,
            'kickoff_at' => now()->subHours(4), 'definitive_at' => now()->subHours(3),
            'status' => 'finished',
        ]);
        MatchExternalId::create(['match_id' => $plMatch->id, 'data_source_id' => $this->ds->id, 'external_id' => '333333']);

        Http::fake(['*injuries*' => Http::response(
            $this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID)]),
            200,
        )]);

        // Filter to Serie A only → only finishedMatch (Serie A) should be processed
        $result = $this->service()->syncMissingHistorical(2026, 'serie-a');

        $this->assertSame(1, $result['candidates']); // only Serie A match
        Http::assertSentCount(1);
    }

    // =========================================================================
    // 11. Error isolation
    // =========================================================================

    public function test_error_one_fixture_does_not_block_others(): void
    {
        // Create a second future match
        $secondMatch = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->addHours(48),
            'status'         => 'not_started',
        ]);
        MatchExternalId::create(['match_id' => $secondMatch->id, 'data_source_id' => $this->ds->id, 'external_id' => '444444']);

        // First fixture (FUTURE_EXT_ID) → HTTP error; second → success
        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), '111111')) {
                return Http::response([], 500);
            }
            return Http::response(
                $this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID)]),
                200,
            );
        });

        $result = $this->service()->syncPending();

        $this->assertSame(2, $result['candidates']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['synced']);
        $this->assertSame(1, PlayerAbsence::count()); // only from successful fixture
    }

    // =========================================================================
    // 12. DataSyncRun
    // =========================================================================

    public function test_data_sync_run_created_with_correct_counters(): void
    {
        Http::fake(['*injuries*' => Http::response(
            $this->response([
                $this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID),
                $this->injuryItem(9895,  'R. Lukaku',   self::AWAY_API_ID),
            ]),
            200,
        )]);

        $this->service()->syncPending();

        $run = DataSyncRun::where('sync_type', 'injury_sync')->firstOrFail();
        $this->assertSame('pending',  $run->mode);
        $this->assertSame(2, $run->created_count);
        $this->assertSame(0, $run->updated_count);
        $this->assertSame(0, $run->skipped_count);
        $this->assertSame(1, $run->api_calls);
        $this->assertSame(0, $run->details['removed_count']);
    }

    // =========================================================================
    // 13. Artisan command
    // =========================================================================

    public function test_artisan_command_pending_delegates_correctly(): void
    {
        Http::fake(['*injuries*' => Http::response(
            $this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID)]),
            200,
        )]);

        $this->artisan('robetting:sync-api-football-injuries')
            ->assertSuccessful();

        $this->assertSame(1, PlayerAbsence::count());
    }

    public function test_artisan_command_historical_delegates_correctly(): void
    {
        Http::fake(['*injuries*' => Http::response(
            $this->response([$this->injuryItem(19241, 'L. Martinez', self::HOME_API_ID)]),
            200,
        )]);

        $this->artisan('robetting:sync-api-football-injuries --season=2026')
            ->assertSuccessful();

        $this->assertSame(1, PlayerAbsence::count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): ApiFootballInjurySyncService
    {
        return app(ApiFootballInjurySyncService::class);
    }

    private function response(array $items): array
    {
        return [
            'errors'   => [],
            'results'  => count($items),
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => $items,
        ];
    }

    private function emptyApiResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 0,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [],
        ];
    }

    private function injuryItem(
        int    $playerId,
        string $playerName,
        int    $teamApiId,
        string $type   = 'Missing Fixture',
        string $reason = 'Knee Injury',
    ): array {
        return [
            'player'  => ['id' => $playerId, 'name' => $playerName, 'photo' => ''],
            'team'    => ['id' => $teamApiId, 'name' => 'Team', 'logo' => ''],
            'fixture' => ['id' => (int) self::FUTURE_EXT_ID, 'timezone' => 'UTC', 'date' => '', 'timestamp' => 0],
            'league'  => ['id' => 135, 'name' => 'Serie A', 'country' => 'Italy', 'logo' => '', 'flag' => '', 'season' => 2026],
            'type'    => $type,
            'reason'  => $reason,
        ];
    }
}
