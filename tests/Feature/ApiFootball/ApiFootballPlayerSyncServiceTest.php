<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\SeasonPlayer;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballPlayerSyncService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiFootballPlayerSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private DataSource  $ds;
    private Competition $competition;
    private Season      $season;
    private Team        $inter;
    private Team        $milan;

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

        $this->inter = Team::create([
            'country_id' => $country->id,
            'name'       => 'Internazionale',
            'code'       => 'INT',
            'type'       => 'club',
            'is_active'  => true,
        ]);
        TeamExternalId::create([
            'team_id'        => $this->inter->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '505',
            'external_name'  => 'Internazionale',
        ]);

        $this->milan = Team::create([
            'country_id' => $country->id,
            'name'       => 'AC Milan',
            'code'       => 'MIL',
            'type'       => 'club',
            'is_active'  => true,
        ]);
        TeamExternalId::create([
            'team_id'        => $this->milan->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '489',
            'external_name'  => 'AC Milan',
        ]);

        // Both teams in the season
        DB::table('season_team')->insert([
            ['season_id' => $this->season->id, 'team_id' => $this->inter->id, 'created_at' => now(), 'updated_at' => now()],
            ['season_id' => $this->season->id, 'team_id' => $this->milan->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    // -------------------------------------------------------------------------
    // Happy path — create
    // -------------------------------------------------------------------------

    public function test_sync_season_creates_players_and_external_ids(): void
    {
        // Both Inter and Milan receive the same 2-player response.
        // Inter processes first → 2 players created.
        // Milan processes second → same API IDs already exist → 2 players updated.
        // season_player: 4 rows (each player × 2 teams).
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->oneTeamResponse([
                $this->playerItem(19241, 'L. Martinez', 'Lautaro Javier', 'Martinez', 'Attacker'),
                $this->playerItem(9895,  'R. Lukaku',   'Romelu',         'Lukaku',   'Attacker'),
            ]),
            200,
            ['x-ratelimit-requests-remaining' => '7400', 'X-RateLimit-Remaining' => '299'],
        )]);

        $result = $this->service()->syncSeason(2026);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['teams_processed']);
        $this->assertSame(2, $result['players_created']);   // unique players (same IDs across teams)
        $this->assertSame(2, $result['players_updated']);   // second team re-processes same players
        $this->assertSame(4, $result['memberships_created']); // 2 players × 2 teams
        $this->assertSame(0, $result['memberships_unchanged']);

        $this->assertSame(2, Player::count());
        $this->assertSame(2, PlayerExternalId::count());
        $this->assertSame(4, SeasonPlayer::count()); // 2 players × 2 teams
    }

    public function test_sync_season_stores_correct_player_master_data(): void
    {
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([
                $this->playerItem(19241, 'L. Martinez', 'Lautaro Javier', 'Martinez', 'Attacker',
                    height: '174 cm', weight: '75 kg', nationality: 'Argentina', birthDate: '1997-08-22'),
            ]),
            200,
        )]);

        $this->service()->syncSeason(2026, 'serie-a');

        $player = Player::firstOrFail();

        $this->assertSame('Lautaro Javier Martinez', $player->name);
        $this->assertSame('Lautaro Javier', $player->firstname);
        $this->assertSame('Martinez', $player->lastname);
        $this->assertSame('1997-08-22', $player->birth_date->toDateString());
        $this->assertSame('Argentina', $player->nationality);
        $this->assertSame(174, $player->height_cm);
        $this->assertSame(75,  $player->weight_kg);
        $this->assertSame('attacker', $player->position);
        $this->assertStringContainsString('19241', $player->photo_url);
    }

    public function test_sync_season_stores_external_id_mapping(): void
    {
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([$this->playerItem(19241, 'L. Martinez', 'Lautaro Javier', 'Martinez', 'Attacker')]),
            200,
        )]);

        $this->service()->syncSeason(2026, 'serie-a');

        $this->assertDatabaseHas('player_external_ids', [
            'external_id'    => '19241',
            'data_source_id' => $this->ds->id,
            'external_name'  => 'L. Martinez',
        ]);
    }

    public function test_sync_season_creates_season_player_membership(): void
    {
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([$this->playerItem(19241, 'L. Martinez', 'Lautaro Javier', 'Martinez', 'Attacker')]),
            200,
        )]);

        $this->service()->syncSeason(2026, 'serie-a');

        $player = Player::firstOrFail();
        $membership = SeasonPlayer::firstOrFail();

        $this->assertSame($this->season->id, $membership->season_id);
        $this->assertSame($this->inter->id,  $membership->team_id);
        $this->assertSame($player->id,       $membership->player_id);
        $this->assertSame('attacker',        $membership->position);
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_sync_season_is_idempotent(): void
    {
        // Same response repeated for both teams on both runs.
        // Run 1: Inter creates player, Milan updates it. 2 memberships created (one per team).
        // Run 2: Both teams update. 2 memberships unchanged.
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([$this->playerItem(19241, 'L. Martinez', 'Lautaro Javier', 'Martinez', 'Attacker')]),
            200,
        )]);

        $service = $this->service();
        $service->syncSeason(2026, 'serie-a');
        $result2 = $service->syncSeason(2026, 'serie-a');

        $this->assertSame(1, Player::count());
        $this->assertSame(1, PlayerExternalId::count());
        $this->assertSame(2, SeasonPlayer::count()); // one membership per team

        $this->assertSame(0, $result2['players_created']);
        $this->assertSame(2, $result2['players_updated']);    // 2 teams process same player
        $this->assertSame(0, $result2['memberships_created']);
        $this->assertSame(2, $result2['memberships_unchanged']); // 2 teams, same player
    }

    public function test_sync_season_updates_player_master_data_on_second_run(): void
    {
        $original = $this->onlyInterResponse([
            $this->playerItem(19241, 'L. Martinez', 'Lautaro Javier', 'Martinez', 'Attacker', nationality: 'Argentina'),
        ]);
        $updated = $this->onlyInterResponse([
            $this->playerItem(19241, 'L. Martinez', 'Lautaro Javier', 'Martinez', 'Attacker', nationality: 'Italy'),
        ]);

        // 2 teams × 2 runs = 4 requests consumed from the sequence.
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::sequence()
                ->push($original, 200) // Inter  — run 1
                ->push($original, 200) // Milan  — run 1
                ->push($updated, 200)  // Inter  — run 2
                ->push($updated, 200), // Milan  — run 2
        ]);

        $service = $this->service();
        $service->syncSeason(2026, 'serie-a');
        $service->syncSeason(2026, 'serie-a');

        $player = Player::firstOrFail();
        $this->assertSame('Italy', $player->nationality);
    }

    // -------------------------------------------------------------------------
    // Transfer scenario — same player in two teams
    // -------------------------------------------------------------------------

    public function test_player_in_two_teams_has_one_external_id_two_memberships(): void
    {
        // Player 19241 appears for both Inter (505) and Milan (489)
        Http::fake(function ($request) {
            $url = $request->url();
            $items = [$this->playerItem(19241, 'L. Martinez', 'Lautaro Javier', 'Martinez', 'Attacker')];

            if (str_contains($url, 'team=505')) {
                return Http::response($this->oneTeamResponse($items), 200);
            }
            return Http::response($this->oneTeamResponse($items), 200);
        });

        $this->service()->syncSeason(2026);

        $this->assertSame(1, Player::count());
        $this->assertSame(1, PlayerExternalId::count());
        $this->assertSame(2, SeasonPlayer::count()); // one row per team

        $this->assertDatabaseHas('season_player', [
            'team_id' => $this->inter->id,
            'player_id' => Player::value('id'),
        ]);
        $this->assertDatabaseHas('season_player', [
            'team_id' => $this->milan->id,
            'player_id' => Player::value('id'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Position normalization
    // -------------------------------------------------------------------------

    public function test_position_goalkeeper(): void
    {
        $this->assertNormalizedPosition('Goalkeeper', 'goalkeeper');
    }

    public function test_position_defender(): void
    {
        $this->assertNormalizedPosition('Defender', 'defender');
    }

    public function test_position_midfielder(): void
    {
        $this->assertNormalizedPosition('Midfielder', 'midfielder');
    }

    public function test_position_attacker_from_attacker(): void
    {
        $this->assertNormalizedPosition('Attacker', 'attacker');
    }

    public function test_position_attacker_from_forward(): void
    {
        $this->assertNormalizedPosition('Forward', 'attacker');
    }

    public function test_position_attacker_from_letter_f(): void
    {
        $this->assertNormalizedPosition('F', 'attacker');
    }

    public function test_position_unknown_is_null(): void
    {
        $this->assertNormalizedPosition('Winger', null);
    }

    public function test_player_with_empty_statistics_gets_null_position(): void
    {
        $item = $this->playerItem(19241, 'L. Martinez', 'Lautaro Javier', 'Martinez', 'Attacker');
        $item['statistics'] = [];

        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([$item]),
            200,
        )]);

        $this->service()->syncSeason(2026, 'serie-a');

        $player = Player::firstOrFail();
        $this->assertNull($player->position);

        $membership = SeasonPlayer::firstOrFail();
        $this->assertNull($membership->position);
    }

    // -------------------------------------------------------------------------
    // Height / weight parsing
    // -------------------------------------------------------------------------

    public function test_height_weight_parsed_from_string(): void
    {
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([
                $this->playerItem(19241, 'L. Martinez', 'Lautaro', 'Martinez', 'Attacker', height: '184 cm', weight: '78 kg'),
            ]),
            200,
        )]);

        $this->service()->syncSeason(2026, 'serie-a');

        $player = Player::firstOrFail();
        $this->assertSame(184, $player->height_cm);
        $this->assertSame(78,  $player->weight_kg);
    }

    public function test_height_weight_null_when_missing(): void
    {
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([
                $this->playerItem(19241, 'L. Martinez', 'Lautaro', 'Martinez', 'Attacker', height: null, weight: null),
            ]),
            200,
        )]);

        $this->service()->syncSeason(2026, 'serie-a');

        $player = Player::firstOrFail();
        $this->assertNull($player->height_cm);
        $this->assertNull($player->weight_kg);
    }

    // -------------------------------------------------------------------------
    // Name construction
    // -------------------------------------------------------------------------

    public function test_name_built_from_firstname_lastname(): void
    {
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([
                $this->playerItem(19241, 'L. Martinez', 'Lautaro Javier', 'Martinez', 'Attacker'),
            ]),
            200,
        )]);

        $this->service()->syncSeason(2026, 'serie-a');
        $this->assertSame('Lautaro Javier Martinez', Player::value('name'));
    }

    public function test_name_falls_back_to_api_name_when_no_firstname_lastname(): void
    {
        $item = $this->playerItem(99, 'Pelé', '', '', 'Attacker');
        $item['player']['firstname'] = '';
        $item['player']['lastname']  = '';

        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([$item]),
            200,
        )]);

        $this->service()->syncSeason(2026, 'serie-a');
        $this->assertSame('Pelé', Player::value('name'));
    }

    // -------------------------------------------------------------------------
    // Paging
    // -------------------------------------------------------------------------

    public function test_paging_fetches_all_pages(): void
    {
        // Inter has 2 pages. Milan has 1 page (empty).
        // Requests consumed in order: Inter p1, Inter p2, Milan p1.
        $interPage1  = $this->oneTeamResponse(
            [$this->playerItem(19241, 'L. Martinez', 'Lautaro Javier', 'Martinez', 'Attacker')],
            page: 1, total: 2
        );
        $interPage2  = $this->oneTeamResponse(
            [$this->playerItem(9895, 'R. Lukaku', 'Romelu', 'Lukaku', 'Attacker')],
            page: 2, total: 2
        );
        $milanEmpty  = $this->oneTeamResponse([]);

        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::sequence()
                ->push($interPage1, 200)
                ->push($interPage2, 200)
                ->push($milanEmpty, 200),
        ]);

        $result = $this->service()->syncSeason(2026, 'serie-a');

        $this->assertSame(2, $result['players_created']);
        $this->assertSame(5, $result['api_calls']); // 2+1 for /players, + 1 squads supplement per team (2)
        $this->assertSame(2, Player::count());
    }

    // -------------------------------------------------------------------------
    // DataSyncRun
    // -------------------------------------------------------------------------

    public function test_sync_records_data_sync_run(): void
    {
        // serie-a has 2 teams → 2 API calls total.
        // Inter creates the player; Milan updates (same ID) → created_count = 1.
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([$this->playerItem(19241, 'L. Martinez', 'Lautaro', 'Martinez', 'Attacker')]),
            200,
            ['x-ratelimit-requests-remaining' => '7200'],
        )]);

        $this->service()->syncSeason(2026, 'serie-a');

        $run = DataSyncRun::firstOrFail();
        $this->assertSame('player_sync', $run->sync_type);
        $this->assertSame($this->ds->id, $run->data_source_id);
        $this->assertSame('ok', $run->status);
        $this->assertSame(1, $run->created_count);
        $this->assertSame(4, $run->api_calls); // (Inter + Milan) /players + (Inter + Milan) squads supplement
    }

    // -------------------------------------------------------------------------
    // League slug filter
    // -------------------------------------------------------------------------

    public function test_league_slug_filter_limits_to_one_competition(): void
    {
        $country2 = Country::create(['name' => 'England', 'football_code' => 'ENG']);
        $pl = Competition::create([
            'country_id' => $country2->id,
            'name'       => 'Premier League',
            'slug'       => 'premier-league',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $plSeason = Season::create([
            'competition_id' => $pl->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);
        SeasonExternalId::create([
            'season_id'      => $plSeason->id,
            'competition_id' => $pl->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '2026',
        ]);

        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([$this->playerItem(19241, 'L. Martinez', 'Lautaro', 'Martinez', 'Attacker')]),
            200,
        )]);

        // With slug filter: only Serie A processed (PL season has no teams in season_team).
        // Serie A has 2 teams (Inter + Milan) → teams_processed = 2.
        $result = $this->service()->syncSeason(2026, 'serie-a');
        $this->assertSame(2, $result['teams_processed']);
    }

    // -------------------------------------------------------------------------
    // No external ID for team — warning, no skip of other teams
    // -------------------------------------------------------------------------

    public function test_team_without_external_id_is_skipped_with_warning(): void
    {
        // Remove Milan's external ID
        TeamExternalId::where('team_id', $this->milan->id)->delete();

        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([$this->playerItem(19241, 'L. Martinez', 'Lautaro', 'Martinez', 'Attacker')]),
            200,
        )]);

        $result = $this->service()->syncSeason(2026);

        $this->assertSame(1, $result['teams_processed']);    // Inter only
        $this->assertSame(1, $result['players_created']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('AC Milan', $result['warnings'][0]);
    }

    // -------------------------------------------------------------------------
    // API error — aborts that team's remaining pages, other teams continue
    // -------------------------------------------------------------------------

    public function test_api_error_for_one_team_is_logged_as_warning(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'team=505')) {
                return Http::response([], 500);
            }
            return Http::response(
                $this->oneTeamResponse([$this->playerItem(9895, 'R. Lukaku', 'Romelu', 'Lukaku', 'Attacker')]),
                200
            );
        });

        $result = $this->service()->syncSeason(2026);

        // Milan succeeds, Inter fails
        $this->assertSame(1, $result['players_created']);
        $this->assertNotEmpty($result['warnings']);
    }

    // -------------------------------------------------------------------------
    // Artisan command
    // -------------------------------------------------------------------------

    public function test_artisan_command_runs_successfully(): void
    {
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([$this->playerItem(19241, 'L. Martinez', 'Lautaro', 'Martinez', 'Attacker')]),
            200,
        )]);

        $this->artisan('robetting:sync-api-football-players --season=2026 --league=serie-a')
            ->assertSuccessful();

        $this->assertSame(1, Player::count());
    }

    public function test_artisan_command_default_season_is_accepted(): void
    {
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([]),
            200,
        )]);

        $this->artisan('robetting:sync-api-football-players')
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // Current-roster supplement (/players/squads) — Availability V1 Step 4H
    // -------------------------------------------------------------------------

    public function test_squad_supplement_creates_player_missing_from_stats_endpoint(): void
    {
        // /players?team&season has nothing for Inter this season (no stats yet),
        // but /players/squads shows one current-roster player (id=63934).
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response(
                $this->squadResponse([$this->squadItem(63934, 'A. Furlanetto', 'Goalkeeper')]),
                200,
            ),
            'v3.football.api-sports.io/players?*' => Http::response($this->onlyInterResponse([]), 200),
        ]);

        $this->service()->syncSeason(2026, 'serie-a');

        $this->assertDatabaseHas('players', ['name' => 'A. Furlanetto']);
        $this->assertDatabaseHas('player_external_ids', [
            'external_id'    => '63934',
            'data_source_id' => $this->ds->id,
            'external_name'  => 'A. Furlanetto',
        ]);

        $player = Player::where('name', 'A. Furlanetto')->firstOrFail();
        $this->assertSame('goalkeeper', $player->position);

        $this->assertDatabaseHas('season_player', [
            'season_id' => $this->season->id,
            'team_id'   => $this->inter->id,
            'player_id' => $player->id,
            'position'  => 'goalkeeper',
        ]);
    }

    public function test_squad_supplement_never_updates_existing_player_master_data(): void
    {
        // Player already exists via the stats endpoint with rich master data.
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response(
                $this->squadResponse([$this->squadItem(19241, 'Totally Different Name', 'Defender')]),
                200,
            ),
            'v3.football.api-sports.io/players?*' => Http::response(
                $this->onlyInterResponse([
                    $this->playerItem(19241, 'L. Martinez', 'Lautaro Javier', 'Martinez', 'Attacker'),
                ]),
                200,
            ),
        ]);

        $this->service()->syncSeason(2026, 'serie-a');

        $this->assertSame(1, Player::count());
        $player = Player::firstOrFail();
        // Master data stays exactly what the stats endpoint wrote — the squad
        // supplement never calls update(), even though its own payload has a
        // different name/position for the SAME api id.
        $this->assertSame('Lautaro Javier Martinez', $player->name);
        $this->assertSame('attacker', $player->position);
    }

    public function test_squad_supplement_identity_is_by_player_id_never_by_name(): void
    {
        // Stats endpoint creates "M. Kean" (id=877). Squad supplement lists a
        // DIFFERENT id (999) with the SAME display name "M. Kean" — must be
        // treated as a distinct player, never merged by name.
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response(
                $this->squadResponse([$this->squadItem(999, 'M. Kean', 'Attacker')]),
                200,
            ),
            'v3.football.api-sports.io/players?*' => Http::response(
                $this->onlyInterResponse([
                    $this->playerItem(877, 'M. Kean', 'Moise', 'Kean', 'Attacker'),
                ]),
                200,
            ),
        ]);

        $this->service()->syncSeason(2026, 'serie-a');

        $this->assertSame(2, Player::count());
        $this->assertDatabaseHas('player_external_ids', ['external_id' => '877', 'data_source_id' => $this->ds->id]);
        $this->assertDatabaseHas('player_external_ids', ['external_id' => '999', 'data_source_id' => $this->ds->id]);
    }

    public function test_squad_supplement_is_idempotent_on_second_run(): void
    {
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response(
                $this->squadResponse([$this->squadItem(63934, 'A. Furlanetto', 'Goalkeeper')]),
                200,
            ),
            'v3.football.api-sports.io/players?*' => Http::response($this->onlyInterResponse([]), 200),
        ]);

        $service = $this->service();
        $service->syncSeason(2026, 'serie-a');
        $result2 = $service->syncSeason(2026, 'serie-a');

        $this->assertSame(1, Player::where('name', 'A. Furlanetto')->count());
        $this->assertSame(1, PlayerExternalId::where('external_id', '63934')->count());
        $this->assertSame(2, SeasonPlayer::count()); // Inter + Milan, both from the squad supplement
        $this->assertSame(0, $result2['players_created']);
    }

    public function test_squad_supplement_api_error_is_logged_as_warning_without_aborting(): void
    {
        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response([], 500),
            'v3.football.api-sports.io/players?*' => Http::response(
                $this->onlyInterResponse([
                    $this->playerItem(19241, 'L. Martinez', 'Lautaro Javier', 'Martinez', 'Attacker'),
                ]),
                200,
            ),
        ]);

        $result = $this->service()->syncSeason(2026, 'serie-a');

        $this->assertSame(1, $result['players_created']); // stats-endpoint player still created
        $this->assertNotEmpty($result['warnings']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function service(): ApiFootballPlayerSyncService
    {
        return app(ApiFootballPlayerSyncService::class);
    }

    /** Full response with N players, paging.total = 1 by default. */
    private function oneTeamResponse(array $players, int $page = 1, int $total = 1): array
    {
        return [
            'errors'   => [],
            'results'  => count($players),
            'paging'   => ['current' => $page, 'total' => $total],
            'response' => $players,
        ];
    }

    /** Response for Inter only (same structure, used when both teams would get same fake). */
    private function onlyInterResponse(array $players, int $page = 1, int $total = 1): array
    {
        return $this->oneTeamResponse($players, $page, $total);
    }

    /** Shape of /players/squads?team= with an empty current squad. */
    private function emptySquadResponse(): array
    {
        return $this->squadResponse([]);
    }

    /** Shape of /players/squads?team= with the given current-squad players. */
    private function squadResponse(array $players): array
    {
        return [
            'errors'   => [],
            'results'  => 1,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [['team' => ['id' => 0, 'name' => ''], 'players' => $players]],
        ];
    }

    /** One /players/squads current-squad entry. */
    private function squadItem(int $id, string $name, string $position): array
    {
        return ['id' => $id, 'name' => $name, 'age' => 0, 'number' => null, 'position' => $position, 'photo' => ''];
    }

    private function playerItem(
        int     $id,
        string  $name,
        string  $firstname,
        string  $lastname,
        string  $position,
        ?string $height      = '184 cm',
        ?string $weight      = '78 kg',
        string  $nationality = 'Argentina',
        string  $birthDate   = '1997-08-22',
    ): array {
        return [
            'player' => [
                'id'          => $id,
                'name'        => $name,
                'firstname'   => $firstname,
                'lastname'    => $lastname,
                'age'         => 27,
                'birth'       => [
                    'date'    => $birthDate,
                    'place'   => 'Buenos Aires',
                    'country' => $nationality,
                ],
                'nationality' => $nationality,
                'height'      => $height,
                'weight'      => $weight,
                'injured'     => false,
                'photo'       => "https://media.api-sports.io/football/players/{$id}.jpg",
            ],
            'statistics' => [
                [
                    'team'   => ['id' => 505, 'name' => 'Inter'],
                    'league' => ['id' => 135, 'name' => 'Serie A', 'season' => 2026],
                    'games'  => [
                        'appearences' => 10,
                        'lineups'     => 10,
                        'minutes'     => 900,
                        'number'      => null,
                        'position'    => $position,
                        'rating'      => '7.5',
                        'captain'     => false,
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Position normalisation helper
    // -------------------------------------------------------------------------

    private function assertNormalizedPosition(string $rawPosition, ?string $expected): void
    {
        $item = $this->playerItem(99, 'Test Player', 'Test', 'Player', $rawPosition);

        Http::fake([
            'v3.football.api-sports.io/players/squads*' => Http::response($this->emptySquadResponse(), 200),
            'v3.football.api-sports.io/players?*' => Http::response(
            $this->onlyInterResponse([$item]),
            200,
        )]);

        $this->service()->syncSeason(2026, 'serie-a');

        $player = Player::orderByDesc('id')->first();
        $this->assertSame($expected, $player->position, "Expected position '{$expected}' for raw '{$rawPosition}'");
    }
}
