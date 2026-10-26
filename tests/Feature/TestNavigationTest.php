<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_shows_competition_and_quick_match_links_from_named_routes(): void
    {
        $response = $this->get(route('home', absolute: false));

        $response->assertOk();
        foreach (['serie-a', 'premier-league', 'la-liga', 'bundesliga', 'ligue-1',
                  'uefa-champions-league', 'uefa-europa-league', 'uefa-conference-league'] as $slug) {
            $response->assertSee(route('competitions.show', ['competition' => $slug]), false);
        }
        $response->assertSee(route('home'), false);
        $response->assertSee(route('matches.show', 10692), false);
        $response->assertSee(route('matches.show', 5370), false);
        $response->assertSee('Test rapidi');
    }

    public function test_competition_link_redirects_to_current_season_page(): void
    {
        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $c = Competition::create(['country_id' => $country->id, 'name' => 'Serie A', 'slug' => 'serie-a', 'format' => 'league', 'is_active' => true]);
        Season::create(['competition_id' => $c->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);

        $this->get(route('competitions.show', ['competition' => 'serie-a'], absolute: false))
            ->assertRedirect(route('competitions.seasons.show', ['competition' => 'serie-a', 'season' => 2026]));
    }
}
