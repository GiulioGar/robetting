<?php

namespace Tests\Feature\Prediction;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\Corner\CornerFeatureBuilder;
use App\Services\Prediction\Corner\CornerModelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CORNER pre-match features: same definitions as tools/scripts/corner_experiment.py (point-in-time, missing != 0),
 * and the availability rules of the inference service.
 */
class CornerFeatureBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Competition $serieA;
    private Competition $other;
    private Season $season;
    private Team $a;
    private Team $b;
    private Team $c;
    private DataSource $ds;

    protected function setUp(): void
    {
        parent::setUp();
        $country = Country::create(['name' => 'Italy', 'code' => 'IT']);
        $this->serieA = Competition::create(['name' => 'Serie A', 'slug' => 'serie-a', 'country_id' => $country->id]);
        $this->other = Competition::create(['name' => 'Coppa', 'slug' => 'coppa-italia', 'country_id' => $country->id]);
        $this->season = Season::create(['competition_id' => $this->serieA->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $this->a = Team::create(['name' => 'Team A', 'slug' => 'a', 'country_id' => $country->id]);
        $this->b = Team::create(['name' => 'Team B', 'slug' => 'b', 'country_id' => $country->id]);
        $this->c = Team::create(['name' => 'Team C', 'slug' => 'c', 'country_id' => $country->id]);
        $this->ds = DataSource::create(['name' => 'API-Football', 'slug' => 'api-football', 'source_type' => 'api']);
        config(['api-football.core_leagues' => [135 => 'serie-a']]);
    }

    private function played(string $at, Team $h, Team $a, ?int $hc, ?int $ac, ?Competition $comp = null, string $status = 'finished'): FootballMatch
    {
        $m = FootballMatch::create([
            'competition_id' => ($comp ?? $this->serieA)->id, 'season_id' => $this->season->id, 'home_team_id' => $h->id, 'away_team_id' => $a->id,
            'kickoff_at' => $at, 'status' => $status, 'home_score_ft' => $status === 'finished' ? 1 : null, 'away_score_ft' => $status === 'finished' ? 0 : null,
        ]);
        if ($hc !== null || $ac !== null) {
            MatchStatistic::create(['match_id' => $m->id, 'data_source_id' => $this->ds->id, 'home_corners' => $hc, 'away_corners' => $ac]);
        }

        return $m;
    }

    private function target(string $at = '2026-09-01 18:00:00'): FootballMatch
    {
        return $this->played($at, $this->a, $this->b, null, null, null, 'scheduled');
    }

    public function test_windows_are_strictly_before_kickoff_from_the_teams_point_of_view(): void
    {
        $this->played('2026-08-01 18:00:00', $this->a, $this->c, 6, 3);          // A at home: for 6, against 3
        $this->played('2026-08-08 18:00:00', $this->c, $this->a, 4, 7);          // A away:   for 7, against 4
        $this->played('2026-09-01 18:00:00', $this->a, $this->c, 20, 20);       // same kickoff as the target: excluded
        $this->played('2026-09-10 18:00:00', $this->a, $this->c, 20, 20);       // future: excluded
        $f = CornerFeatureBuilder::forMatch($this->target());

        $this->assertSame(2, $f['h_n10']);
        $this->assertEqualsWithDelta(6.5, $f['h_for10'], 1e-12);
        $this->assertEqualsWithDelta(3.5, $f['h_ag10'], 1e-12);
        $this->assertSame(0, $f['a_n10']);                                      // team B has no history
        $this->assertNull($f['a_for10']);                                       // missing, never 0
    }

    public function test_match_without_statistics_counts_in_the_window_but_not_in_the_average(): void
    {
        $this->played('2026-07-20 18:00:00', $this->a, $this->c, 10, 2);        // 6th most recent: only inside the 10-match window
        $this->played('2026-08-01 18:00:00', $this->a, $this->c, 6, 3);
        $this->played('2026-08-08 18:00:00', $this->a, $this->c, null, null);   // no statistics row
        $this->played('2026-08-15 18:00:00', $this->a, $this->c, null, null);
        $this->played('2026-08-16 18:00:00', $this->a, $this->c, null, null);
        $this->played('2026-08-17 18:00:00', $this->a, $this->c, 5, 5);
        $f = CornerFeatureBuilder::forMatch($this->target());

        // 5-match window = 01/08 .. 17/08 (three without statistics): 2 matches with data, average over them only
        $this->assertSame(2, $f['h_n5']);
        $this->assertEqualsWithDelta(5.5, $f['h_for5'], 1e-12);
        // 10-match window adds the 20/07 match
        $this->assertSame(3, $f['h_n10']);
        $this->assertEqualsWithDelta((10 + 6 + 5) / 3, $f['h_for10'], 1e-12);
    }

    public function test_competitions_are_mixed_and_venue_form_uses_only_the_requested_venue(): void
    {
        $this->played('2026-08-01 18:00:00', $this->a, $this->c, 8, 2, $this->other);     // A home, cup
        $this->played('2026-08-08 18:00:00', $this->c, $this->a, 4, 7);                    // A away
        $f = CornerFeatureBuilder::forMatch($this->target());

        $this->assertSame(2, $f['h_n10']);                                                 // any competition
        $this->assertSame(1, $f['h_vn']);                                                  // only the home match
        $this->assertEqualsWithDelta(8.0, $f['h_vfor'], 1e-12);
    }

    public function test_league_mean_uses_earlier_matches_of_the_same_competition_season_with_both_counts(): void
    {
        $this->played('2026-08-01 18:00:00', $this->a, $this->c, 6, 4);
        $this->played('2026-08-08 18:00:00', $this->c, $this->b, 4, 2);
        $this->played('2026-08-15 18:00:00', $this->a, $this->b, 9, null);                // incomplete counts: ignored
        $this->played('2026-08-16 18:00:00', $this->a, $this->b, 99, 99, $this->other);   // another competition: ignored
        $f = CornerFeatureBuilder::forMatch($this->target());

        $this->assertSame(2, $f['lg_n']);
        $this->assertEqualsWithDelta(5.0, $f['lg_home'], 1e-12);
        $this->assertEqualsWithDelta(3.0, $f['lg_away'], 1e-12);
        $first = CornerFeatureBuilder::forMatch($this->target('2026-07-01 18:00:00'));
        $this->assertSame(0, $first['lg_n']);
        $this->assertNull($first['lg_home']);
    }

    public function test_service_refuses_unsupported_competitions_and_matches_without_corner_history(): void
    {
        $svc = new CornerModelService();
        $cup = FootballMatch::create(['competition_id' => $this->other->id, 'season_id' => $this->season->id, 'home_team_id' => $this->a->id,
            'away_team_id' => $this->b->id, 'kickoff_at' => '2026-09-01 18:00:00', 'status' => 'scheduled']);
        $this->assertSame(['available' => false, 'reason' => 'competition_not_supported'], $svc->predictForMatch($cup->load('competition')));

        $r = $svc->predictForMatch($this->target()->load('competition'));
        $this->assertFalse($r['available']);
        $this->assertSame('insufficient_corner_history', $r['reason']);
        $this->assertArrayNotHasKey('over', $r);                                           // no probabilities invented
    }

    public function test_service_predicts_when_both_teams_have_corner_history(): void
    {
        foreach (range(1, 4) as $i) {
            $this->played("2026-08-0{$i} 18:00:00", $this->a, $this->c, 6, 4);
            $this->played("2026-08-1{$i} 18:00:00", $this->b, $this->c, 5, 4);
        }
        $r = (new CornerModelService())->predictForMatch($this->target()->load('competition'));

        $this->assertTrue($r['available']);
        $this->assertEqualsWithDelta(1.0, array_sum($r['total_pmf']), 1e-9);
        $this->assertEqualsWithDelta($r['mu_home'] + $r['mu_away'], $r['expected_total'], 1e-12);
        $this->assertCount(3, $r['over']);
    }
}
