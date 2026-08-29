<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchPageLiveRenderingTest extends TestCase
{
    use RefreshDatabase;

    private Competition $competition;
    private Season $season;
    private Team $homeTeam;
    private Team $awayTeam;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->awayTeam = Team::create(['name' => 'Milan',  'type' => 'club', 'is_active' => true]);
    }

    // -------------------------------------------------------------------------
    // Live 1H: current score + minuto + stato leggibile; FT non mostrato
    // -------------------------------------------------------------------------

    public function test_live_1h_shows_current_score_minute_and_status(): void
    {
        $match = $this->makeMatch([
            'status'             => 'live',
            'kickoff_at'         => now()->subMinutes(23),
            'current_home_score' => 1,
            'current_away_score' => 0,
            'live_minute'        => 23,
            'live_status'        => '1H',
            'home_score_ft'      => null,
            'away_score_ft'      => null,
        ]);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $response->assertSee('1° tempo');        // live_status label
        $response->assertSee("23'", false);  // live_minute (apostrofo non escaped nel DOM)
        // Score uses current_home/away_score, not home/away_score_ft
        $response->assertSee('1');
        $response->assertSee('0');
        $response->assertDontSee('Terminata');
    }

    // -------------------------------------------------------------------------
    // Live HT: intervallo, score HT presente nella riga secondaria
    // -------------------------------------------------------------------------

    public function test_live_ht_shows_halftime_label_and_ht_score(): void
    {
        $match = $this->makeMatch([
            'status'             => 'live',
            'kickoff_at'         => now()->subMinutes(50),
            'current_home_score' => 1,
            'current_away_score' => 0,
            'live_minute'        => 45,
            'live_status'        => 'HT',
            'home_score_ht'      => 1,
            'away_score_ht'      => 0,
            'home_score_ft'      => null,
            'away_score_ft'      => null,
        ]);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $response->assertSee('Intervallo');       // live_status label per HT
        $response->assertSee("45'", false);  // live_minute
        // HT score secondary row still rendered
        $response->assertSee('HT');
        $response->assertDontSee('Terminata');
    }

    // -------------------------------------------------------------------------
    // FT: score finale da home/away_score_ft; nessun indicatore live
    // -------------------------------------------------------------------------

    public function test_finished_shows_ft_score_not_live_ui(): void
    {
        $match = $this->makeMatch([
            'status'             => 'finished',
            'kickoff_at'         => now()->subHours(2),
            'home_score_ft'      => 2,
            'away_score_ft'      => 1,
            'home_score_ht'      => 1,
            'away_score_ht'      => 0,
            'current_home_score' => 2,
            'current_away_score' => 1,
            'live_minute'        => null,
            'live_status'        => 'FT',
        ]);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $response->assertSee('Terminata');
        // FT score rendered in the main score block
        $response->assertSee('2');
        $response->assertSee('1');
        // No live labels
        $response->assertDontSee('1° tempo');
        $response->assertDontSee('Intervallo');
        $response->assertDontSee('2° tempo');
    }

    // -------------------------------------------------------------------------
    // Scheduled: mostra "vs" e data kickoff; nessun score
    // -------------------------------------------------------------------------

    public function test_scheduled_shows_vs_and_kickoff_no_score(): void
    {
        $kickoff = now()->addDays(3)->setTimezone('UTC');

        $match = $this->makeMatch([
            'status'        => 'scheduled',
            'kickoff_at'    => $kickoff,
            'home_score_ft' => null,
            'away_score_ft' => null,
        ]);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $response->assertSee('vs');
        $response->assertSee('Programmata');
        $response->assertDontSee('1° tempo');
        $response->assertDontSee('Intervallo');
        $response->assertDontSee('Terminata');
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function makeMatch(array $attributes): FootballMatch
    {
        return FootballMatch::create(array_merge([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now(),
            'status'         => 'scheduled',
        ], $attributes));
    }
}
