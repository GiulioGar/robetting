<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\MatchExternalId;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballException;
use App\Services\DataSources\ApiFootball\ApiFootballMatchEventSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Post-match event sync via API-Football /fixtures/events.
 *
 * Completeness sentinel: events_fetched_at TIMESTAMP NULL on matches.
 * Set on any valid 2xx response (including []). Not set on HTTP error or all-unparseable payload.
 * Idempotency anchor: source_event_key = {elapsed}_{extra|0}_{api_team_id}_{type}_{detail}_{player_id|name}.
 */
class ApiFootballMatchEventSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOME_API_ID = 505;
    private const AWAY_API_ID = 489;

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

        $this->homeTeam = Team::create(['name' => 'Home FC', 'type' => 'club', 'is_active' => true]);
        $this->awayTeam = Team::create(['name' => 'Away FC', 'type' => 'club', 'is_active' => true]);

        TeamExternalId::create([
            'team_id'        => $this->homeTeam->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => (string) self::HOME_API_ID,
            'external_name'  => 'Home FC',
        ]);

        TeamExternalId::create([
            'team_id'        => $this->awayTeam->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => (string) self::AWAY_API_ID,
            'external_name'  => 'Away FC',
        ]);
    }

    // -------------------------------------------------------------------------
    // 1. Goal + assist: correct event_type, player/assist fields, team resolved
    // -------------------------------------------------------------------------

    public function test_goal_with_assist_parsed_correctly(): void
    {
        $match = $this->makeMatch('9801');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->goalEvent(45, null, self::HOME_API_ID, 'Normal Goal', 184943, 'Vlahovic', 2295, 'Kostic'),
            ]), 200),
        ]);

        $result = $this->service()->syncSingle($match, '9801');

        $this->assertSame('synced', $result['outcome']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(1, $result['events_count']);

        $event = MatchEvent::first();
        $this->assertNotNull($event);
        $this->assertSame('goal', $event->event_type);
        $this->assertSame(45, $event->minute);
        $this->assertSame('45', $event->minute_label);
        $this->assertSame($this->homeTeam->id, $event->team_id);
        $this->assertSame('184943', $event->player_external_id);
        $this->assertSame('Vlahovic', $event->player_name);
        $this->assertSame('2295', $event->related_player_external_id);
        $this->assertSame('Kostic', $event->related_player_name);
        $this->assertNotNull($event->detail);
        $this->assertSame('Goal', $event->detail['api_type']);
        $this->assertSame('Normal Goal', $event->detail['api_detail']);

        $match->refresh();
        $this->assertNotNull($match->events_fetched_at);
    }

    // -------------------------------------------------------------------------
    // 2. Own goal → event_type = 'own_goal'
    // -------------------------------------------------------------------------

    public function test_own_goal_event_type(): void
    {
        $match = $this->makeMatch('9802');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->goalEvent(67, null, self::AWAY_API_ID, 'Own Goal', 5512, 'Acerbi', null, null),
            ]), 200),
        ]);

        $this->service()->syncSingle($match, '9802');

        $this->assertSame('own_goal', MatchEvent::first()->event_type);
        $this->assertSame($this->awayTeam->id, MatchEvent::first()->team_id);
    }

    // -------------------------------------------------------------------------
    // 3. Penalty goal → event_type = 'goal'
    // -------------------------------------------------------------------------

    public function test_penalty_goal_event_type(): void
    {
        $match = $this->makeMatch('9803');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->goalEvent(73, null, self::HOME_API_ID, 'Penalty', 184943, 'Vlahovic', null, null),
            ]), 200),
        ]);

        $this->service()->syncSingle($match, '9803');

        $this->assertSame('goal', MatchEvent::first()->event_type);
        $this->assertSame('Penalty', MatchEvent::first()->detail['api_detail']);
    }

    // -------------------------------------------------------------------------
    // 4. Missed penalty → event_type = 'missed_penalty'
    // -------------------------------------------------------------------------

    public function test_missed_penalty_event_type(): void
    {
        $match = $this->makeMatch('9804');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->goalEvent(58, null, self::AWAY_API_ID, 'Missed Penalty', 7791, 'Giroud', null, null),
            ]), 200),
        ]);

        $this->service()->syncSingle($match, '9804');

        $this->assertSame('missed_penalty', MatchEvent::first()->event_type);
    }

    // -------------------------------------------------------------------------
    // 5. Yellow card → event_type = 'yellow_card'
    // -------------------------------------------------------------------------

    public function test_yellow_card_event_type(): void
    {
        $match = $this->makeMatch('9805');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->cardEvent(34, null, self::AWAY_API_ID, 'Yellow Card', 8833, 'Theo Hernandez'),
            ]), 200),
        ]);

        $this->service()->syncSingle($match, '9805');

        $event = MatchEvent::first();
        $this->assertSame('yellow_card', $event->event_type);
        $this->assertSame('8833', $event->player_external_id);
        $this->assertSame('Theo Hernandez', $event->player_name);
        $this->assertNull($event->related_player_external_id);
    }

    // -------------------------------------------------------------------------
    // 6. Red card → event_type = 'red_card'
    // -------------------------------------------------------------------------

    public function test_red_card_event_type(): void
    {
        $match = $this->makeMatch('9806');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->cardEvent(78, null, self::HOME_API_ID, 'Red Card', 3391, 'Bremer'),
            ]), 200),
        ]);

        $this->service()->syncSingle($match, '9806');

        $this->assertSame('red_card', MatchEvent::first()->event_type);
    }

    // -------------------------------------------------------------------------
    // 7. Yellow-red card (second yellow) → event_type = 'yellow_red_card'
    // -------------------------------------------------------------------------

    public function test_yellow_red_card_event_type(): void
    {
        $match = $this->makeMatch('9807');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->cardEvent(82, null, self::AWAY_API_ID, 'Yellow Red Card', 6612, 'Tonali'),
            ]), 200),
        ]);

        $this->service()->syncSingle($match, '9807');

        $this->assertSame('yellow_red_card', MatchEvent::first()->event_type);
    }

    // -------------------------------------------------------------------------
    // 8. Substitution: event_type = 'substitution', player = in, related = out
    // -------------------------------------------------------------------------

    public function test_substitution_player_in_and_out(): void
    {
        $match = $this->makeMatch('9808');

        // In API-Football: player = coming on (in), assist = going off (out)
        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->substEvent(61, null, self::HOME_API_ID, 9182, 'Locatelli', 8123, 'Ramsey'),
            ]), 200),
        ]);

        $this->service()->syncSingle($match, '9808');

        $event = MatchEvent::first();
        $this->assertSame('substitution', $event->event_type);
        $this->assertSame('9182', $event->player_external_id);
        $this->assertSame('Locatelli', $event->player_name);
        $this->assertSame('8123', $event->related_player_external_id);
        $this->assertSame('Ramsey', $event->related_player_name);
        $this->assertSame(['api_type' => 'subst'], $event->detail);
    }

    // -------------------------------------------------------------------------
    // 9. VAR event with null player: event stored with null player fields
    // -------------------------------------------------------------------------

    public function test_var_with_null_player_stored_correctly(): void
    {
        $match = $this->makeMatch('9809');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->varEvent(38, null, self::HOME_API_ID, 'Goal Disallowed - offside', null, null),
            ]), 200),
        ]);

        $result = $this->service()->syncSingle($match, '9809');

        $this->assertSame('synced', $result['outcome']);

        $event = MatchEvent::first();
        $this->assertNotNull($event);
        $this->assertSame('var', $event->event_type);
        $this->assertSame(38, $event->minute);
        $this->assertNull($event->player_external_id);
        $this->assertNull($event->player_name);
        $this->assertSame('Goal Disallowed - offside', $event->detail['api_detail']);
    }

    // -------------------------------------------------------------------------
    // 10. Extra time minute label: minute=45, minute_label='45+2'
    // -------------------------------------------------------------------------

    public function test_extra_time_minute_label(): void
    {
        $match = $this->makeMatch('9810');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->goalEvent(45, 2, self::HOME_API_ID, 'Normal Goal', 184943, 'Vlahovic', null, null),
            ]), 200),
        ]);

        $this->service()->syncSingle($match, '9810');

        $event = MatchEvent::first();
        $this->assertSame(45, $event->minute);
        $this->assertSame('45+2', $event->minute_label);
    }

    // -------------------------------------------------------------------------
    // 11. Idempotency: second sync produces no new rows
    // -------------------------------------------------------------------------

    public function test_second_sync_is_idempotent(): void
    {
        $match = $this->makeMatch('9811');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->goalEvent(30, null, self::HOME_API_ID, 'Normal Goal', 184943, 'Vlahovic', 2295, 'Kostic'),
                $this->cardEvent(55, null, self::AWAY_API_ID, 'Yellow Card', 8833, 'Theo Hernandez'),
            ]), 200),
        ]);

        $service = $this->service();

        $result1 = $service->syncSingle($match, '9811');
        $this->assertSame('synced', $result1['outcome']);
        $this->assertSame(2, MatchEvent::count());

        // Second call on the same model instance: events_fetched_at is now set → skip
        $result2 = $service->syncSingle($match, '9811');
        $this->assertSame('skipped_complete', $result2['outcome']);
        $this->assertSame(0, $result2['api_calls']);
        $this->assertSame(2, MatchEvent::count());

        Http::assertSentCount(1);
    }

    // -------------------------------------------------------------------------
    // 12. Empty response []: events_fetched_at set, no MatchEvent rows created
    // -------------------------------------------------------------------------

    public function test_empty_response_sets_events_fetched_at_no_events_inserted(): void
    {
        $match = $this->makeMatch('9812');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([]), 200),
        ]);

        $result = $this->service()->syncSingle($match, '9812');

        $this->assertSame('empty', $result['outcome']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(0, $result['events_count']);
        $this->assertSame(0, MatchEvent::count());

        $match->refresh();
        $this->assertNotNull($match->events_fetched_at);
    }

    // -------------------------------------------------------------------------
    // 13. HTTP failure: events_fetched_at stays null, match remains retryable
    // -------------------------------------------------------------------------

    public function test_http_failure_leaves_match_retryable(): void
    {
        $match = $this->makeMatch('9813');

        Http::fake([
            '*fixtures/events*' => Http::response([], 500),
        ]);

        $threwException = false;
        try {
            $this->service()->syncSingle($match, '9813');
        } catch (ApiFootballException) {
            $threwException = true;
        }

        $this->assertTrue($threwException, 'syncSingle deve propagare ApiFootballException su HTTP 500');
        $this->assertSame(0, MatchEvent::count());

        $match->refresh();
        $this->assertNull($match->events_fetched_at, 'HTTP failure non deve impostare events_fetched_at');
    }

    // -------------------------------------------------------------------------
    // 14. Unparseable payload: all events missing required fields → no events_fetched_at
    // -------------------------------------------------------------------------

    public function test_unparseable_response_leaves_match_retryable(): void
    {
        $match = $this->makeMatch('9814');

        // Non-empty response but all items lack time/team/type (completely unexpected structure)
        Http::fake([
            '*fixtures/events*' => Http::response([
                'errors'   => [],
                'response' => [
                    ['completely' => 'wrong', 'structure' => true],
                    ['no' => 'time', 'or' => 'team'],
                ],
            ], 200),
        ]);

        $result = $this->service()->syncSingle($match, '9814');

        $this->assertSame('unparsable', $result['outcome']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(0, MatchEvent::count());

        $match->refresh();
        $this->assertNull($match->events_fetched_at, 'Payload non parsabile non deve impostare events_fetched_at');
    }

    // -------------------------------------------------------------------------
    // 14b. Mixed payload: 2 valid + 1 malformed → valid events saved, events_fetched_at set
    // -------------------------------------------------------------------------

    public function test_mixed_payload_saves_valid_events_and_sets_events_fetched_at(): void
    {
        $match = $this->makeMatch('9814b');

        // 2 valid events + 1 malformed (missing time/team/type)
        Http::fake([
            '*fixtures/events*' => Http::response([
                'errors'   => [],
                'response' => [
                    $this->goalEvent(30, null, self::HOME_API_ID, 'Normal Goal', 184943, 'Vlahovic', null, null),
                    $this->cardEvent(55, null, self::AWAY_API_ID, 'Yellow Card', 8833, 'Theo Hernandez'),
                    ['completely' => 'malformed', 'no_time_team_type' => true],
                ],
            ], 200),
        ]);

        $result = $this->service()->syncSingle($match, '9814b');

        $this->assertSame('synced', $result['outcome']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(2, $result['events_count'], '2 valid events must be saved despite 1 malformed item');
        $this->assertSame(2, MatchEvent::count());

        $match->refresh();
        $this->assertNotNull($match->events_fetched_at, 'events_fetched_at must be set when at least 1 event is valid');
    }

    // -------------------------------------------------------------------------
    // 15. events_fetched_at already set → zero API call
    // -------------------------------------------------------------------------

    public function test_events_fetched_at_present_skips_api_call(): void
    {
        $match = $this->makeMatch('9815');
        $match->update(['events_fetched_at' => now()->subMinutes(5)]);
        $match->refresh();

        Http::fake();

        $result = $this->service()->syncSingle($match, '9815');

        $this->assertSame('skipped_complete', $result['outcome']);
        $this->assertSame(0, $result['api_calls']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 16. syncPending: match inside grace period → zero candidates
    // -------------------------------------------------------------------------

    public function test_inside_grace_period_match_not_a_candidate(): void
    {
        // definitive_at = now(): not yet past the 10-minute grace window
        $this->makeDefinitiveMatch('9816', definitiveAt: now());

        Http::fake();

        $result = $this->service()->syncPending(gracePeriodMinutes: 10);

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 17. syncPending: match past grace period → 1 candidate, synced
    // -------------------------------------------------------------------------

    public function test_past_grace_period_match_is_candidate(): void
    {
        // definitive_at 11 minutes ago → past the 10-minute grace window
        $match = $this->makeDefinitiveMatch('9817', definitiveAt: now()->subMinutes(11));

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->goalEvent(25, null, self::HOME_API_ID, 'Normal Goal', 184943, 'Vlahovic', null, null),
            ]), 200),
        ]);

        $result = $this->service()->syncPending(gracePeriodMinutes: 10);

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['synced']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(1, MatchEvent::count());

        $match->refresh();
        $this->assertNotNull($match->events_fetched_at);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function service(): ApiFootballMatchEventSyncService
    {
        return app(ApiFootballMatchEventSyncService::class);
    }

    private function makeMatch(string $extId, mixed $definitiveAt = null): FootballMatch
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHours(2),
            'status'         => 'finished',
            'definitive_at'  => $definitiveAt ?? now()->subHours(2),
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => $extId,
            'external_name'  => null,
        ]);

        return $match;
    }

    private function makeDefinitiveMatch(string $extId, mixed $definitiveAt): FootballMatch
    {
        return $this->makeMatch($extId, $definitiveAt);
    }

    private function apiResponse(array $events): array
    {
        return [
            'errors'   => [],
            'results'  => count($events),
            'response' => $events,
        ];
    }

    private function goalEvent(
        int $elapsed,
        ?int $extra,
        int $teamId,
        string $detail,
        ?int $playerId,
        ?string $playerName,
        ?int $assistId,
        ?string $assistName,
    ): array {
        return [
            'time'    => ['elapsed' => $elapsed, 'extra' => $extra],
            'team'    => ['id' => $teamId, 'name' => 'Team'],
            'player'  => ['id' => $playerId, 'name' => $playerName],
            'assist'  => ['id' => $assistId, 'name' => $assistName],
            'type'    => 'Goal',
            'detail'  => $detail,
            'comments' => null,
        ];
    }

    private function cardEvent(
        int $elapsed,
        ?int $extra,
        int $teamId,
        string $detail,
        int $playerId,
        string $playerName,
    ): array {
        return [
            'time'    => ['elapsed' => $elapsed, 'extra' => $extra],
            'team'    => ['id' => $teamId, 'name' => 'Team'],
            'player'  => ['id' => $playerId, 'name' => $playerName],
            'assist'  => ['id' => null, 'name' => null],
            'type'    => 'Card',
            'detail'  => $detail,
            'comments' => null,
        ];
    }

    private function substEvent(
        int $elapsed,
        ?int $extra,
        int $teamId,
        int $playerInId,
        string $playerInName,
        int $playerOutId,
        string $playerOutName,
    ): array {
        return [
            'time'    => ['elapsed' => $elapsed, 'extra' => $extra],
            'team'    => ['id' => $teamId, 'name' => 'Team'],
            'player'  => ['id' => $playerInId, 'name' => $playerInName],
            'assist'  => ['id' => $playerOutId, 'name' => $playerOutName],
            'type'    => 'subst',
            'detail'  => null,
            'comments' => null,
        ];
    }

    private function varEvent(
        int $elapsed,
        ?int $extra,
        int $teamId,
        string $detail,
        ?int $playerId,
        ?string $playerName,
    ): array {
        return [
            'time'    => ['elapsed' => $elapsed, 'extra' => $extra],
            'team'    => ['id' => $teamId, 'name' => 'Team'],
            'player'  => ['id' => $playerId, 'name' => $playerName],
            'assist'  => ['id' => null, 'name' => null],
            'type'    => 'Var',
            'detail'  => $detail,
            'comments' => null,
        ];
    }
}
