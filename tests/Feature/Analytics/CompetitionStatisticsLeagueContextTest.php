<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\CompetitionStatisticsCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for CompetitionStatisticsCalculator::calculateLeagueContext().
 *
 * Tests:
 *  [A]  Empty collection → matches_considered=0, all metrics null
 *  [B]  Non-finished matches are excluded (defensive internal filter)
 *  [C]  Matches with null FT scores are excluded
 *  [D]  avg_goals_per_match correct
 *  [E]  avg_home_goals and avg_away_goals correct
 *  [F]  home_win_rate / draw_rate / away_win_rate correct
 *  [G]  home_vs_away_goal_diff = avg_home_goals − avg_away_goals
 *  [H]  matches_considered equals count of valid finished matches
 *  [I]  shots averages correct when all matches have stats
 *  [J]  shots_coverage reflects only matches with non-null shot data
 *  [K]  null stats not promoted to zero (avg remains null when coverage = 0)
 *  [L]  SoT tracked independently from shots coverage
 *  [M]  shots_coverage = 0 → avg_home/away_shots null
 */
class CompetitionStatisticsLeagueContextTest extends TestCase
{
    use RefreshDatabase;

    private Competition $comp;
    private Season $season;
    private Team $teamA;
    private Team $teamB;
    private DataSource $ds;

    private const DELTA = 0.0001;

