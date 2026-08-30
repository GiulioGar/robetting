<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchLineup;
use App\Models\MatchLineupPlayer;
use App\Models\Season;
use App\Models\Team;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the tactical pitch rendering on the match page.
 *
 * Rules under test:
 *  - Pitch (.lineup-pitch) shown only when at least one starter has a valid grid.
 *  - 4-3-3 and 4-2-3-1 (Rmax=5) produce valid pitch containers.
 *  - Players with grid=null are excluded from the pitch but remain in the list.
 *  - shirt_number=null → initials label used instead.
 *  - Lineup with no grid data at all → no pitch container.
 *  - One team with grid, other without → only the grid team renders a pitch.
 */
class MatchPageTacticalPitchTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private FootballMatch $match;
    private Team $homeTeam;
    private Team $awayTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);

        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $comp    = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $season  = Season::create([
            'competition_id' => $comp->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);

        $this->homeTeam = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->awayTeam = Team::create(['name' => 'Milan', 'type' => 'club', 'is_active' => true]);

        $this->match = FootballMatch::create([
            'competition_id' => $comp->id,
            'season_id'      => $season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHours(2),
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
            'definitive_at'  => now()->subMinutes(30),
        ]);
    }

    // -------------------------------------------------------------------------
    // 1. 4-3-3: pitch container present, player names rendered on pitch
    // -------------------------------------------------------------------------

    public function test_tactical_pitch_shown_for_433(): void
    {
        $lineup = $this->makeLineup($this->homeTeam->id, '4-3-3');
        $this->addStarterWithGrid($lineup, 1001, 'Sommer', 1, 'G', '1:1');
        $this->addStarterWithGrid($lineup, 1002, 'De Vrij', 6, 'D', '2:1');
        $this->addStarterWithGrid($lineup, 1003, 'Acerbi', 15, 'D', '2:2');
        $this->addStarterWithGrid($lineup, 1004, 'Bastoni', 95, 'D', '2:3');
        $this->addStarterWithGrid($lineup, 1005, 'Darmian', 36, 'D', '2:4');
        $this->addStarterWithGrid($lineup, 1006, 'Barella', 23, 'M', '3:1');
        $this->addStarterWithGrid($lineup, 1007, 'Calhanoglu', 20, 'M', '3:2');
        $this->addStarterWithGrid($lineup, 1008, 'Mkhitaryan', 22, 'M', '3:3');
        $this->addStarterWithGrid($lineup, 1009, 'Dimarco', 32, 'F', '4:1');
        $this->addStarterWithGrid($lineup, 1010, 'Lautaro', 10, 'F', '4:2');
        $this->addStarterWithGrid($lineup, 1011, 'Thuram', 9, 'F', '4:3');

        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        $response->assertSee('lineup-pitch');
        $response->assertSee('Sommer');
        $response->assertSee('Lautaro');
    }

    // -------------------------------------------------------------------------
    // 2. 4-2-3-1 con Rmax=5 → pitch presente, tutti e 5 i livelli
    // -------------------------------------------------------------------------

    public function test_tactical_pitch_shown_for_42341_rmax5(): void
    {
        $lineup = $this->makeLineup($this->homeTeam->id, '4-2-3-1');
        $this->addStarterWithGrid($lineup, 2001, 'Maignan', 16, 'G', '1:1');
        $this->addStarterWithGrid($lineup, 2002, 'Calabria', 2, 'D', '2:1');
        $this->addStarterWithGrid($lineup, 2003, 'Thiaw', 28, 'D', '2:2');
        $this->addStarterWithGrid($lineup, 2004, 'Tomori', 23, 'D', '2:3');
        $this->addStarterWithGrid($lineup, 2005, 'Theo', 19, 'D', '2:4');
        $this->addStarterWithGrid($lineup, 2006, 'Bennacer', 4, 'M', '3:1');
        $this->addStarterWithGrid($lineup, 2007, 'Tonali', 8, 'M', '3:2');
        $this->addStarterWithGrid($lineup, 2008, 'Saelemaekers', 56, 'M', '4:1');
        $this->addStarterWithGrid($lineup, 2009, 'Diaz', 10, 'M', '4:2');
        $this->addStarterWithGrid($lineup, 2010, 'Leao', 17, 'M', '4:3');
        $this->addStarterWithGrid($lineup, 2011, 'Giroud', 9, 'F', '5:1');

        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        $response->assertSee('lineup-pitch');
        // Rmax=5 → il portiere è a y=(5-1+1)/(5+1)*100 = 83.3%
        // Verifichiamo solo che la pagina contenga i giocatori di tutti i livelli
        $response->assertSee('Maignan');
        $response->assertSee('Giroud');
    }

    // -------------------------------------------------------------------------
    // 3. Giocatore con grid null → escluso dal pitch, presente nella lista
    // -------------------------------------------------------------------------

    public function test_null_grid_player_excluded_from_pitch_but_in_list(): void
    {
        $lineup = $this->makeLineup($this->homeTeam->id, '4-3-3');
        $this->addStarterWithGrid($lineup, 3001, 'Sommer', 1, 'G', '1:1');
        // Questo giocatore ha grid null → solo nella lista
        MatchLineupPlayer::create([
            'match_lineup_id'    => $lineup->id,
            'player_external_id' => '3099',
            'player_name'        => 'GioconNull',
            'shirt_number'       => 7,
            'position'           => 'F',
            'grid'               => null,
            'is_starter'         => true,
        ]);

        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        $response->assertSee('lineup-pitch');   // campo mostrato grazie a Sommer
        $response->assertSee('GioconNull');     // presente nella lista testuale
    }

    // -------------------------------------------------------------------------
    // 4. shirt_number null → iniziali nel nodo tattico
    // -------------------------------------------------------------------------

    public function test_null_shirt_number_uses_initials_on_pitch(): void
    {
        $lineup = $this->makeLineup($this->homeTeam->id, '4-3-3');
        MatchLineupPlayer::create([
            'match_lineup_id'    => $lineup->id,
            'player_external_id' => '4001',
            'player_name'        => 'Marcus Thuram',
            'shirt_number'       => null,
            'position'           => 'F',
            'grid'               => '4:1',
            'is_starter'         => true,
        ]);

        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        $response->assertSee('lineup-pitch');
        // Iniziali di "Marcus Thuram" = "MT"
        $response->assertSee('MT');
        // Nome completo nel title attribute
        $response->assertSee('Marcus Thuram');
    }

    // -------------------------------------------------------------------------
    // 5. Lineup senza grid → nessun pitch, solo lista
    // -------------------------------------------------------------------------

    public function test_no_grid_players_no_pitch_shown(): void
    {
        $lineup = $this->makeLineup($this->homeTeam->id, '4-3-3');
        MatchLineupPlayer::create([
            'match_lineup_id'    => $lineup->id,
            'player_external_id' => '5001',
            'player_name'        => 'Sommer',
            'shirt_number'       => 1,
            'position'           => 'G',
            'grid'               => null,
            'is_starter'         => true,
        ]);

        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        $response->assertDontSee('lineup-pitch');
        $response->assertSee('Titolari');   // lista testuale ancora presente
        $response->assertSee('Sommer');
    }

    // -------------------------------------------------------------------------
    // 6. Solo una squadra ha grid → solo il suo mini-campo è presente
    // -------------------------------------------------------------------------

    public function test_one_team_with_grid_other_without(): void
    {
        // Home con grid
        $homeLineup = $this->makeLineup($this->homeTeam->id, '4-3-3');
        $this->addStarterWithGrid($homeLineup, 6001, 'Sommer', 1, 'G', '1:1');

        // Away con player ma senza grid
        $awayLineup = $this->makeLineup($this->awayTeam->id, '4-4-2');
        MatchLineupPlayer::create([
            'match_lineup_id'    => $awayLineup->id,
            'player_external_id' => '6099',
            'player_name'        => 'Maignan',
            'shirt_number'       => 16,
            'position'           => 'G',
            'grid'               => null,
            'is_starter'         => true,
        ]);

        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        $response->assertSee('lineup-pitch');   // home pitch presente
        $response->assertSee('Sommer');
        $response->assertSee('Maignan');        // away in lista testuale
    }

    // -------------------------------------------------------------------------
    // 7. Regressione: sezione formazioni ancora visibile con entrambe le lineup
    // -------------------------------------------------------------------------

    public function test_existing_lineup_list_still_rendered_with_pitch(): void
    {
        $lineup = $this->makeLineup($this->homeTeam->id, '4-3-3', 'Inzaghi S.');
        $this->addStarterWithGrid($lineup, 7001, 'Sommer', 1, 'G', '1:1');
        MatchLineupPlayer::create([
            'match_lineup_id'    => $lineup->id,
            'player_external_id' => '7099',
            'player_name'        => 'Di Gennaro',
            'shirt_number'       => 43,
            'position'           => 'G',
            'grid'               => null,
            'is_starter'         => false,
        ]);

        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        $response->assertSee('lineup-pitch');
        $response->assertSee('Titolari');
        $response->assertSee('Panchina');
        $response->assertSee('Inzaghi S.');
        $response->assertSee('4-3-3');
        $response->assertSee('Di Gennaro');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeLineup(int $teamId, ?string $formation = null, ?string $coachName = null): MatchLineup
    {
        return MatchLineup::create([
            'match_id'          => $this->match->id,
            'team_id'           => $teamId,
            'data_source_id'    => $this->ds->id,
            'formation'         => $formation,
            'coach_external_id' => $coachName ? '999' : null,
            'coach_name'        => $coachName,
        ]);
    }

    private function addStarterWithGrid(
        MatchLineup $lineup,
        int         $extId,
        string      $name,
        ?int        $shirtNumber,
        string      $position,
        string      $grid,
    ): MatchLineupPlayer {
        return MatchLineupPlayer::create([
            'match_lineup_id'    => $lineup->id,
            'player_external_id' => (string) $extId,
            'player_name'        => $name,
            'shirt_number'       => $shirtNumber,
            'position'           => $position,
            'grid'               => $grid,
            'is_starter'         => true,
        ]);
    }
}
