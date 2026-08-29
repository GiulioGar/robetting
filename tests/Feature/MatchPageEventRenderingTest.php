<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\Season;
use App\Models\Team;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that the Match Page renders each event_type correctly.
 *
 * Substitution semantics (API-Football):
 *   player     field → player_name          = player going OFF (out)
 *   assist     field → related_player_name  = player coming ON (in)
 * Blade renders: ↑ related_player_name (in)  ↓ player_name (out)
 */
class MatchPageEventRenderingTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;
    private DataSource $ds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);

        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);

        $competition = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
            'is_active'  => true,
        ]);

        $season = Season::create([
            'competition_id' => $competition->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);

        $homeTeam = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $awayTeam = Team::create(['name' => 'Milan',  'type' => 'club', 'is_active' => true]);

        $this->match = FootballMatch::create([
            'competition_id' => $competition->id,
            'season_id'      => $season->id,
            'home_team_id'   => $homeTeam->id,
            'away_team_id'   => $awayTeam->id,
            'kickoff_at'     => now()->subHours(2),
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 1,
        ]);

        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();
    }

    // -------------------------------------------------------------------------
    // 1. Goal + assist: badge "GOL", scorer, assist in parentesi
    // -------------------------------------------------------------------------

    public function test_goal_with_assist_renders_badge_scorer_and_assist(): void
    {
        $this->makeEvent('goal', 35, '35', 'Lautaro', 'Barella');

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('GOL');
        $response->assertSeeText('Lautaro');
        $response->assertSeeText('Barella');
    }

    // -------------------------------------------------------------------------
    // 2. Own goal: badge "AUTOGOL", player_name
    // -------------------------------------------------------------------------

    public function test_own_goal_renders_autogol_badge_and_player(): void
    {
        $this->makeEvent('own_goal', 67, '67', 'Tomori', null);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('AUTOGOL');
        $response->assertSeeText('Tomori');
        // The green "GOL" success badge must NOT appear — only the secondary AUTOGOL badge
        $response->assertDontSee('bg-success">GOL', false);
    }

    // -------------------------------------------------------------------------
    // 3. Missed penalty: badge "RIG. SBAG.", player_name
    // -------------------------------------------------------------------------

    public function test_missed_penalty_renders_badge_and_player(): void
    {
        $this->makeEvent('missed_penalty', 58, '58', 'Giroud', null);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('RIG. SBAG.');
        $response->assertSeeText('Giroud');
    }

    // -------------------------------------------------------------------------
    // 4. Substitution: ↑ related_player_name (IN) appare prima di ↓ player_name (OUT)
    //    Semantics: player_name = player going OFF, related_player_name = player coming ON
    // -------------------------------------------------------------------------

    public function test_substitution_shows_player_in_before_player_out(): void
    {
        $this->makeEvent('substitution', 61, '61',
            playerName:        'Ramsey',    // OUT
            relatedPlayerName: 'Locatelli', // IN
        );

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSeeText('Locatelli');
        $response->assertSeeText('Ramsey');
        // ↑ IN (Locatelli) must appear before ↓ OUT (Ramsey) in the rendered HTML
        $response->assertSeeInOrder(['Locatelli', 'Ramsey'], false);
    }

    // -------------------------------------------------------------------------
    // 5. VAR: badge "VAR", api_detail dal JSON — NON raw JSON
    // -------------------------------------------------------------------------

    public function test_var_renders_badge_and_detail_description(): void
    {
        $this->makeEvent('var', 38, '38', null, null, [
            'api_type'   => 'Var',
            'api_detail' => 'Goal Disallowed - offside',
        ]);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('VAR');
        $response->assertSeeText('Goal Disallowed - offside');
        // Raw JSON must not appear
        $response->assertDontSee('{&quot;api_type&quot;', false);
        $response->assertDontSee('{"api_type"', false);
    }

    // -------------------------------------------------------------------------
    // 6. minute_label 45+2: il template usa minute_label invece di minute
    // -------------------------------------------------------------------------

    public function test_extra_time_event_shows_minute_label(): void
    {
        $this->makeEvent('goal', 45, '45+2', 'Lautaro', null);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        // assertSeeText decodes HTML entities: &#039; → ' so "45+2'" matches correctly
        $response->assertSeeText("45+2'");
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function makeEvent(
        string  $eventType,
        int     $minute,
        string  $minuteLabel,
        ?string $playerName,
        ?string $relatedPlayerName,
        ?array  $detail = null,
    ): MatchEvent {
        return MatchEvent::create([
            'match_id'            => $this->match->id,
            'data_source_id'      => $this->ds->id,
            'event_type'          => $eventType,
            'minute'              => $minute,
            'minute_label'        => $minuteLabel,
            'team_id'             => null,
            'player_name'         => $playerName,
            'related_player_name' => $relatedPlayerName,
            'detail'              => $detail,
            'source_event_key'    => "{$minute}_0_505_{$eventType}_{$playerName}",
        ]);
    }
}
