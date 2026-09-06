<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchPlayerStatistic;
use App\Models\Player;
use App\Models\PlayerAbsence;
use App\Models\Season;
use App\Models\Team;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the "Impatto indisponibili" (E5 absence impact) section on /matches/{match}.
 *
 * Three rendering paths under test:
 *  Case A — absences_count = 0              → "Nessuna indisponibilità registrata"
 *  Case B — absences known, no stats        → count + "non calcolabile" + 0/N (0%)
 *  Case C — full data                       → all metrics, NULL shown as "—"
 *
 * Other rules:
 *  - homeAbsenceImpact / awayAbsenceImpact passed to view
 *  - section heading always visible
 *  - section visible on finished match
 *  - home and away kept separate
 *  - future stats excluded (anti-leakage)
 */
class MatchPageAbsenceImpactTest extends TestCase
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
    // 1. View receives both impact arrays
    // ─────────────────────────────────────────────────────────────────────────

    public function test_view_receives_home_and_away_absence_impact(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertViewHas('homeAbsenceImpact');
        $response->assertViewHas('awayAbsenceImpact');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Section heading always visible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_heading_visible(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('Impatto indisponibili');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Case A — no absences → fallback message
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_absences_shows_case_a_fallback(): void
    {
        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('Nessuna indisponibilità registrata');

        $home = $response->viewData('homeAbsenceImpact');
        $this->assertSame(0, $home['absences_count']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Case B — absences known, no player-stats history
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absences_without_stats_shows_case_b(): void
    {
        $player = $this->makePlayer();
        $this->makeAbsence($this->match, $this->homeTeam, $player);

        $response = $this->get(route('matches.show', $this->match));

        $response->assertOk();
        $response->assertSee('non calcolabile');
        $response->assertSee('0/1 (0%)');

        $home = $response->viewData('homeAbsenceImpact');
        $this->assertSame(1, $home['absences_count']);
        $this->assertSame(0, $home['absent_players_with_stats_count']);
        $this->assertSame(0.0, $home['absence_stats_coverage_percentage']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. absences_count rendered in Case C
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absences_count_rendered(): void
    {
        [$player, $prev] = $this->makeAbsenceWithHistory($this->homeTeam);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeAbsenceImpact');
        $this->assertSame(1, $home['absences_count']);
        $response->assertSee('Assenti');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Minuti persi (absent_minutes_last_30_days) rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absent_minutes_rendered(): void
    {
        $player = $this->makePlayer();
        $this->makeAbsence($this->match, $this->homeTeam, $player);

        $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStat($prev, $this->homeTeam, $player, 90);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeAbsenceImpact');
        $this->assertSame(90, $home['absent_minutes_last_30_days']);
        $response->assertSee("90'");
        $response->assertSee('Minuti persi ultimi 30 gg');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Peso minuti (absent_minutes_share_percentage) rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absent_minutes_share_percentage_rendered(): void
    {
        $absent = $this->makePlayer();
        $other  = $this->makePlayer();
        $this->makeAbsence($this->match, $this->homeTeam, $absent);

        $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStat($prev, $this->homeTeam, $absent, 90);
        $this->addStat($prev, $this->homeTeam, $other, 90);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeAbsenceImpact');
        $this->assertNotNull($home['absent_minutes_share_percentage']);
        $expected = number_format($home['absent_minutes_share_percentage'], 1) . '%';
        $response->assertSee($expected);
        $response->assertSee('Peso sui minuti squadra');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. Presenze e titolarità ultime 5 rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absent_appearances_and_starts_rendered(): void
    {
        $player = $this->makePlayer();
        $this->makeAbsence($this->match, $this->homeTeam, $player);

        $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStat($prev, $this->homeTeam, $player, 90, false); // starter

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeAbsenceImpact');
        $this->assertSame(1, $home['absent_appearances_last_5']);
        $this->assertSame(1, $home['absent_starts_last_5']);
        $response->assertSee('Presenze ultime 5 degli assenti');
        $response->assertSee('Titolarità ultime 5 degli assenti');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9. Assenti molto utilizzati rendered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_heavily_used_absences_rendered(): void
    {
        $player = $this->makePlayer();
        $this->makeAbsence($this->match, $this->homeTeam, $player);

        // 4 starts → heavily used
        for ($i = 1; $i <= 4; $i++) {
            $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays($i * 7));
            $this->addStat($prev, $this->homeTeam, $player, 90, false);
        }

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeAbsenceImpact');
        $this->assertSame(1, $home['heavily_used_absences_count']);
        $response->assertSee('Assenti molto utilizzati');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10. Copertura dati rendered (X/Y format + %)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_coverage_rendered(): void
    {
        $p1 = $this->makePlayer();
        $p2 = $this->makePlayer();
        $this->makeAbsence($this->match, $this->homeTeam, $p1);
        $this->makeAbsence($this->match, $this->homeTeam, $p2);

        $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStat($prev, $this->homeTeam, $p1, 90); // only p1 has stats

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeAbsenceImpact');
        $this->assertSame(1, $home['absent_players_with_stats_count']);
        $this->assertSame(2, $home['absences_count']);
        $this->assertSame(50.0, $home['absence_stats_coverage_percentage']);

        $response->assertSee('1/2');
        $response->assertSee('50%');
        $response->assertSee('Copertura dati');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11. NULL values rendered as "—"
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_values_rendered_as_dash(): void
    {
        // Absent player has a stat row but games_minutes = null
        // → absent_minutes_last_30_days = null, absent_minutes_share_percentage = null
        $player = $this->makePlayer();
        $this->makeAbsence($this->match, $this->homeTeam, $player);

        $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStat($prev, $this->homeTeam, $player, null); // null minutes

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeAbsenceImpact');
        $this->assertNull($home['absent_minutes_last_30_days']);
        $this->assertNull($home['absent_minutes_share_percentage']);
        $response->assertSee('—');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 12. Future stats excluded (anti-leakage)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_future_stats_excluded(): void
    {
        $player = $this->makePlayer();
        $this->makeAbsence($this->match, $this->homeTeam, $player);

        // Only a future stat row — must be ignored
        $future = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => Carbon::parse(self::TARGET)->addDays(7),
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 0,
        ]);
        $this->addStat($future, $this->homeTeam, $player, 90);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        // Calculator falls back to noStatsResult → Case B
        $home = $response->viewData('homeAbsenceImpact');
        $this->assertSame(0, $home['absent_players_with_stats_count']);
        $response->assertSee('non calcolabile');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 13. Section visible on finished match
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
        $response->assertSee('Impatto indisponibili');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 14. Home and away impacts are separate
    // ─────────────────────────────────────────────────────────────────────────

    public function test_home_and_away_impact_are_separate(): void
    {
        $homeAbsent = $this->makePlayer();
        $awayAbsent = $this->makePlayer();

        $this->makeAbsence($this->match, $this->homeTeam, $homeAbsent);
        $this->makeAbsence($this->match, $this->awayTeam, $awayAbsent);

        $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStat($prev, $this->homeTeam, $homeAbsent, 90);
        $this->addStat($prev, $this->awayTeam, $awayAbsent, 60);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();

        $home = $response->viewData('homeAbsenceImpact');
        $away = $response->viewData('awayAbsenceImpact');

        $this->assertSame(1, $home['absences_count']);
        $this->assertSame(1, $away['absences_count']);
        $this->assertSame(90, $home['absent_minutes_last_30_days']);
        $this->assertSame(60, $away['absent_minutes_last_30_days']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makePlayer(): Player
    {
        return Player::create(['name' => 'Player_' . uniqid()]);
    }

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

    private function makeAbsence(FootballMatch $match, Team $team, Player $player): PlayerAbsence
    {
        return PlayerAbsence::create([
            'match_id'       => $match->id,
            'player_id'      => $player->id,
            'team_id'        => $team->id,
            'data_source_id' => $this->ds->id,
        ]);
    }

    private function addStat(FootballMatch $match, Team $team, Player $player, ?int $minutes, bool $isSub = false): MatchPlayerStatistic
    {
        return MatchPlayerStatistic::create([
            'match_id'         => $match->id,
            'player_id'        => $player->id,
            'team_id'          => $team->id,
            'data_source_id'   => $this->ds->id,
            'games_substitute' => $isSub,
            'games_minutes'    => $minutes,
        ]);
    }

    /**
     * Shortcut: one absent player with one previous stat row (90 min, starter).
     * Returns [$player, $prevMatch] — enough to enter Case C.
     */
    private function makeAbsenceWithHistory(Team $team): array
    {
        $player = $this->makePlayer();
        $this->makeAbsence($this->match, $team, $player);
        $prev = $this->makePrev(Carbon::parse(self::TARGET)->subDays(7));
        $this->addStat($prev, $team, $player, 90, false);
        return [$player, $prev];
    }
}
