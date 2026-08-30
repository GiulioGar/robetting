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
 * Verifies the Formazioni section on the match page (/matches/{match}).
 *
 * Rules under test:
 *  - Section visible only when at least one lineup exists for the configured source.
 *  - Both teams shown side by side; missing team → "Formazione non ancora disponibile".
 *  - Starters (is_starter=true) appear under "Titolari"; bench under "Panchina".
 *  - Formation badge and coach name rendered when present.
 *  - shirt_number and position show "–" when null.
 *  - Lineups from a different data source are not shown.
 */
class MatchPageLineupsTest extends TestCase
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
            'home_score_ft'  => 2,
            'away_score_ft'  => 1,
            'definitive_at'  => now()->subMinutes(30),
        ]);
    }

    // -------------------------------------------------------------------------
    // 1. Entrambe le lineup → sezione visibile con nome di entrambe le squadre
    // -------------------------------------------------------------------------

    public function test_both_lineups_shown(): void
    {
        $this->makeLineup($this->homeTeam->id, '3-5-2', 'Inzaghi S.');
        $this->makeLineup($this->awayTeam->id, '4-2-3-1', 'Fonseca');

        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        $response->assertSee('Formazioni');
        $response->assertSee('Inter');
        $response->assertSee('Milan');
    }

    // -------------------------------------------------------------------------
    // 2. Solo una lineup → l'altra squadra mostra fallback
    // -------------------------------------------------------------------------

    public function test_one_lineup_shows_fallback_for_missing_team(): void
    {
        $this->makeLineup($this->homeTeam->id, '3-5-2');

        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        $response->assertSee('Formazioni');
        $response->assertSee('Inter');
        $response->assertSee('Formazione non ancora disponibile');
    }

    // -------------------------------------------------------------------------
    // 3. Titolari e panchina separati
    // -------------------------------------------------------------------------

    public function test_starters_and_bench_in_separate_sections(): void
    {
        $lineup = $this->makeLineup($this->homeTeam->id, '4-3-3');

        MatchLineupPlayer::create([
            'match_lineup_id'    => $lineup->id,
            'player_external_id' => '1001',
            'player_name'        => 'Sommer',
            'shirt_number'       => 1,
            'position'           => 'G',
            'grid'               => '1:1',
            'is_starter'         => true,
        ]);

        MatchLineupPlayer::create([
            'match_lineup_id'    => $lineup->id,
            'player_external_id' => '1099',
            'player_name'        => 'Di Gennaro',
            'shirt_number'       => 43,
            'position'           => 'G',
            'grid'               => null,
            'is_starter'         => false,
        ]);

        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        $response->assertSee('Titolari');
        $response->assertSee('Panchina');
        $response->assertSee('Sommer');
        $response->assertSee('Di Gennaro');
    }

    // -------------------------------------------------------------------------
    // 4. Formation badge e allenatore visualizzati
    // -------------------------------------------------------------------------

    public function test_formation_and_coach_displayed(): void
    {
        $this->makeLineup($this->homeTeam->id, '3-5-2', 'Inzaghi S.');

        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        $response->assertSee('3-5-2');
        $response->assertSee('Inzaghi S.');
        $response->assertSee('Allenatore:');
    }

    // -------------------------------------------------------------------------
    // 5. shirt_number e position null → visualizza "–"
    // -------------------------------------------------------------------------

    public function test_null_shirt_number_and_position_shows_dash(): void
    {
        $lineup = $this->makeLineup($this->homeTeam->id);

        MatchLineupPlayer::create([
            'match_lineup_id'    => $lineup->id,
            'player_external_id' => '1002',
            'player_name'        => 'De Vrij',
            'shirt_number'       => null,
            'position'           => null,
            'grid'               => null,
            'is_starter'         => true,
        ]);

        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        $response->assertSee('De Vrij');
        $response->assertSee('–');
    }

    // -------------------------------------------------------------------------
    // 6. Nessuna lineup → sezione "Formazioni" assente
    // -------------------------------------------------------------------------

    public function test_no_lineup_section_absent(): void
    {
        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        $response->assertDontSee('Formazioni');
    }

    // -------------------------------------------------------------------------
    // 7. Lineup da fonte diversa da API-Football → non mostrata
    // -------------------------------------------------------------------------

    public function test_lineup_from_different_source_not_shown(): void
    {
        $otherDs = DataSource::create([
            'name'        => 'Other Source',
            'slug'        => 'other-source',
            'source_type' => 'api',
            'description' => null,
            'url'         => null,
        ]);

        MatchLineup::create([
            'match_id'          => $this->match->id,
            'team_id'           => $this->homeTeam->id,
            'data_source_id'    => $otherDs->id,
            'formation'         => '4-4-2',
            'coach_external_id' => null,
            'coach_name'        => null,
        ]);

        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        $response->assertDontSee('Formazioni');
    }

    // -------------------------------------------------------------------------
    // 7. Match Page non contiene il bottone di aggiornamento manuale
    // -------------------------------------------------------------------------

    public function test_match_page_does_not_contain_manual_update_button(): void
    {
        $response = $this->get(route('matches.show', $this->match->id));

        $response->assertOk();
        // Il trigger manuale è stato spostato nel pannello admin — non deve più apparire qui.
        $response->assertDontSee('Aggiorna tutti i dati');
        $response->assertDontSee('matches.update-all');
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
}
