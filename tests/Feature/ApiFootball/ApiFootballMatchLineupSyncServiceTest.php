<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\MatchLineup;
use App\Models\MatchLineupPlayer;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballMatchLineupSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integration tests for ApiFootballMatchLineupSyncService.
 *
 * Rules under test:
 *  - One API call per fixture returns both teams' lineups.
 *  - startXI rows get is_starter=true; substitutes get is_starter=false.
 *  - formation, coach_external_id, coach_name are stored on match_lineups.
 *  - Repeated syncs are idempotent (UNIQUE constraints).
 *  - Stale players (removed from new API response) are deleted.
 *  - lineups_last_attempt_at is updated on every attempt, including empty/error.
 *  - lineups_fetched_at is updated only on valid non-empty parseable response.
 *  - Window: kickoff in [now-30m, now+75m], status NOT IN definitive statuses.
 *  - Throttle: skip matches whose lineups_last_attempt_at is within 15 minutes.
 *  - finished matches are excluded by syncPending.
 *  - lineups_fetched_at already set does NOT exclude a match from future polling.
 */
class ApiFootballMatchLineupSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOME_EXT_ID = '505';
    private const AWAY_EXT_ID = '489';
    private const FIXTURE_EXT_ID = '12345';

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
    // 1. startXI → is_starter=true, substitutes → is_starter=false
    // -------------------------------------------------------------------------

    public function test_startxi_and_substitutes_saved_with_correct_is_starter(): void
    {
        $match = $this->makeWindowMatch(self::FIXTURE_EXT_ID);

        Http::fake([
            '*fixtures/lineups*' => Http::response($this->lineupResponse(
                homeStartXI:   [$this->player(1001, 'Sommer', 1, 'G', '1:1')],
                homeSubstitutes: [$this->player(1099, 'Di Gennaro', 43, 'G', null)],
                awayStartXI:   [$this->player(2001, 'Maignan', 16, 'G', '1:1')],
                awaySubstitutes: [$this->player(2099, 'Mirante', 83, 'G', null)],
            ), 200),
        ]);

        $result = $this->service()->syncSingle($match, self::FIXTURE_EXT_ID);

        $this->assertSame('synced', $result['outcome']);
        $this->assertSame(1, $result['api_calls']);

        $homeLineup = MatchLineup::where('team_id', $this->homeTeam->id)->first();
        $this->assertNotNull($homeLineup);

        $starter = MatchLineupPlayer::where('match_lineup_id', $homeLineup->id)
            ->where('player_external_id', '1001')
            ->first();
        $this->assertNotNull($starter);
        $this->assertTrue($starter->is_starter, 'startXI player deve avere is_starter=true');

        $sub = MatchLineupPlayer::where('match_lineup_id', $homeLineup->id)
            ->where('player_external_id', '1099')
            ->first();
        $this->assertNotNull($sub);
        $this->assertFalse($sub->is_starter, 'Substitute deve avere is_starter=false');
    }

    // -------------------------------------------------------------------------
    // 2. formation + coach_external_id + coach_name salvati su match_lineups
    // -------------------------------------------------------------------------

    public function test_formation_and_coach_saved_on_match_lineup(): void
    {
        $match = $this->makeWindowMatch(self::FIXTURE_EXT_ID);

        Http::fake([
            '*fixtures/lineups*' => Http::response($this->lineupResponse(
                homeStartXI:    [$this->player(1001, 'Sommer', 1, 'G', '1:1')],
                homeSubstitutes: [],
                awayStartXI:    [$this->player(2001, 'Maignan', 16, 'G', '1:1')],
                awaySubstitutes: [],
                homeFormation:  '3-5-2',
                awayFormation:  '4-2-3-1',
                homeCoachId:    200,
                homeCoachName:  'Inzaghi S.',
                awayCoachId:    300,
                awayCoachName:  'Fonseca',
            ), 200),
        ]);

        $this->service()->syncSingle($match, self::FIXTURE_EXT_ID);

        $homeLineup = MatchLineup::where('team_id', $this->homeTeam->id)->first();
        $this->assertSame('3-5-2', $homeLineup->formation);
        $this->assertSame('200', $homeLineup->coach_external_id);
        $this->assertSame('Inzaghi S.', $homeLineup->coach_name);

        $awayLineup = MatchLineup::where('team_id', $this->awayTeam->id)->first();
        $this->assertSame('4-2-3-1', $awayLineup->formation);
        $this->assertSame('300', $awayLineup->coach_external_id);
        $this->assertSame('Fonseca', $awayLineup->coach_name);
    }

    // -------------------------------------------------------------------------
    // 3. Una sola chiamata API → entrambe le squadre salvate
    // -------------------------------------------------------------------------

    public function test_both_teams_saved_from_one_api_call(): void
    {
        $match = $this->makeWindowMatch(self::FIXTURE_EXT_ID);

        Http::fake([
            '*fixtures/lineups*' => Http::response($this->lineupResponse(
                homeStartXI:    [$this->player(1001, 'Sommer', 1, 'G', '1:1')],
                homeSubstitutes: [],
                awayStartXI:    [$this->player(2001, 'Maignan', 16, 'G', '1:1')],
                awaySubstitutes: [],
            ), 200),
        ]);

        $this->service()->syncSingle($match, self::FIXTURE_EXT_ID);

        $this->assertSame(2, MatchLineup::count(), 'Devono esserci esattamente 2 lineup (home + away)');
        Http::assertSentCount(1);
    }

    // -------------------------------------------------------------------------
    // 4. Secondo fetch identico → nessun duplicato
    // -------------------------------------------------------------------------

    public function test_second_identical_fetch_no_duplicates(): void
    {
        $match = $this->makeWindowMatch(self::FIXTURE_EXT_ID);

        Http::fake([
            '*fixtures/lineups*' => Http::response($this->lineupResponse(
                homeStartXI:    [$this->player(1001, 'Sommer', 1, 'G', '1:1')],
                homeSubstitutes: [$this->player(1099, 'Di Gennaro', 43, 'G', null)],
                awayStartXI:    [$this->player(2001, 'Maignan', 16, 'G', '1:1')],
                awaySubstitutes: [],
            ), 200),
        ]);

        $service = $this->service();
        $service->syncSingle($match, self::FIXTURE_EXT_ID);
        $service->syncSingle($match, self::FIXTURE_EXT_ID);

        $this->assertSame(2, MatchLineup::count());
        $this->assertSame(3, MatchLineupPlayer::count(), '1 home sub + 1 home starter + 1 away starter = 3');
        Http::assertSentCount(2);
    }

    // -------------------------------------------------------------------------
    // 5. Lineup cambiata (cambio formazione) → DB aggiornato
    // -------------------------------------------------------------------------

    public function test_lineup_change_updates_db(): void
    {
        $match = $this->makeWindowMatch(self::FIXTURE_EXT_ID);

        Http::fake([
            '*fixtures/lineups*' => Http::sequence()
                ->push($this->lineupResponse(
                    homeStartXI:   [$this->player(1001, 'Sommer', 1, 'G', '1:1')],
                    homeSubstitutes: [],
                    awayStartXI:   [$this->player(2001, 'Maignan', 16, 'G', '1:1')],
                    awaySubstitutes: [],
                    homeFormation: '3-5-2',
                    awayFormation: '4-2-3-1',
                ), 200)
                ->push($this->lineupResponse(
                    homeStartXI:   [$this->player(1001, 'Sommer', 1, 'G', '1:1')],
                    homeSubstitutes: [],
                    awayStartXI:   [$this->player(2001, 'Maignan', 16, 'G', '1:1')],
                    awaySubstitutes: [],
                    homeFormation: '4-3-3',
                    awayFormation: '4-4-2',
                ), 200),
        ]);

        $service = $this->service();
        $service->syncSingle($match, self::FIXTURE_EXT_ID);

        $homeLineup = MatchLineup::where('team_id', $this->homeTeam->id)->first();
        $this->assertSame('3-5-2', $homeLineup->formation);

        $service->syncSingle($match, self::FIXTURE_EXT_ID);

        $this->assertSame(2, MatchLineup::count(), 'Nessuna riga duplicata dopo aggiornamento');
        $homeLineup->refresh();
        $this->assertSame('4-3-3', $homeLineup->formation, 'Formazione deve essere aggiornata');
    }

    // -------------------------------------------------------------------------
    // 6. Giocatore rimosso dalla nuova lineup → riga stale eliminata
    // -------------------------------------------------------------------------

    public function test_stale_player_removed_on_new_lineup(): void
    {
        $match = $this->makeWindowMatch(self::FIXTURE_EXT_ID);

        Http::fake([
            '*fixtures/lineups*' => Http::sequence()
                ->push($this->lineupResponse(
                    homeStartXI:   [
                        $this->player(1001, 'Sommer', 1, 'G', '1:1'),
                        $this->player(1002, 'De Vrij', 6, 'D', '2:1'),
                    ],
                    homeSubstitutes: [],
                    awayStartXI:   [$this->player(2001, 'Maignan', 16, 'G', '1:1')],
                    awaySubstitutes: [],
                ), 200)
                ->push($this->lineupResponse(
                    homeStartXI:   [
                        $this->player(1001, 'Sommer', 1, 'G', '1:1'),
                        // 1002 rimosso, 1003 aggiunto
                        $this->player(1003, 'Bastoni', 95, 'D', '2:1'),
                    ],
                    homeSubstitutes: [],
                    awayStartXI:   [$this->player(2001, 'Maignan', 16, 'G', '1:1')],
                    awaySubstitutes: [],
                ), 200),
        ]);

        $service = $this->service();

        $service->syncSingle($match, self::FIXTURE_EXT_ID);
        $homeLineup = MatchLineup::where('team_id', $this->homeTeam->id)->first();
        $this->assertSame(2, MatchLineupPlayer::where('match_lineup_id', $homeLineup->id)->count());

        $service->syncSingle($match, self::FIXTURE_EXT_ID);
        $homeLineup->refresh();

        $playerExtIds = MatchLineupPlayer::where('match_lineup_id', $homeLineup->id)
            ->pluck('player_external_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['1001', '1003'], $playerExtIds, 'Il giocatore 1002 deve essere stato eliminato');
        $this->assertSame(2, MatchLineupPlayer::where('match_lineup_id', $homeLineup->id)->count());
    }

    // -------------------------------------------------------------------------
    // 7. Risposta [] → lineups_last_attempt_at aggiornato, lineups_fetched_at null
    // -------------------------------------------------------------------------

    public function test_empty_response_updates_last_attempt_not_fetched_at(): void
    {
        $match = $this->makeWindowMatch(self::FIXTURE_EXT_ID);

        Http::fake([
            '*fixtures/lineups*' => Http::response([
                'errors' => [], 'results' => 0, 'response' => [],
            ], 200),
        ]);

        $result = $this->service()->syncSingle($match, self::FIXTURE_EXT_ID);

        $this->assertSame('empty', $result['outcome']);
        $this->assertSame(1, $result['api_calls']);
        $this->assertSame(0, MatchLineup::count());

        $match->refresh();
        $this->assertNotNull($match->lineups_last_attempt_at, 'lineups_last_attempt_at deve essere impostato');
        $this->assertNull($match->lineups_fetched_at, 'lineups_fetched_at non deve essere impostato per risposta vuota');
    }

    // -------------------------------------------------------------------------
    // 8. HTTP failure → lineups_last_attempt_at aggiornato, lineups_fetched_at null, nessuna eccezione
    // -------------------------------------------------------------------------

    public function test_http_failure_updates_last_attempt_not_fetched_at(): void
    {
        $match = $this->makeWindowMatch(self::FIXTURE_EXT_ID);

        Http::fake([
            '*fixtures/lineups*' => Http::response([], 500),
        ]);

        $result = $this->service()->syncSingle($match, self::FIXTURE_EXT_ID);

        $this->assertSame('http_error', $result['outcome']);
        $this->assertSame(0, $result['api_calls']);
        $this->assertSame(0, MatchLineup::count());

        $match->refresh();
        $this->assertNotNull($match->lineups_last_attempt_at, 'lineups_last_attempt_at deve essere impostato anche su HTTP failure');
        $this->assertNull($match->lineups_fetched_at, 'lineups_fetched_at non deve essere impostato su HTTP failure');
    }

    // -------------------------------------------------------------------------
    // 9. Match dentro la finestra T-75/T+30 → candidato (syncPending lo trova)
    // -------------------------------------------------------------------------

    public function test_inside_window_is_candidate(): void
    {
        // kickoff in 30 min → now è T-30 → dentro la finestra
        $match = $this->makeWindowMatch(self::FIXTURE_EXT_ID, kickoffOffsetMinutes: 30);

        Http::fake([
            '*fixtures/lineups*' => Http::response($this->lineupResponse(
                homeStartXI:    [$this->player(1001, 'Sommer', 1, 'G', '1:1')],
                homeSubstitutes: [],
                awayStartXI:    [$this->player(2001, 'Maignan', 16, 'G', '1:1')],
                awaySubstitutes: [],
            ), 200),
        ]);

        $result = $this->service()->syncPending();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['synced']);
        $this->assertSame(1, $result['api_calls']);
    }

    // -------------------------------------------------------------------------
    // 10. Match fuori finestra (troppo presto) → zero call
    // -------------------------------------------------------------------------

    public function test_outside_window_too_early_not_candidate(): void
    {
        // kickoff tra 100 min → fuori finestra (limite +75m)
        $match = $this->makeWindowMatch(self::FIXTURE_EXT_ID, kickoffOffsetMinutes: 100);

        Http::fake();

        $result = $this->service()->syncPending();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 11. Throttle < 15m → zero call
    // -------------------------------------------------------------------------

    public function test_throttle_recent_attempt_not_candidate(): void
    {
        // kickoff in 30 min, dentro finestra, ma tentato 5 min fa → throttled
        $match = $this->makeWindowMatch(self::FIXTURE_EXT_ID, kickoffOffsetMinutes: 30);
        $match->update(['lineups_last_attempt_at' => now()->subMinutes(5)]);

        Http::fake();

        $result = $this->service()->syncPending();

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 12. Throttle scaduto (> 15m) → candidato
    // -------------------------------------------------------------------------

    public function test_throttle_expired_is_candidate(): void
    {
        // kickoff in 30 min, tentato 20 min fa → throttle scaduto
        $match = $this->makeWindowMatch(self::FIXTURE_EXT_ID, kickoffOffsetMinutes: 30);
        $match->update(['lineups_last_attempt_at' => now()->subMinutes(20)]);

        Http::fake([
            '*fixtures/lineups*' => Http::response($this->lineupResponse(
                homeStartXI:    [$this->player(1001, 'Sommer', 1, 'G', '1:1')],
                homeSubstitutes: [],
                awayStartXI:    [$this->player(2001, 'Maignan', 16, 'G', '1:1')],
                awaySubstitutes: [],
            ), 200),
        ]);

        $result = $this->service()->syncPending();

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['synced']);
    }

    // -------------------------------------------------------------------------
    // 13. Match finished → escluso da syncPending
    // -------------------------------------------------------------------------

    public function test_finished_match_excluded_from_sync_pending(): void
    {
        // Match status='finished' nella finestra temporale → deve essere escluso
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subMinutes(10),
            'status'         => 'finished',
            'definitive_at'  => now()->subMinutes(5),
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => self::FIXTURE_EXT_ID,
            'external_name'  => null,
        ]);

        Http::fake();

        $result = $this->service()->syncPending();

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 14. Match senza external ID → zero call, warning loggato
    // -------------------------------------------------------------------------

    public function test_no_external_id_zero_api_call(): void
    {
        // Match in finestra ma senza MatchExternalId per API-Football
        FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->addMinutes(30),
            'status'         => 'scheduled',
        ]);

        Http::fake();

        $result = $this->service()->syncPending();

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['api_calls']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 15. lineups_fetched_at già valorizzato NON impedisce il refresh pre-kickoff
    // -------------------------------------------------------------------------

    public function test_fetched_at_already_set_does_not_exclude_from_sync_pending(): void
    {
        // Match già sincronizzato (lineups_fetched_at valorizzato), ancora in finestra
        $match = $this->makeWindowMatch(self::FIXTURE_EXT_ID, kickoffOffsetMinutes: 30);
        $match->update(['lineups_fetched_at' => now()->subMinutes(20)]);

        Http::fake([
            '*fixtures/lineups*' => Http::response($this->lineupResponse(
                homeStartXI:    [$this->player(1001, 'Sommer', 1, 'G', '1:1')],
                homeSubstitutes: [],
                awayStartXI:    [$this->player(2001, 'Maignan', 16, 'G', '1:1')],
                awaySubstitutes: [],
            ), 200),
        ]);

        $result = $this->service()->syncPending();

        $this->assertSame(1, $result['candidates'], 'lineups_fetched_at non deve escludere dal polling');
        $this->assertSame(1, $result['synced']);

        $match->refresh();
        $this->assertNotNull($match->lineups_fetched_at);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function service(): ApiFootballMatchLineupSyncService
    {
        return app(ApiFootballMatchLineupSyncService::class);
    }

    /** Create a match inside the candidate window (scheduled, kickoff in $kickoffOffsetMinutes, with external ID). */
    private function makeWindowMatch(string $extId, int $kickoffOffsetMinutes = 30): FootballMatch
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->addMinutes($kickoffOffsetMinutes),
            'status'         => 'scheduled',
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => $extId,
            'external_name'  => null,
        ]);

        return $match;
    }

    /** Build a full two-team lineup API response. */
    private function lineupResponse(
        array  $homeStartXI,
        array  $homeSubstitutes,
        array  $awayStartXI,
        array  $awaySubstitutes,
        string $homeFormation = '4-3-3',
        string $awayFormation = '4-3-3',
        int    $homeCoachId = 100,
        string $homeCoachName = 'Inzaghi S.',
        int    $awayCoachId = 200,
        string $awayCoachName = 'Fonseca',
    ): array {
        return [
            'errors'  => [],
            'results' => 2,
            'response' => [
                $this->teamLineup(
                    (int) self::HOME_EXT_ID,
                    $homeFormation,
                    $homeCoachId,
                    $homeCoachName,
                    $homeStartXI,
                    $homeSubstitutes,
                ),
                $this->teamLineup(
                    (int) self::AWAY_EXT_ID,
                    $awayFormation,
                    $awayCoachId,
                    $awayCoachName,
                    $awayStartXI,
                    $awaySubstitutes,
                ),
            ],
        ];
    }

    private function teamLineup(
        int    $teamExtId,
        string $formation,
        int    $coachId,
        string $coachName,
        array  $startXI,
        array  $substitutes,
    ): array {
        return [
            'team'        => ['id' => $teamExtId, 'name' => 'Team'],
            'coach'       => ['id' => $coachId, 'name' => $coachName],
            'formation'   => $formation,
            'startXI'     => array_map(fn($p) => ['player' => $p], $startXI),
            'substitutes' => array_map(fn($p) => ['player' => $p], $substitutes),
        ];
    }

    /** Build a raw player array matching the API structure (no is_starter field). */
    private function player(int $id, string $name, int $number, string $pos, ?string $grid): array
    {
        return [
            'id'     => $id,
            'name'   => $name,
            'number' => $number,
            'pos'    => $pos,
            'grid'   => $grid,
        ];
    }
}
