<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Generic Competition Page: league standings, league-phase standings for cup
 * formats (UEFA), clean omission when no standings apply, last results /
 * upcoming fixtures linking to the Match Page.
 */
class CompetitionPageTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();
        $this->country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
    }

    private function competition(string $name, string $slug, string $format): array
    {
        $competition = Competition::create([
            'country_id' => $this->country->id, 'name' => $name, 'slug' => $slug, 'format' => $format, 'is_active' => true,
        ]);
        $season = Season::create([
            'competition_id' => $competition->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true,
        ]);

        return [$competition, $season];
    }

    private function team(string $name): Team
    {
        return Team::create(['name' => $name, 'type' => 'club', 'is_active' => true]);
    }

    private function match(Competition $c, Season $s, Team $home, Team $away, string $status, string $kickoff, ?int $hg = null, ?int $ag = null, ?string $round = null, ?int $matchday = null): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $c->id, 'season_id' => $s->id,
            'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'kickoff_at' => Carbon::parse($kickoff), 'status' => $status,
            'home_score_ft' => $hg, 'away_score_ft' => $ag,
            'round' => $round, 'matchday' => $matchday,
        ]);
    }

    public function test_core_league_shows_header_standings_results_upcoming_and_match_links(): void
    {
        [$c, $s] = $this->competition('Serie A', 'serie-a', 'league');
        $napoli = $this->team('Napoli');
        $inter  = $this->team('Inter');
        $done = $this->match($c, $s, $napoli, $inter, 'finished', '2026-09-01 18:00:00', 2, 1, 'Regular Season - 1', 1);
        $next = $this->match($c, $s, $inter, $napoli, 'scheduled', Carbon::now()->addDays(5)->toDateTimeString(), null, null, 'Regular Season - 2', 2);

        $response = $this->get(route('competitions.seasons.show', ['competition' => 'serie-a', 'season' => 2026], absolute: false));

        $response->assertOk();
        $response->assertSee('Serie A');
        $response->assertSee('Stagione 2026/27');
        $this->assertSame('Classifica', $response->viewData('standingsTitle'));
        $this->assertCount(2, $response->viewData('standings'));
        $this->assertSame([$done->id], $response->viewData('recentResults')->pluck('id')->all());
        $this->assertSame([$next->id], $response->viewData('upcomingMatches')->pluck('id')->all());
        $response->assertSee(route('matches.show', $done->id, absolute: false), false);
        $response->assertSee(route('matches.show', $next->id, absolute: false), false);
    }

    public function test_champions_league_phase_standings_exclude_qualifiers_and_do_not_assume_20_teams(): void
    {
        [$c, $s] = $this->competition('UEFA Champions League', 'uefa-champions-league', 'cup');
        $a = $this->team('Napoli');
        $b = $this->team('Benfica');
        $d = $this->team('PSV');
        $q = $this->team('Qualifier FC');

        $this->match($c, $s, $q, $d, 'finished', '2026-07-10 18:00:00', 1, 0, '1st Qualifying Round');
        $this->match($c, $s, $a, $b, 'finished', '2026-09-10 19:00:00', 3, 0, 'League Stage - 1', 1);
        $upcoming = $this->match($c, $s, $b, $d, 'scheduled', Carbon::now()->addDays(10)->toDateTimeString(), null, null, 'League Stage - 2', 2);

        $response = $this->get(route('competitions.seasons.show', ['competition' => 'uefa-champions-league', 'season' => 2026], absolute: false));

        $response->assertOk();
        $this->assertSame('Classifica · League Phase', $response->viewData('standingsTitle'));
        $standings = $response->viewData('standings');
        $this->assertCount(3, $standings); // only the 3 league-phase teams, not 20, qualifier excluded
        $this->assertSame('Napoli', $standings[0]['name']);
        $this->assertSame(3, $standings[0]['points']);
        $this->assertNotContains('Qualifier FC', array_column($standings, 'name'));
        $response->assertSee('Classifica · League Phase');
        // Results span any round (qualifier included); upcoming links to the Match Page.
        $this->assertCount(2, $response->viewData('recentResults'));
        $response->assertSee(route('matches.show', $upcoming->id, absolute: false), false);
    }

    public function test_competition_without_league_phase_omits_standings_block(): void
    {
        [$c, $s] = $this->competition('Coppa Italia', 'coppa-italia', 'cup');
        $a = $this->team('Napoli');
        $b = $this->team('Verona');
        $this->match($c, $s, $a, $b, 'finished', '2026-08-14 18:00:00', 2, 0, 'Round of 64', 64);

        $response = $this->get(route('competitions.seasons.show', ['competition' => 'coppa-italia', 'season' => 2026], absolute: false));

        $response->assertOk();
        $this->assertNull($response->viewData('standings'));
        $response->assertDontSee('Classifica ·');
        $response->assertDontSee('Gestisci fasce'); // standings block (and its zones link) omitted
        $this->assertCount(1, $response->viewData('recentResults'));
    }

    public function test_competition_without_matchdays_hides_matchday_navigator(): void
    {
        [$c, $s] = $this->competition('Supercoppa', 'supercoppa', 'cup');
        $a = $this->team('Napoli');
        $b = $this->team('Inter');
        $this->match($c, $s, $a, $b, 'finished', '2026-08-01 18:00:00', 1, 1, 'Final');

        $response = $this->get(route('competitions.seasons.show', ['competition' => 'supercoppa', 'season' => 2026], absolute: false));

        $response->assertOk();
        $this->assertFalse($response->viewData('hasMatchdays'));
        $response->assertDontSee('Nessuna partita trovata per la giornata');
        $response->assertSee('Ultimi risultati');
    }
}
