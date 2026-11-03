<?php

namespace Tests\Unit\Services\Odds;

use App\Services\Odds\GoalMarketComparator as Cmp;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * GoalMarketComparator::compare() — synthetic data, no DB. Model reference for (1.5, 1.2, 0.15):
 * P(total <= 2.5) = 0.502065112387, P(BTTS) = 0.553757293941 (independent brute-force of the full joint).
 */
class GoalMarketComparatorTest extends TestCase
{
    private const PRED = ['id' => 7, 'lambda_home' => 1.5, 'lambda_away' => 1.2, 'lambda3' => 0.15];
    private const UNDER_25 = 0.502065112387;
    private const BTTS_YES = 0.553757293941;

    private Carbon $asOf;
    private Carbon $kickoff;
    private int $nextId = 1;
    private int $nextObs = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asOf = Carbon::parse('2026-11-05 12:00:00', 'UTC');
        $this->kickoff = Carbon::parse('2026-11-06 19:30:00', 'UTC');
    }

    private function pred(): array
    {
        return self::PRED + ['generated_at' => $this->asOf->copy()->subHours(5)];
    }

    /** One complete observation (all selections share observation_id / fetched_at). */
    private function obs(int $bm, string $name, string $market, ?float $line, array $odds, ?Carbon $fetchedAt = null, ?string $obsId = null, ?Carbon $kickoff = null): array
    {
        $obsId ??= 'obs-' . $this->nextObs++;
        $fetchedAt ??= $this->asOf->copy()->subHour();
        $rows = [];
        foreach ($odds as $selection => $odd) {
            $rows[] = [
                'id' => $this->nextId++, 'observation_id' => $obsId, 'bookmaker_id' => $bm, 'bookmaker_name' => $name,
                'market' => $market, 'line' => $line, 'selection' => $selection, 'odd' => $odd,
                'provider_updated_at' => $fetchedAt->copy()->subMinutes(30), 'fetched_at' => $fetchedAt, 'kickoff_at' => $kickoff ?? $this->kickoff,
            ];
        }

        return $rows;
    }

    private function find(array $result, string $market, ?float $line, string $selection): array
    {
        foreach ($result['markets'] as $m) {
            if ($m['market'] === $market && $m['line'] === $line && $m['selection'] === $selection) {
                return $m;
            }
        }
        $this->fail("entry {$market} {$line} {$selection} not found");
    }

    private function threeBookmakers(): array
    {
        return array_merge(
            $this->obs(1, 'Alpha', 'total_goals', 2.5, ['over' => 1.90, 'under' => 1.95]),
            $this->obs(2, 'Bravo', 'total_goals', 2.5, ['over' => 2.00, 'under' => 1.80]),
            $this->obs(3, 'Charlie', 'total_goals', 2.5, ['over' => 1.85, 'under' => 2.05]),
        );
    }

    public function test_maps_every_goal_market_with_model_probability_and_fair_odd(): void
    {
        $r = Cmp::compare($this->pred(), [], $this->asOf);

        $this->assertSame('no_quotes', $r['status']);
        $this->assertCount(5 * 2 + 2 + 3 * 2 * 2, $r['markets']);   // O/U 0.5-4.5, BTTS, team goals 0.5-2.5 x 2 sides
        $this->assertSame(7, $r['prediction']['id']);

        $under = $this->find($r, 'total_goals', 2.5, 'under');
        $over  = $this->find($r, 'total_goals', 2.5, 'over');
        $this->assertEqualsWithDelta(self::UNDER_25, $under['model_probability'], 1e-9);
        $this->assertEqualsWithDelta(1 - self::UNDER_25, $over['model_probability'], 1e-9);
        $this->assertEqualsWithDelta(1 / self::UNDER_25, $under['model_fair_odd'], 1e-9);
        $this->assertEqualsWithDelta(self::BTTS_YES, $this->find($r, 'btts', null, 'yes')['model_probability'], 1e-9);
        $this->assertEqualsWithDelta(0.223130160148, $this->find($r, 'team_goals_home', 0.5, 'under')['model_probability'], 1e-9);
        $this->assertEqualsWithDelta(0.301194211912, $this->find($r, 'team_goals_away', 0.5, 'under')['model_probability'], 1e-9);

        foreach ($r['markets'] as $m) {   // no quotes => explicit status, never invented values
            $this->assertSame('no_quotes', $m['status']);
            foreach (['consensus_probability', 'probability_edge', 'best_odd', 'theoretical_ev', 'latest_fetched_at'] as $k) {
                $this->assertNull($m[$k]);
            }
            $this->assertSame(0, $m['bookmaker_count']);
        }
    }

    public function test_two_way_devig_median_best_odd_edge_and_ev(): void
    {
        $r = Cmp::compare($this->pred(), $this->threeBookmakers(), $this->asOf);
        $this->assertSame('ok', $r['status']);
        $this->assertSame(2, $r['quoted_markets']);   // only total_goals 2.5 over/under have quotes

        $over = $this->find($r, 'total_goals', 2.5, 'over');
        // fair_over = under_odd / (over_odd + under_odd): Alpha 1.95/3.85, Bravo 1.80/3.80, Charlie 2.05/3.90
        $fairs = [1.95 / 3.85, 1.80 / 3.80, 2.05 / 3.90];
        $this->assertSame('ok', $over['status']);
        $this->assertSame(3, $over['bookmaker_count']);
        $this->assertEqualsWithDelta($fairs[0], $over['consensus_probability'], 1e-12);   // median = Alpha; medians sum to 1
        $this->assertEqualsWithDelta(array_sum([1 / 1.9 + 1 / 1.95]) - 1, $over['bookmakers'][0]['overround'], 1e-12);
        $this->assertEqualsWithDelta($fairs[1], $over['bookmakers'][1]['fair_probability'], 1e-12);

        $p = 1 - self::UNDER_25;
        $this->assertEqualsWithDelta($p - $fairs[0], $over['probability_edge'], 1e-9);
        $this->assertEqualsWithDelta(2.00, $over['best_odd'], 1e-12);
        $this->assertSame(['Bravo'], array_column($over['best_bookmakers'], 'name'));
        $this->assertTrue($over['best_bookmakers'][0]['fetched_at']->eq($this->asOf->copy()->subHour()));
        $this->assertEqualsWithDelta($p * 2.00 - 1, $over['theoretical_ev'], 1e-9);

        $under = $this->find($r, 'total_goals', 2.5, 'under');
        $this->assertEqualsWithDelta(1 - $fairs[0], $under['consensus_probability'], 1e-12);
        $this->assertEqualsWithDelta(2.05, $under['best_odd'], 1e-12);
        $this->assertSame(['Charlie'], array_column($under['best_bookmakers'], 'name'));
        $this->assertEqualsWithDelta(self::UNDER_25 * 2.05 - 1, $under['theoretical_ev'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $over['consensus_probability'] + $under['consensus_probability'], 1e-12);

        $this->assertSame(60, $over['latest_age_minutes']);
        $this->assertTrue($over['latest_fetched_at']->eq($over['oldest_fetched_at']));
    }

    public function test_even_number_of_bookmakers_uses_mean_of_middle_values_and_ties_list_all_best_bookmakers(): void
    {
        $rows = array_merge(
            $this->obs(1, 'Alpha', 'total_goals', 2.5, ['over' => 1.90, 'under' => 1.95]),
            $this->obs(2, 'Bravo', 'total_goals', 2.5, ['over' => 1.90, 'under' => 1.80]),
        );
        $r = Cmp::compare($this->pred(), $rows, $this->asOf);
        $over = $this->find($r, 'total_goals', 2.5, 'over');

        $medOver  = (1.95 / 3.85 + 1.80 / 3.70) / 2;
        $medUnder = (1.90 / 3.85 + 1.90 / 3.70) / 2;
        $this->assertEqualsWithDelta($medOver / ($medOver + $medUnder), $over['consensus_probability'], 1e-12);
        $this->assertEqualsWithDelta(1.90, $over['best_odd'], 1e-12);
        $this->assertSame(['Alpha', 'Bravo'], array_column($over['best_bookmakers'], 'name'));
    }

    public function test_btts_and_team_goals_are_separate_equivalent_markets(): void
    {
        $rows = array_merge(
            $this->obs(1, 'Alpha', 'btts', null, ['yes' => 1.70, 'no' => 2.10]),
            $this->obs(1, 'Alpha', 'team_goals_home', 1.5, ['over' => 2.30, 'under' => 1.60]),
        );
        $r = Cmp::compare($this->pred(), $rows, $this->asOf);

        $yes = $this->find($r, 'btts', null, 'yes');
        $this->assertEqualsWithDelta(2.10 / 3.80, $yes['consensus_probability'], 1e-12);
        $this->assertEqualsWithDelta(self::BTTS_YES * 1.70 - 1, $yes['theoretical_ev'], 1e-9);
        $this->assertSame('ok', $this->find($r, 'team_goals_home', 1.5, 'over')['status']);
        // same line on the other side / other markets stays unquoted: no cross-market or cross-side contamination
        $this->assertSame('no_quotes', $this->find($r, 'team_goals_away', 1.5, 'over')['status']);
        $this->assertSame('no_quotes', $this->find($r, 'total_goals', 1.5, 'over')['status']);
    }

    public function test_only_exactly_equivalent_lines_are_compared(): void
    {
        $rows = $this->obs(1, 'Alpha', 'total_goals', 3.5, ['over' => 2.60, 'under' => 1.50]);
        $r = Cmp::compare($this->pred(), $rows, $this->asOf);

        $this->assertSame('no_quotes', $this->find($r, 'total_goals', 2.5, 'over')['status']);
        $this->assertSame('ok', $this->find($r, 'total_goals', 3.5, 'over')['status']);
        $this->assertSame(2, $r['quoted_markets']);
    }

    public function test_latest_complete_observation_per_bookmaker_and_as_of_semantics(): void
    {
        $old = $this->asOf->copy()->subHours(6);
        $new = $this->asOf->copy()->subHours(1);
        $future = $this->asOf->copy()->addMinutes(5);
        $rows = array_merge(
            $this->obs(1, 'Alpha', 'total_goals', 2.5, ['over' => 1.80, 'under' => 2.05], $old),
            $this->obs(1, 'Alpha', 'total_goals', 2.5, ['over' => 1.95, 'under' => 1.90], $new),
            $this->obs(1, 'Alpha', 'total_goals', 2.5, ['over' => 2.50, 'under' => 1.50], $future),   // after as-of: never used
        );

        $r = Cmp::compare($this->pred(), $rows, $this->asOf);
        $over = $this->find($r, 'total_goals', 2.5, 'over');
        $this->assertSame(1, $over['bookmaker_count']);
        $this->assertEqualsWithDelta(1.95, $over['best_odd'], 1e-12);

        $earlier = Cmp::compare($this->pred(), $rows, $this->asOf->copy()->subHours(3));
        $this->assertEqualsWithDelta(1.80, $this->find($earlier, 'total_goals', 2.5, 'over')['best_odd'], 1e-12);

        $none = Cmp::compare($this->pred(), $rows, $this->asOf->copy()->subHours(8));
        $this->assertSame('no_quotes', $none['status']);
    }

    public function test_selections_from_different_observations_are_never_mixed(): void
    {
        $t1 = $this->asOf->copy()->subHours(3);
        $t2 = $this->asOf->copy()->subHours(1);
        // newest fetch only carries "over" (incomplete) => the older COMPLETE pair is used, never over(new)+under(old)
        $rows = array_merge(
            $this->obs(1, 'Alpha', 'total_goals', 2.5, ['over' => 1.80, 'under' => 2.05], $t1, 'obs-complete'),
            $this->obs(1, 'Alpha', 'total_goals', 2.5, ['over' => 2.40], $t2, 'obs-incomplete'),
        );
        $r = Cmp::compare($this->pred(), $rows, $this->asOf);
        $this->assertEqualsWithDelta(1.80, $this->find($r, 'total_goals', 2.5, 'over')['best_odd'], 1e-12);
        $this->assertEqualsWithDelta(2.05, $this->find($r, 'total_goals', 2.5, 'under')['best_odd'], 1e-12);

        // rows sharing an observation_id but with different fetch times / selections of another line are rejected
        $mixed = array_merge(
            $this->obs(1, 'Alpha', 'total_goals', 2.5, ['over' => 1.80], $t1, 'obs-x'),
            $this->obs(1, 'Alpha', 'total_goals', 2.5, ['under' => 2.05], $t2, 'obs-x'),
        );
        $this->assertSame('no_quotes', Cmp::compare($this->pred(), $mixed, $this->asOf)['status']);
    }

    public function test_invalid_duplicate_and_not_pre_match_observations_are_ignored(): void
    {
        $rows = array_merge(
            $this->obs(1, 'Alpha', 'total_goals', 0.5, ['over' => 1.00, 'under' => 11.0]),                  // odd not > 1.0
            $this->obs(2, 'Bravo', 'total_goals', 1.5, ['over' => 1.40, 'under' => 3.0], $this->kickoff->copy()->addMinute()),   // fetched after kickoff and after as-of
            $this->obs(3, 'Charlie', 'total_goals', 3.5, ['over' => 2.6, 'under' => 1.5], $this->asOf->copy()->subHour(), null, $this->asOf->copy()->subHours(2)), // fetched >= kickoff known at fetch
        );
        $dup = $this->obs(4, 'Delta', 'total_goals', 4.5, ['over' => 5.0, 'under' => 1.2], null, 'obs-dup');
        $dup[] = array_merge($dup[0], ['id' => 999]);   // the same selection twice in one observation
        $rows = array_merge($rows, $dup);

        $r = Cmp::compare($this->pred(), $rows, $this->asOf);
        $this->assertSame('no_quotes', $r['status']);
        $this->assertSame(0, $r['quoted_markets']);
    }

    public function test_missing_or_unusable_prediction_has_explicit_status(): void
    {
        $this->assertSame('no_prediction', Cmp::compare(null, $this->threeBookmakers(), $this->asOf)['status']);
        $this->assertSame([], Cmp::compare(null, [], $this->asOf)['markets']);

        $noL3 = Cmp::compare(['lambda_home' => 1.5, 'lambda_away' => 1.2, 'lambda3' => null], $this->threeBookmakers(), $this->asOf);
        $this->assertSame('invalid_prediction', $noL3['status']);
        $this->assertSame([], $noL3['markets']);

        $badLambda = Cmp::compare(['lambda_home' => 0.0, 'lambda_away' => 1.2, 'lambda3' => 0.15], [], $this->asOf);
        $this->assertSame('invalid_prediction', $badLambda['status']);
    }

    public function test_lambda3_uses_the_same_safety_cap_as_the_1x2_engine(): void
    {
        $r = Cmp::compare(['lambda_home' => 0.1, 'lambda_away' => 1.2, 'lambda3' => 0.15], [], $this->asOf);

        $this->assertSame('no_quotes', $r['status']);
        $this->assertEqualsWithDelta(0.1 * 0.9999, $r['prediction']['lambda3'], 1e-15);
    }
}
