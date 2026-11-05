<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\PreMatchFeatureAggregator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * EXPERIMENTAL opt-in RECENT_WINDOW_ALL: last 10 definitive matches in ANY competition (ELO_ONLY / results-only included).
 * The default and the team_history mode must stay exactly as they were; only the core_recent_* block may differ.
 */
class PreMatchFeatureAggregatorRecentAllModeTest extends TestCase
{
    use RefreshDatabase;

    private Competition $uefa;
    private Competition $hun;
    private Competition $serieA;
    private Season $uefaSeason;
    private Season $hunSeason;
    private Season $serieASeason;
    private Team $x;      // outside the 5 leagues (like Ferencvarosi)
    private Team $y;
    private Team $z;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'api-football.core_leagues'              => [135 => 'serie-a'],
            'api-football.additional_competitions'   => [2 => 'uefa-champions-league'],
            'api-football.results_only_competitions' => [271 => 'hungary-nb-i'],
        ]);
        $country = Country::create(['name' => 'Europe', 'football_code' => 'EU']);
        $this->uefa   = Competition::create(['country_id' => $country->id, 'name' => 'UEFA Champions League', 'slug' => 'uefa-champions-league', 'format' => 'cup', 'is_active' => true]);
        $this->hun    = Competition::create(['country_id' => $country->id, 'name' => 'NB I', 'slug' => 'hungary-nb-i', 'format' => 'league', 'is_active' => true]);
        $this->serieA = Competition::create(['country_id' => $country->id, 'name' => 'Serie A', 'slug' => 'serie-a', 'format' => 'league', 'is_active' => true]);
        $this->uefaSeason   = Season::create(['competition_id' => $this->uefa->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $this->hunSeason    = Season::create(['competition_id' => $this->hun->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $this->serieASeason = Season::create(['competition_id' => $this->serieA->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $this->x = Team::create(['name' => 'Team X', 'type' => 'club', 'is_active' => true]);
        $this->y = Team::create(['name' => 'Team Y', 'type' => 'club', 'is_active' => true]);
        $this->z = Team::create(['name' => 'Team Z', 'type' => 'club', 'is_active' => true]);
    }

    private function played(Competition $c, Season $s, Team $h, Team $a, string $at, int $hg, int $ag, string $status = 'finished'): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $c->id, 'season_id' => $s->id, 'home_team_id' => $h->id, 'away_team_id' => $a->id,
            'kickoff_at' => Carbon::parse($at, 'UTC'), 'status' => $status,
            'home_score_ft' => $status === 'finished' ? $hg : null, 'away_score_ft' => $status === 'finished' ? $ag : null,
        ]);
    }

    private function target(): FootballMatch
    {
        return $this->played($this->uefa, $this->uefaSeason, $this->x, $this->y, '2026-11-05 17:45:00', 0, 0, 'scheduled');
    }

    private function flat(FootballMatch $m, string $mode): array
    {
        return PreMatchFeatureAggregator::flatten(PreMatchFeatureAggregator::aggregate($m, null, $mode, PreMatchFeatureAggregator::ELO_MODE_EXTENDED));
    }

    public function test_recent_all_includes_elo_only_and_results_only_matches_but_team_history_does_not(): void
    {
        $this->played($this->uefa, $this->uefaSeason, $this->x, $this->z, '2026-09-17 19:00:00', 3, 0);    // ELO_ONLY (no core team)
        $this->played($this->hun, $this->hunSeason, $this->x, $this->z, '2026-10-18 17:00:00', 1, 1);       // results-only league
        $target = $this->target();

        $th  = $this->flat($target, PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY);
        $all = $this->flat($target, PreMatchFeatureAggregator::RECENT_WINDOW_ALL);

        $this->assertEquals(0, $th['core_recent_home_matches_considered']);       // Team History excludes both
        $this->assertEquals(2, $all['core_recent_home_matches_considered']);
        $this->assertEqualsWithDelta(2.0, $all['core_recent_home_avg_goals_for'], 1e-9);      // (3 + 1) / 2
        $this->assertEqualsWithDelta(0.5, $all['core_recent_home_avg_goals_against'], 1e-9);  // (0 + 1) / 2
    }

    public function test_only_the_recent_block_differs_and_default_equals_team_history(): void
    {
        $this->played($this->uefa, $this->uefaSeason, $this->x, $this->z, '2026-09-17 19:00:00', 3, 0);
        $this->played($this->hun, $this->hunSeason, $this->x, $this->z, '2026-10-18 17:00:00', 1, 1);
        $target = $this->target();

        $default = PreMatchFeatureAggregator::flatten(PreMatchFeatureAggregator::aggregate($target));
        $thLegacyElo = PreMatchFeatureAggregator::flatten(PreMatchFeatureAggregator::aggregate(
            $target, null, PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY, PreMatchFeatureAggregator::ELO_MODE_LEGACY
        ));
        $this->assertEquals($thLegacyElo, $default);                                    // the default call is unchanged

        $th  = $this->flat($target, PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY);
        $all = $this->flat($target, PreMatchFeatureAggregator::RECENT_WINDOW_ALL);
        $strip = fn (array $f) => array_filter($f, fn ($k) => ! str_starts_with($k, 'core_recent_') && ! str_contains($k, 'timing'), ARRAY_FILTER_USE_KEY);
        $this->assertEquals($strip($th), $strip($all));                                 // every other feature identical
        $this->assertNotEquals($th['core_recent_home_matches_considered'], $all['core_recent_home_matches_considered']);
    }

    public function test_recent_all_is_strictly_pre_kickoff_finished_only_and_capped_at_ten(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->played($this->hun, $this->hunSeason, $this->x, $this->z, sprintf('2026-09-%02d 17:00:00', $i), 1, 0);
        }
        $this->played($this->hun, $this->hunSeason, $this->x, $this->z, '2026-10-01 17:00:00', 9, 9, 'scheduled');   // not final
        $this->played($this->uefa, $this->uefaSeason, $this->x, $this->z, '2026-11-05 17:45:00', 9, 9);                // at kickoff (not before)
        $this->played($this->uefa, $this->uefaSeason, $this->x, $this->z, '2026-11-20 17:45:00', 9, 9);                // after kickoff
        $target = $this->target();

        $all = $this->flat($target, PreMatchFeatureAggregator::RECENT_WINDOW_ALL);

        $this->assertEquals(10, $all['core_recent_home_matches_considered']);
        $this->assertEqualsWithDelta(1.0, $all['core_recent_home_avg_goals_for'], 1e-9);   // only the 1-0 matches
    }

    public function test_unknown_recent_window_mode_is_still_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PreMatchFeatureAggregator::aggregate($this->target(), null, 'everything');
    }
}
