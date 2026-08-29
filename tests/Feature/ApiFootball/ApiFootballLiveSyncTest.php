<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\Team;
use App\Services\DataSources\ApiFootball\ApiFootballResultRefreshService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiFootballLiveSyncTest extends TestCase
{
    use RefreshDatabase;

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

        $this->homeTeam = Team::create(['name' => 'Home FC', 'type' => 'club', 'is_active' => true]);
        $this->awayTeam = Team::create(['name' => 'Away FC', 'type' => 'club', 'is_active' => true]);
    }

    // -------------------------------------------------------------------------
    // 1. Prima metà (1H): current score + minuto + live_status; FT ancora null
    // -------------------------------------------------------------------------

    public function test_live_1h_sets_current_score_minute_status_and_leaves_ft_null(): void
    {
        $match = $this->makeMatchWithExtId(now()->subMinutes(23), 'scheduled', 9201);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->liveFixtureResponse(
                extId:    9201,
                short:    '1H',
                goalHome: 1,
                goalAway: 0,
                elapsed:  23,
                htHome:   null,
                htAway:   null,
                ftHome:   null,
                ftAway:   null,
            ),
            200,
        )]);

        $result = app(ApiFootballResultRefreshService::class)->refresh();

        $this->assertSame(1, $result['updated']);

        $match->refresh();
        $this->assertSame('live',  $match->status);
        $this->assertSame(1,       $match->current_home_score);
        $this->assertSame(0,       $match->current_away_score);
        $this->assertSame(23,      $match->live_minute);
        $this->assertSame('1H',    $match->live_status);
        // FT fields must remain null during live
        $this->assertNull($match->home_score_ft);
        $this->assertNull($match->away_score_ft);
    }

    // -------------------------------------------------------------------------
    // 2. Intervallo (HT): score HT scritto, current score corretto, live_minute
    // -------------------------------------------------------------------------

    public function test_live_ht_writes_halftime_score_and_current(): void
    {
        $match = $this->makeMatchWithExtId(now()->subMinutes(50), 'live', 9202);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->liveFixtureResponse(
                extId:    9202,
                short:    'HT',
                goalHome: 1,
                goalAway: 0,
                elapsed:  45,
                htHome:   1,
                htAway:   0,
                ftHome:   null,
                ftAway:   null,
            ),
            200,
        )]);

        app(ApiFootballResultRefreshService::class)->refresh();

        $match->refresh();
        $this->assertSame('live', $match->status);
        $this->assertSame(1,      $match->home_score_ht);
        $this->assertSame(0,      $match->away_score_ht);
        $this->assertSame(1,      $match->current_home_score);
        $this->assertSame(0,      $match->current_away_score);
        $this->assertSame(45,     $match->live_minute);
        $this->assertSame('HT',   $match->live_status);
        $this->assertNull($match->home_score_ft);
    }

    // -------------------------------------------------------------------------
    // 3. Seconda metà (2H): score e minuto aggiornati
    // -------------------------------------------------------------------------

    public function test_live_2h_updates_score_minute_status(): void
    {
        $match = $this->makeMatchWithExtId(now()->subMinutes(70), 'live', 9203);

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->liveFixtureResponse(
                extId:    9203,
                short:    '2H',
                goalHome: 2,
                goalAway: 1,
                elapsed:  67,
                htHome:   1,
                htAway:   0,
                ftHome:   null,
                ftAway:   null,
            ),
            200,
        )]);

        app(ApiFootballResultRefreshService::class)->refresh();

        $match->refresh();
        $this->assertSame('live', $match->status);
        $this->assertSame(2,      $match->current_home_score);
        $this->assertSame(1,      $match->current_away_score);
        $this->assertSame(67,     $match->live_minute);
        $this->assertSame('2H',   $match->live_status);
        $this->assertSame(1,      $match->home_score_ht);   // HT score preserved
        $this->assertNull($match->home_score_ft);
    }

    // -------------------------------------------------------------------------
    // 4. Finale (FT): home_score_ft da score.fulltime.*, NON da goals.*
    //    Prova esplicita: goals.home ≠ score.fulltime.home
    // -------------------------------------------------------------------------

    public function test_ft_uses_score_fulltime_not_goals_and_nulls_live_minute(): void
    {
        $match = $this->makeMatchWithExtId(now()->subMinutes(95), 'live', 9204);

        // Deliberately set goals.home = 3 but score.fulltime.home = 2
        // to prove home_score_ft comes from score.fulltime, not goals.
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response(
            $this->liveFixtureResponse(
                extId:    9204,
                short:    'FT',
                goalHome: 3,  // goals.home — must NOT land in home_score_ft
                goalAway: 1,
                elapsed:  90,
                htHome:   1,
                htAway:   0,
                ftHome:   2,  // score.fulltime.home — this must be home_score_ft
                ftAway:   1,
            ),
            200,
        )]);

        app(ApiFootballResultRefreshService::class)->refresh();

        $match->refresh();
        $this->assertSame('finished', $match->status);
        // FT score from score.fulltime, not goals
        $this->assertSame(2, $match->home_score_ft, 'home_score_ft deve venire da score.fulltime.home');
        $this->assertSame(1, $match->away_score_ft);
        // current_* reflects goals.* as-is
        $this->assertSame(3, $match->current_home_score, 'current_home_score deve venire da goals.home');
        $this->assertSame(1, $match->current_away_score);
        // live_minute must be null for definitive status
        $this->assertNull($match->live_minute, 'live_minute deve essere null per uno stato definitivo');
        $this->assertSame('FT', $match->live_status);
    }

    // -------------------------------------------------------------------------
    // 5. Idempotenza: stesso stato live inviato due volte → seconda = unchanged
    // -------------------------------------------------------------------------

    public function test_live_idempotent_same_data_second_refresh_unchanged(): void
    {
        $match = $this->makeMatchWithExtId(now()->subMinutes(70), 'live', 9205);

        $response = $this->liveFixtureResponse(
            extId:    9205,
            short:    '2H',
            goalHome: 2,
            goalAway: 1,
            elapsed:  67,
            htHome:   1,
            htAway:   0,
            ftHome:   null,
            ftAway:   null,
        );

        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response($response, 200)]);

        $result1 = app(ApiFootballResultRefreshService::class)->refresh();
        $this->assertSame(1, $result1['updated']);

        // Second call with identical response
        Http::fake(['v3.football.api-sports.io/fixtures*' => Http::response($response, 200)]);

        $result2 = app(ApiFootballResultRefreshService::class)->refresh();
        $this->assertSame(0, $result2['updated']);
        $this->assertSame(1, $result2['unchanged']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeMatchWithExtId(mixed $kickoffAt, string $status, int $extId): FootballMatch
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => $kickoffAt,
            'status'         => $status,
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => (string) $extId,
            'external_name'  => null,
        ]);

        return $match;
    }

    /**
     * Flexible fixture response for live/FT scenarios.
     * goals.* and score.* are independent so tests can prove the mapping is correct.
     */
    private function liveFixtureResponse(
        int    $extId,
        string $short,
        ?int   $goalHome,
        ?int   $goalAway,
        ?int   $elapsed  = null,
        ?int   $htHome   = null,
        ?int   $htAway   = null,
        ?int   $ftHome   = null,
        ?int   $ftAway   = null,
    ): array {
        return [
            'errors'   => [],
            'results'  => 1,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [[
                'fixture' => [
                    'id'       => $extId,
                    'timezone' => 'UTC',
                    'date'     => now()->subHour()->toIso8601String(),
                    'status'   => ['short' => $short, 'elapsed' => $elapsed],
                    'venue'    => ['name' => 'Test Stadium'],
                ],
                'league' => [
                    'id'     => 135,
                    'name'   => 'Serie A',
                    'season' => 2026,
                    'round'  => 'Regular Season - 1',
                ],
                'teams' => [
                    'home' => ['id' => 505, 'name' => 'Home FC', 'winner' => null],
                    'away' => ['id' => 489, 'name' => 'Away FC', 'winner' => null],
                ],
                'goals' => ['home' => $goalHome, 'away' => $goalAway],
                'score' => [
                    'halftime'  => ['home' => $htHome, 'away' => $htAway],
                    'fulltime'  => ['home' => $ftHome, 'away' => $ftAway],
                    'extratime' => ['home' => null,    'away' => null],
                    'penalty'   => ['home' => null,    'away' => null],
                ],
            ]],
        ];
    }
}
