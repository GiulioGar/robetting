<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchPlayerStatistic;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the "Profilo età recente" (E3 age profile) section on /matches/{match}.
 *
 * Rules under test:
 *  - homeAgeProfile / awayAgeProfile passed to view
 *  - age values rendered (1 decimal)
 *  - coverage shown (X/Y players and %)
 *  - null values rendered as "—"
 *  - coverage = 0 → "Dati anagrafici non disponibili"
 *  - no future data included (anti-leakage)
 *  - section visible on finished match
 *  - section heading always visible
 *  - home and away values kept separate
 */
class MatchPageAgeProfileTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private FootballMatch $match;
    private Team $homeTeam;
    private Team $awayTeam;
    private Competition $comp;
    private Season $season;

    private const TARGET = '2026-09-10 20:45:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        $country      = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->comp   = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $this->season = Season::create([
            'competition_id' => $this->comp->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);
        $this->homeTeam = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->awayTeam = Team::create(['name' => 'Milan',  'type' => 'club', 'is_active' => true]);

        $this->match = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET),
            'status'         => 'scheduled',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. View receives both age-profile arrays
    // ─────────────────────────────────────────────────────────────────────────

    public function test_view_receives_home_and_away_age_profiles(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertViewHas('homeAgeProfile');
        $response->assertViewHas('awayAgeProfile');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Section heading always visible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_heading_visible(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('Profilo età recente');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. No stats → "Dati anagrafici non disponibili" for both teams
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_stats_shows_no_data_message(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('Dati anagrafici non disponibili');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Age values rendered with 1 decimal
    // ─────────────────────────────────────────────────────────────────────────

    public function test_age_values_rendered_with_one_decimal(): void
    {
        $player = Player::create(['name' => 'Barella', 'birth_date' => '1997-02-07']);
        $prev   = $this->makePrev(Carbon::parse(self::TARGET)->subDays(5));
        $this->addStat($prev, $player, $this->homeTeam, 90, isSub: false);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $profile = $response->viewData('homeAgeProfile');
        $this->assertNotNull($profile['average_age_used_last_5']);

        // 1-decimal format: "XX.X" must appear in output
        $expected = number_format($profile['average_age_used_last_5'], 1);
        $response->assertSee($expected);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Coverage shown as "X/Y (ZZ%)"
    // ─────────────────────────────────────────────────────────────────────────

    public function test_coverage_rendered_correctly(): void
    {
        $withBirth = Player::create(['name' => 'WithBirth', 'birth_date' => '1997-01-01']);
        $noBirth   = Player::create(['name' => 'NoBirth']);

        $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays(5));
        $this->addStat($prev, $withBirth, $this->homeTeam, 90);
        $this->addStat($prev, $noBirth,   $this->homeTeam, 80);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        // 1 out of 2 players have birth_date → "1/2" and "50%"
        $response->assertSee('1/2');
        $response->assertSee('50%');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Null weighted average → "—"
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_values_rendered_as_dash(): void
    {
        // Player with birth_date but NULL minutes → weighted avg = null
        $player = Player::create(['name' => 'NullMins', 'birth_date' => '1997-01-01']);
        $prev   = $this->makePrev(Carbon::parse(self::TARGET)->subDays(5));
        $this->addStat($prev, $player, $this->homeTeam, null);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        // weighted_average_age_last_5 is null → rendered as "—"
        $response->assertSee('—');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Coverage = 0 → fallback message, not numeric values
    // ─────────────────────────────────────────────────────────────────────────

    public function test_zero_coverage_shows_fallback_not_numbers(): void
    {
        // Player without birth_date
        $player = Player::create(['name' => 'NoBirthPlayer']);
        $prev   = $this->makePrev(Carbon::parse(self::TARGET)->subDays(5));
        $this->addStat($prev, $player, $this->homeTeam, 90);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $profile = $response->viewData('homeAgeProfile');
        $this->assertSame(0, $profile['players_with_birth_date_count']);
        $response->assertSee('Dati anagrafici non disponibili');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. Future match data excluded (anti-leakage)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_future_match_excluded(): void
    {
        $player = Player::create(['name' => 'FuturePlayer', 'birth_date' => '1997-01-01']);

        // Match AFTER target kickoff
        $future = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET)->addDays(5),
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 0,
        ]);
        $this->addStat($future, $player, $this->homeTeam, 90);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $profile = $response->viewData('homeAgeProfile');
        $this->assertSame(0, $profile['players_used_count']);
        $this->assertNull($profile['average_age_used_last_5']);
        $response->assertSee('Dati anagrafici non disponibili');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9. Section visible on finished match too
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_visible_on_finished_match(): void
    {
        $this->match->update([
            'status'        => 'finished',
            'home_score_ft' => 2,
            'away_score_ft' => 1,
        ]);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('Profilo età recente');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10. Home and away values kept separate
    // ─────────────────────────────────────────────────────────────────────────

    public function test_home_and_away_profiles_are_separate(): void
    {
        $homePlayer = Player::create(['name' => 'HomePlayer', 'birth_date' => '1994-01-01']);
        $awayPlayer = Player::create(['name' => 'AwayPlayer', 'birth_date' => '2002-01-01']);

        $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays(5));
        $this->addStat($prev, $homePlayer, $this->homeTeam, 90);
        $this->addStat($prev, $awayPlayer, $this->awayTeam, 90);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $homeProfile = $response->viewData('homeAgeProfile');
        $awayProfile = $response->viewData('awayAgeProfile');

        $this->assertSame(1, $homeProfile['players_used_count']);
        $this->assertSame(1, $awayProfile['players_used_count']);

        // Home player is older than away player → averages differ
        $this->assertGreaterThan(
            $awayProfile['average_age_used_last_5'],
            $homeProfile['average_age_used_last_5'],
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11. All three age metrics rendered when data available
    // ─────────────────────────────────────────────────────────────────────────

    public function test_all_three_age_metrics_rendered(): void
    {
        $player = Player::create(['name' => 'Mkhitaryan', 'birth_date' => '1989-01-21']);
        $prev   = $this->makePrev(Carbon::parse(self::TARGET)->subDays(5));
        $this->addStat($prev, $player, $this->homeTeam, 90, isSub: false); // starter

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $response->assertSee('Età media utilizzati');
        $response->assertSee('Età media pesata minuti');
        $response->assertSee('Età media titolari');
        $response->assertSee('Copertura dati');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makePrev(Carbon $kickoff): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => $kickoff,
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);
    }

    private function addStat(
        FootballMatch $match,
        Player $player,
        Team $team,
        ?int $minutes,
        bool $isSub = false,
    ): void {
        MatchPlayerStatistic::create([
            'match_id'         => $match->id,
            'player_id'        => $player->id,
            'team_id'          => $team->id,
            'data_source_id'   => $this->ds->id,
            'games_minutes'    => $minutes,
            'games_substitute' => $isSub,
        ]);
    }
}
