<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\PreMatchFeatureAggregator;
use App\Services\Analytics\TeamEloCalculator;
use App\Services\DataSources\ApiFootball\MatchDataScope;
use App\Services\Prediction\TeamHistoryMatchEligibility;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Results-only competitions (config api-football.results_only_competitions): imported for descriptive statistics only.
 * They must NOT change legacy Elo, the Candidate47 schedule history, Team History or MatchDataScope.
 */
class ResultsOnlyCompetitionTest extends TestCase
{
    use RefreshDatabase;

    private Competition $core;
    private Competition $hun;
    private Season $coreSeason;
    private Season $hunSeason;
    private Team $a;
    private Team $b;
    private Team $c;

    protected function setUp(): void
    {
        parent::setUp();
        $country = Country::create(['name' => 'Europe', 'code' => 'EU']);
        $this->core = Competition::create(['name' => 'Serie A', 'slug' => 'serie-a', 'country_id' => $country->id]);
        $this->hun  = Competition::create(['name' => 'NB I', 'slug' => 'hungary-nb-i', 'country_id' => $country->id]);
        $this->coreSeason = Season::create(['competition_id' => $this->core->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $this->hunSeason  = Season::create(['competition_id' => $this->hun->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $this->a = Team::create(['name' => 'Team A', 'country_id' => $country->id]);
        $this->b = Team::create(['name' => 'Team B', 'country_id' => $country->id]);
        $this->c = Team::create(['name' => 'Team C', 'country_id' => $country->id]);

        config([
            'api-football.core_leagues' => [135 => 'serie-a'],
            'api-football.additional_competitions' => [],
            'api-football.results_only_competitions' => [271 => 'hungary-nb-i'],
        ]);
    }

    private function played(Competition $c, Season $s, Team $h, Team $a, string $at, int $hs, int $as): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $c->id, 'season_id' => $s->id, 'home_team_id' => $h->id, 'away_team_id' => $a->id,
            'kickoff_at' => $at, 'status' => 'finished', 'home_score_ft' => $hs, 'away_score_ft' => $as, 'round' => 'Regular Season - 1',
        ]);
    }

    public function test_results_only_ids_come_from_config_and_do_not_change_scope_classification(): void
    {
        $m = $this->played($this->hun, $this->hunSeason, $this->a, $this->c, '2026-08-01 18:00:00', 2, 0);

        $this->assertSame([$this->hun->id], MatchDataScope::resultsOnlyCompetitionIds());
        $this->assertSame(MatchDataScope::UNKNOWN, MatchDataScope::classify($m));   // not core, not additional

        config(['api-football.results_only_competitions' => []]);
        $this->assertSame([], MatchDataScope::resultsOnlyCompetitionIds());
    }

    public function test_adding_a_results_only_match_does_not_change_legacy_elo(): void
    {
        $this->played($this->core, $this->coreSeason, $this->a, $this->b, '2026-08-10 18:00:00', 1, 0);
        $cutoff = Carbon::parse('2026-12-01 00:00:00', 'UTC');
        $before = TeamEloCalculator::calculateRatingsBefore($cutoff);

        $this->played($this->hun, $this->hunSeason, $this->a, $this->c, '2026-08-20 18:00:00', 3, 0);
        $this->played($this->hun, $this->hunSeason, $this->c, $this->b, '2026-08-27 18:00:00', 0, 2);

        $this->assertSame($before, TeamEloCalculator::calculateRatingsBefore($cutoff));
        // bulk replay (OPT-1) uses the same guard
        $target = $this->played($this->core, $this->coreSeason, $this->a, $this->b, '2026-09-10 18:00:00', 0, 0);
        $bulk = TeamEloCalculator::calculateRatingsBeforeMatches(collect([$target]));
        $this->assertEqualsWithDelta($before[$this->a->id], $bulk[$target->id]['home_elo'], 1e-9);
        $this->assertArrayNotHasKey($this->c->id, TeamEloCalculator::calculateRatingsBefore($cutoff));

        // control: without the bucket the very same matches DO move Elo (the guard is what protects it)
        config(['api-football.results_only_competitions' => []]);
        $this->assertNotSame($before, TeamEloCalculator::calculateRatingsBefore($cutoff));
    }

    public function test_candidate47_schedule_history_excludes_results_only_matches(): void
    {
        $this->played($this->core, $this->coreSeason, $this->a, $this->b, '2026-08-10 18:00:00', 1, 0);
        $this->played($this->hun, $this->hunSeason, $this->a, $this->c, '2026-08-20 18:00:00', 3, 0);
        $target = FootballMatch::create([
            'competition_id' => $this->core->id, 'season_id' => $this->coreSeason->id, 'home_team_id' => $this->a->id,
            'away_team_id' => $this->b->id, 'kickoff_at' => '2026-09-01 18:00:00', 'status' => 'scheduled',
        ]);
        $m = new ReflectionMethod(PreMatchFeatureAggregator::class, 'loadScheduleHistory');
        $m->setAccessible(true);

        $this->assertCount(1, $m->invoke(null, $target, $this->a->id));      // only the Serie A match

        config(['api-football.results_only_competitions' => []]);
        $this->assertCount(2, $m->invoke(null, $target, $this->a->id));      // control: the guard is what excludes it
    }

    public function test_team_history_does_not_include_results_only_matches(): void
    {
        $this->played($this->core, $this->coreSeason, $this->a, $this->b, '2026-08-10 18:00:00', 1, 0);
        $this->played($this->hun, $this->hunSeason, $this->a, $this->c, '2026-08-20 18:00:00', 3, 0);

        $history = TeamHistoryMatchEligibility::matchesBefore($this->a->id, Carbon::parse('2026-12-01', 'UTC'));

        $this->assertSame([$this->core->id], $history->pluck('competition_id')->unique()->values()->all());
    }
}
