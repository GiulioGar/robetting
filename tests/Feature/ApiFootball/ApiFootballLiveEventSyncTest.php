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
use App\Services\DataSources\ApiFootball\ApiFootballMatchEventSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Live event sync via ApiFootballMatchEventSyncService::syncLiveSingle / syncLive.
 *
 * Rules under test:
 *  - syncLiveSingle always fetches (no sentinel guard).
 *  - upsert is idempotent — same source_event_key → no duplicate row.
 *  - events_fetched_at is NEVER set by the live flow.
 *  - HTTP failures are caught by syncLive, returned as failed count, never thrown.
 *  - syncLive only considers status = 'live' matches; 'finished' matches are ignored.
 */
class ApiFootballLiveEventSyncTest extends TestCase
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
    // 1. Live con 1 goal → evento inserito
    // -------------------------------------------------------------------------

    public function test_live_goal_event_inserted(): void
    {
        $match = $this->makeLiveMatch('7001');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->goalEvent(45, null, self::HOME_API_ID, 'Normal Goal', 184943, 'Vlahovic', 2295, 'Kostic'),
            ]), 200),
        ]);

        $result = $this->service()->syncLiveSingle($match, '7001');

        $this->assertSame('synced', $result['outcome']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(1, $result['events_count']);
        $this->assertSame(1, MatchEvent::count());

        $event = MatchEvent::first();
        $this->assertSame('goal', $event->event_type);
        $this->assertSame(45, $event->minute);
        $this->assertSame($this->homeTeam->id, $event->team_id);
    }

    // -------------------------------------------------------------------------
    // 2. Secondo refresh stesso payload → nessun duplicato
    // -------------------------------------------------------------------------

    public function test_live_same_payload_no_duplicate(): void
    {
        $match = $this->makeLiveMatch('7002');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->goalEvent(30, null, self::HOME_API_ID, 'Normal Goal', 184943, 'Vlahovic', null, null),
            ]), 200),
        ]);

        $service = $this->service();
        $service->syncLiveSingle($match, '7002');
        $service->syncLiveSingle($match, '7002');

        $this->assertSame(1, MatchEvent::count());
        Http::assertSentCount(2);
    }

    // -------------------------------------------------------------------------
    // 3. Live con nuovo cartellino → nuovo evento aggiunto
    // -------------------------------------------------------------------------

    public function test_live_new_card_added_on_second_refresh(): void
    {
        $match = $this->makeLiveMatch('7003');

        $goal = $this->goalEvent(30, null, self::HOME_API_ID, 'Normal Goal', 184943, 'Vlahovic', null, null);
        $card = $this->cardEvent(55, null, self::AWAY_API_ID, 'Yellow Card', 8833, 'Theo Hernandez');

        Http::fake([
            '*fixtures/events*' => Http::sequence()
                ->push($this->apiResponse([$goal]), 200)
                ->push($this->apiResponse([$goal, $card]), 200),
        ]);

        $service = $this->service();

        $service->syncLiveSingle($match, '7003');
        $this->assertSame(1, MatchEvent::count());

        $service->syncLiveSingle($match, '7003');
        $this->assertSame(2, MatchEvent::count());

        $types = MatchEvent::orderBy('minute')->pluck('event_type')->all();
        $this->assertSame(['goal', 'yellow_card'], $types);
    }

    // -------------------------------------------------------------------------
    // 4. Substitution aggiornata correttamente (player=OUT, related=IN)
    // -------------------------------------------------------------------------

    public function test_live_substitution_player_semantics(): void
    {
        $match = $this->makeLiveMatch('7004');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->substEvent(65, null, self::HOME_API_ID, 9182, 'Locatelli', 8123, 'Ramsey'),
            ]), 200),
        ]);

        $this->service()->syncLiveSingle($match, '7004');

        $event = MatchEvent::first();
        $this->assertNotNull($event);
        $this->assertSame('substitution', $event->event_type);
        $this->assertSame('9182', $event->player_external_id);
        $this->assertSame('Locatelli', $event->player_name);
        $this->assertSame('8123', $event->related_player_external_id);
        $this->assertSame('Ramsey', $event->related_player_name);
    }

    // -------------------------------------------------------------------------
    // 5. events_fetched_at resta null durante live
    // -------------------------------------------------------------------------

    public function test_live_events_fetched_at_stays_null(): void
    {
        $match = $this->makeLiveMatch('7005');

        Http::fake([
            '*fixtures/events*' => Http::response($this->apiResponse([
                $this->goalEvent(22, null, self::AWAY_API_ID, 'Normal Goal', 7791, 'Giroud', null, null),
            ]), 200),
        ]);

        $this->service()->syncLiveSingle($match, '7005');

        $match->refresh();
        $this->assertNull($match->events_fetched_at, 'syncLiveSingle non deve mai impostare events_fetched_at');
    }

    // -------------------------------------------------------------------------
    // 6. HTTP failure eventi non rompe il result refresh
    //    syncLive cattura l'eccezione, ritorna failed=1, non lancia
    // -------------------------------------------------------------------------

    public function test_http_failure_on_live_events_does_not_throw(): void
    {
        $this->makeLiveMatch('7006');

        Http::fake([
            '*fixtures/events*' => Http::response([], 500),
        ]);

        $result = $this->service()->syncLive();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['candidates']);
        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['api_calls']);
        $this->assertSame(0, MatchEvent::count());
    }

    // -------------------------------------------------------------------------
    // 7a. Live match senza external ID → zero API call, warning loggato
    // -------------------------------------------------------------------------

    public function test_live_match_without_external_id_makes_no_api_call(): void
    {
        // Match live ma senza MatchExternalId per API-Football
        FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHour(),
            'status'         => 'live',
        ]);

        Http::fake();

        $result = $this->service()->syncLive();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 7. FT non usa il live flow — syncLive ignora match finished
    // -------------------------------------------------------------------------

    public function test_finished_match_not_picked_up_by_sync_live(): void
    {
        $country  = Country::firstOrCreate(['name' => 'Italy'], ['football_code' => 'IT']);
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHours(2),
            'status'         => 'finished',
            'definitive_at'  => now()->subMinutes(15),
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '7007',
            'external_name'  => null,
        ]);

        Http::fake();

        $result = $this->service()->syncLive();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, MatchEvent::count());
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function service(): ApiFootballMatchEventSyncService
    {
        return app(ApiFootballMatchEventSyncService::class);
    }

    private function makeLiveMatch(string $extId): FootballMatch
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
            'external_id'    => $extId,
            'external_name'  => null,
        ]);

        return $match;
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
            'time'     => ['elapsed' => $elapsed, 'extra' => $extra],
            'team'     => ['id' => $teamId, 'name' => 'Team'],
            'player'   => ['id' => $playerId, 'name' => $playerName],
            'assist'   => ['id' => $assistId, 'name' => $assistName],
            'type'     => 'Goal',
            'detail'   => $detail,
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
            'time'     => ['elapsed' => $elapsed, 'extra' => $extra],
            'team'     => ['id' => $teamId, 'name' => 'Team'],
            'player'   => ['id' => $playerId, 'name' => $playerName],
            'assist'   => ['id' => null, 'name' => null],
            'type'     => 'Card',
            'detail'   => $detail,
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
            'time'     => ['elapsed' => $elapsed, 'extra' => $extra],
            'team'     => ['id' => $teamId, 'name' => 'Team'],
            'player'   => ['id' => $playerInId, 'name' => $playerInName],
            'assist'   => ['id' => $playerOutId, 'name' => $playerOutName],
            'type'     => 'subst',
            'detail'   => null,
            'comments' => null,
        ];
    }
}
