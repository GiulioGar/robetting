<?php

namespace Tests\Unit\Services\DataSources\ApiFootball;

use App\Services\DataSources\ApiFootball\GoalOddsParser;
use PHPUnit\Framework\TestCase;

/**
 * Odds GOAL — pure parsing/normalisation of the 4 explicit GOAL bets (5, 8, 16, 17).
 */
class GoalOddsParserTest extends TestCase
{
    private const BETS = [5 => 'total_goals', 8 => 'btts', 16 => 'team_goals_home', 17 => 'team_goals_away'];

    /** @param array<string,string> $pairs label => odd */
    private function values(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $label => $odd) {
            $out[] = ['value' => (string) $label, 'odd' => $odd];
        }

        return $out;
    }

    private function lines(array $parsed): array
    {
        return array_map(fn ($o) => $o['line'], $parsed['observations']);
    }

    public function test_total_goals_accepts_half_lines_0_5_to_4_5_with_complete_pairs(): void
    {
        $v = [];
        foreach ([0.5, 1.5, 2.5, 3.5, 4.5] as $l) {
            $v["Over $l"]  = '1.80';
            $v["Under $l"] = '2.00';
        }
        $p = GoalOddsParser::parse(5, self::BETS, $this->values($v));

        $this->assertSame([0.5, 1.5, 2.5, 3.5, 4.5], $this->lines($p));
        $this->assertSame(0, $p['discarded']);
        $o = $p['observations'][2];
        $this->assertSame('total_goals', $o['market']);
        $this->assertSame(['over', 'under'], array_keys($o['selections']));
        $this->assertSame(['odd' => 1.8, 'label' => 'Over 2.5'], $o['selections']['over']);
        $this->assertSame(['odd' => 2.0, 'label' => 'Under 2.5'], $o['selections']['under']);
    }

    public function test_asian_whole_and_out_of_range_lines_are_discarded(): void
    {
        $v = [];
        foreach ([1.25, 1.75, 2.0, 2.25, 2.75, 3.0, 5.5, 6.5] as $l) {
            $v["Over $l"]  = '1.90';
            $v["Under $l"] = '1.90';
        }
        $v['Over 2.5'] = '1.95';
        $v['Under 2.5'] = '1.85';
        $p = GoalOddsParser::parse(5, self::BETS, $this->values($v));

        $this->assertSame([2.5], $this->lines($p));
        $this->assertSame(16, $p['discarded']);   // every non-accepted label
    }

    public function test_incomplete_or_invalid_pairs_are_dropped_as_a_whole(): void
    {
        $p = GoalOddsParser::parse(5, self::BETS, $this->values([
            'Over 0.5' => '1.00', 'Under 0.5' => '11.60',   // odd not > 1.0
            'Over 1.5' => '1.30',                            // Under missing
            'Over 2.5' => 'n/a', 'Under 2.5' => '2.10',      // not numeric
            'Over 3.5' => '2.50', 'Under 3.5' => '1.55',     // valid
            'Under 4.5' => '1.20',                           // Over missing
        ]));

        $this->assertSame([3.5], $this->lines($p));
        $this->assertSame(4, $p['discarded']);
    }

    public function test_duplicate_selection_for_the_same_line_is_ambiguous_and_dropped(): void
    {
        $values = [
            ['value' => 'Over 2.5', 'odd' => '1.90'], ['value' => 'Over 2.5', 'odd' => '1.95'], ['value' => 'Under 2.5', 'odd' => '1.90'],
            ['value' => 'Over 1.5', 'odd' => '1.30'], ['value' => 'Under 1.5', 'odd' => '3.40'],
        ];
        $p = GoalOddsParser::parse(5, self::BETS, $values);

        $this->assertSame([1.5], $this->lines($p));
    }

    public function test_team_goals_lines_stop_at_2_5_and_use_the_side_market(): void
    {
        $v = [];
        foreach ([0.5, 1.5, 2.5, 3.5, 4.5] as $l) {
            $v["Over $l"] = '2.00';
            $v["Under $l"] = '1.80';
        }
        $home = GoalOddsParser::parse(16, self::BETS, $this->values($v));
        $away = GoalOddsParser::parse(17, self::BETS, $this->values($v));

        $this->assertSame([0.5, 1.5, 2.5], $this->lines($home));
        $this->assertSame('team_goals_home', $home['observations'][0]['market']);
        $this->assertSame([0.5, 1.5, 2.5], $this->lines($away));
        $this->assertSame('team_goals_away', $away['observations'][0]['market']);
        $this->assertSame(4, $home['discarded']);   // 3.5 and 4.5, two selections each
    }

    public function test_btts_yes_no_pair_is_normalised_without_a_line(): void
    {
        $p = GoalOddsParser::parse(8, self::BETS, $this->values(['Yes' => '1.55', 'No' => '2.40']));

        $this->assertCount(1, $p['observations']);
        $o = $p['observations'][0];
        $this->assertSame('btts', $o['market']);
        $this->assertNull($o['line']);
        $this->assertSame(['yes', 'no'], array_keys($o['selections']));
        $this->assertSame(['odd' => 1.55, 'label' => 'Yes'], $o['selections']['yes']);
    }

    public function test_btts_incomplete_or_invalid_is_dropped(): void
    {
        $this->assertSame([], GoalOddsParser::parse(8, self::BETS, $this->values(['Yes' => '1.55']))['observations']);
        $this->assertSame([], GoalOddsParser::parse(8, self::BETS, $this->values(['Yes' => '1.55', 'No' => '1.00']))['observations']);
        $this->assertSame([], GoalOddsParser::parse(8, self::BETS, $this->values(['Yes' => '1.55', 'Maybe' => '2.40']))['observations']);
    }

    public function test_other_bets_including_half_time_markets_are_never_accepted(): void
    {
        $v = $this->values(['Over 1.5' => '1.50', 'Under 1.5' => '2.50']);

        foreach ([1, 6, 26, 38, 105, 349] as $betId) {   // 1X2, 1st/2nd half O/U, exact goals, 1st-half team total, goals range
            $p = GoalOddsParser::parse($betId, self::BETS, $v);
            $this->assertSame([], $p['observations'], "bet {$betId}");
        }
    }
}