    protected function setUp(): void
    {
        parent::setUp();

        $country     = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->comp  = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
        ]);
        $this->season = Season::create([
            'competition_id' => $this->comp->id,
            'name'           => '2025/26',
            'year_start'     => 2025,
            'year_end'       => 2026,
        ]);
        $this->teamA = Team::create(['name' => 'Team A', 'slug' => 'team-a']);
        $this->teamB = Team::create(['name' => 'Team B', 'slug' => 'team-b']);
        $this->ds    = DataSource::create([
            'name'        => 'FDO',
            'slug'        => 'football-data-co-uk',
            'source_type' => 'csv',
        ]);
    }

    private function makeMatch(array $overrides = []): FootballMatch
    {
        return FootballMatch::create(array_merge([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamB->id,
            'kickoff_at'     => Carbon::parse('2025-10-01 15:00:00'),
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ], $overrides));
    }

    private function makeStat(FootballMatch $match, array $overrides = []): MatchStatistic
    {
        return MatchStatistic::create(array_merge([
            'match_id'                => $match->id,
            'data_source_id'          => $this->ds->id,
            'home_shots'              => null,
            'away_shots'              => null,
            'home_shots_on_target'    => null,
            'away_shots_on_target'    => null,
        ], $overrides));
    }

    // [A] Empty collection → all null output, matches_considered = 0
    public function test_empty_collection_returns_zero_matches_and_all_nulls(): void
    {
        $result = CompetitionStatisticsCalculator::calculateLeagueContext(collect());

        $this->assertSame(0, $result['matches_considered']);
        $this->assertNull($result['avg_goals_per_match']);
        $this->assertNull($result['avg_home_goals']);
        $this->assertNull($result['avg_away_goals']);
        $this->assertNull($result['home_win_rate']);
        $this->assertNull($result['draw_rate']);
        $this->assertNull($result['away_win_rate']);
        $this->assertNull($result['home_vs_away_goal_diff']);
        $this->assertNull($result['avg_home_shots']);
        $this->assertNull($result['avg_away_shots']);
        $this->assertNull($result['avg_home_shots_on_target']);
        $this->assertNull($result['avg_away_shots_on_target']);
        $this->assertSame(0, $result['shots_coverage']);
    }

    // [B] Non-finished matches are excluded by the internal defensive filter
    public function test_non_finished_matches_are_excluded(): void
    {
        $scheduled = $this->makeMatch(['status' => 'scheduled', 'home_score_ft' => null, 'away_score_ft' => null]);
        $finished  = $this->makeMatch(['status' => 'finished', 'home_score_ft' => 2, 'away_score_ft' => 1]);

        $result = CompetitionStatisticsCalculator::calculateLeagueContext(
            collect([$scheduled, $finished])
        );

        $this->assertSame(1, $result['matches_considered']);
    }

    // [C] Matches with null FT scores are excluded even if status = finished
    public function test_null_ft_scores_are_excluded(): void
    {
        $noScore  = $this->makeMatch(['home_score_ft' => null, 'away_score_ft' => null]);
        $withScore = $this->makeMatch(['home_score_ft' => 1, 'away_score_ft' => 1]);

        $result = CompetitionStatisticsCalculator::calculateLeagueContext(
            collect([$noScore, $withScore])
        );

        $this->assertSame(1, $result['matches_considered']);
    }

    // [D] avg_goals_per_match computed correctly
    public function test_avg_goals_per_match_correct(): void
    {
        // 2-1 → 3 goals, 1-0 → 1 goal, 3-2 → 5 goals = 9 / 3 = 3.0
        $matches = collect([
            $this->makeMatch(['home_score_ft' => 2, 'away_score_ft' => 1]),
            $this->makeMatch(['home_score_ft' => 1, 'away_score_ft' => 0]),
            $this->makeMatch(['home_score_ft' => 3, 'away_score_ft' => 2]),
        ]);

        $result = CompetitionStatisticsCalculator::calculateLeagueContext($matches);

        $this->assertEqualsWithDelta(3.0, $result['avg_goals_per_match'], self::DELTA);
    }

    // [E] avg_home_goals and avg_away_goals are split correctly
    public function test_home_away_goals_split_correctly(): void
    {
        // home: 2+1+3 = 6 / 3 = 2.0; away: 1+0+2 = 3 / 3 = 1.0
        $matches = collect([
            $this->makeMatch(['home_score_ft' => 2, 'away_score_ft' => 1]),
            $this->makeMatch(['home_score_ft' => 1, 'away_score_ft' => 0]),
            $this->makeMatch(['home_score_ft' => 3, 'away_score_ft' => 2]),
        ]);

        $result = CompetitionStatisticsCalculator::calculateLeagueContext($matches);

        $this->assertEqualsWithDelta(2.0, $result['avg_home_goals'], self::DELTA);
        $this->assertEqualsWithDelta(1.0, $result['avg_away_goals'], self::DELTA);
    }

    // [F] Outcome rates (H/D/A) are correct
    public function test_outcome_rates_correct(): void
    {
        // 2 home wins, 1 draw, 1 away win → 4 matches
        $matches = collect([
            $this->makeMatch(['home_score_ft' => 2, 'away_score_ft' => 0]),
            $this->makeMatch(['home_score_ft' => 1, 'away_score_ft' => 0]),
            $this->makeMatch(['home_score_ft' => 1, 'away_score_ft' => 1]),
            $this->makeMatch(['home_score_ft' => 0, 'away_score_ft' => 2]),
        ]);

        $result = CompetitionStatisticsCalculator::calculateLeagueContext($matches);

        $this->assertEqualsWithDelta(0.5,  $result['home_win_rate'], self::DELTA); // 2/4
        $this->assertEqualsWithDelta(0.25, $result['draw_rate'],     self::DELTA); // 1/4
        $this->assertEqualsWithDelta(0.25, $result['away_win_rate'], self::DELTA); // 1/4
    }

    // [G] home_vs_away_goal_diff = avg_home_goals − avg_away_goals
    public function test_home_vs_away_goal_diff_equals_difference(): void
    {
        $matches = collect([
            $this->makeMatch(['home_score_ft' => 2, 'away_score_ft' => 1]),
            $this->makeMatch(['home_score_ft' => 1, 'away_score_ft' => 0]),
            $this->makeMatch(['home_score_ft' => 3, 'away_score_ft' => 2]),
        ]);

        $result = CompetitionStatisticsCalculator::calculateLeagueContext($matches);

        $expected = $result['avg_home_goals'] - $result['avg_away_goals'];
        $this->assertEqualsWithDelta($expected, $result['home_vs_away_goal_diff'], self::DELTA);
        $this->assertEqualsWithDelta(1.0, $result['home_vs_away_goal_diff'], self::DELTA);
    }

    // [H] matches_considered equals count of valid finished matches only
    public function test_matches_considered_counts_only_valid_finished(): void
    {
        $valid1    = $this->makeMatch(['home_score_ft' => 1, 'away_score_ft' => 0]);
        $valid2    = $this->makeMatch(['home_score_ft' => 2, 'away_score_ft' => 1]);
        $noScore   = $this->makeMatch(['home_score_ft' => null, 'away_score_ft' => null]);
        $scheduled = $this->makeMatch(['status' => 'scheduled', 'home_score_ft' => null, 'away_score_ft' => null]);

        $result = CompetitionStatisticsCalculator::calculateLeagueContext(
            collect([$valid1, $valid2, $noScore, $scheduled])
        );

        $this->assertSame(2, $result['matches_considered']);
    }

    // [I] shots averages correct when all matches have stats
    public function test_shot_averages_correct_with_full_coverage(): void
    {
        $m1 = $this->makeMatch(['home_score_ft' => 1, 'away_score_ft' => 0]);
        $m2 = $this->makeMatch(['home_score_ft' => 2, 'away_score_ft' => 1]);

        $stats = collect([
            $m1->id => $this->makeStat($m1, ['home_shots' => 10, 'away_shots' => 5,
                                              'home_shots_on_target' => 4, 'away_shots_on_target' => 2]),
            $m2->id => $this->makeStat($m2, ['home_shots' => 8,  'away_shots' => 6,
                                              'home_shots_on_target' => 3, 'away_shots_on_target' => 3]),
        ]);

        $result = CompetitionStatisticsCalculator::calculateLeagueContext(
            collect([$m1, $m2]),
            $stats
        );

        $this->assertEqualsWithDelta(9.0, $result['avg_home_shots'], self::DELTA); // (10+8)/2
        $this->assertEqualsWithDelta(5.5, $result['avg_away_shots'], self::DELTA); // (5+6)/2
        $this->assertEqualsWithDelta(3.5, $result['avg_home_shots_on_target'], self::DELTA); // (4+3)/2
        $this->assertEqualsWithDelta(2.5, $result['avg_away_shots_on_target'], self::DELTA); // (2+3)/2
        $this->assertSame(2, $result['shots_coverage']);
    }

    // [J] shots_coverage reflects only matches with non-null shot data
    public function test_shots_coverage_reflects_partial_stat_availability(): void
    {
        $m1 = $this->makeMatch(['home_score_ft' => 1, 'away_score_ft' => 0]);
        $m2 = $this->makeMatch(['home_score_ft' => 2, 'away_score_ft' => 0]);
        $m3 = $this->makeMatch(['home_score_ft' => 0, 'away_score_ft' => 1]);

        $stats = collect([
            $m1->id => $this->makeStat($m1, ['home_shots' => 10, 'away_shots' => 4]),
            // m2 has no stat row
            $m3->id => $this->makeStat($m3, ['home_shots' => null, 'away_shots' => null]),
        ]);

        $result = CompetitionStatisticsCalculator::calculateLeagueContext(
            collect([$m1, $m2, $m3]),
            $stats
        );

        $this->assertSame(1, $result['shots_coverage']);
        $this->assertSame(3, $result['matches_considered']);
        $this->assertEqualsWithDelta(10.0, $result['avg_home_shots'], self::DELTA);
        $this->assertEqualsWithDelta(4.0,  $result['avg_away_shots'], self::DELTA);
    }

    // [K] When shots_coverage = 0 → avg_home/away_shots are null, not zero
    public function test_null_shots_not_promoted_to_zero(): void
    {
        $m1 = $this->makeMatch(['home_score_ft' => 1, 'away_score_ft' => 0]);
        // No stat row for m1

        $result = CompetitionStatisticsCalculator::calculateLeagueContext(
            collect([$m1]),
            collect()
        );

        $this->assertSame(1, $result['matches_considered']);
        $this->assertNull($result['avg_home_shots']);
        $this->assertNull($result['avg_away_shots']);
        $this->assertNull($result['avg_home_shots_on_target']);
        $this->assertNull($result['avg_away_shots_on_target']);
        $this->assertSame(0, $result['shots_coverage']);
        $this->assertSame(0, $result['shots_on_target_coverage']);
    }

    // [L] SoT tracked independently from shots: partial SoT does not affect shots coverage
    public function test_sot_coverage_independent_from_shots_coverage(): void
    {
        $m1 = $this->makeMatch(['home_score_ft' => 1, 'away_score_ft' => 0]);
        $m2 = $this->makeMatch(['home_score_ft' => 2, 'away_score_ft' => 1]);

        // m1 has shots but no SoT; m2 has both
        $stats = collect([
            $m1->id => $this->makeStat($m1, [
                'home_shots' => 8, 'away_shots' => 5,
                'home_shots_on_target' => null, 'away_shots_on_target' => null,
            ]),
            $m2->id => $this->makeStat($m2, [
                'home_shots' => 10, 'away_shots' => 6,
                'home_shots_on_target' => 4, 'away_shots_on_target' => 2,
            ]),
        ]);

        $result = CompetitionStatisticsCalculator::calculateLeagueContext(
            collect([$m1, $m2]),
            $stats
        );

        // shots_coverage = 2 (both have shots); shots_on_target_coverage = 1 (only m2)
        $this->assertSame(2, $result['shots_coverage']);
        $this->assertSame(1, $result['shots_on_target_coverage']);
        $this->assertEqualsWithDelta(9.0, $result['avg_home_shots'], self::DELTA); // (8+10)/2
        // SoT only from m2
        $this->assertEqualsWithDelta(4.0, $result['avg_home_shots_on_target'], self::DELTA);
        $this->assertEqualsWithDelta(2.0, $result['avg_away_shots_on_target'], self::DELTA);
    }

    // [M] rates and averages are null when collection is empty
    public function test_empty_collection_has_null_rates(): void
    {
        $result = CompetitionStatisticsCalculator::calculateLeagueContext(collect());

        $this->assertNull($result['home_win_rate']);
        $this->assertNull($result['draw_rate']);
        $this->assertNull($result['away_win_rate']);
    }
}
