<?php

namespace Tests\Feature\Prediction;

use App\Models\FootballMatch;
use App\Services\Prediction\MatchOutcomeLabelBuilder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for MatchOutcomeLabelBuilder.
 *
 * Tests:
 *  [A]  2-1 home win — correct labels
 *  [B]  1-1 draw — correct labels
 *  [C]  0-2 away win — correct labels
 *  [D]  0-0 — draw=1, btts=0, over_2_5=0
 *  [E]  2-2 — draw=1, btts=1, over_2_5=1
 *  [F]  3-0 — home_win=1, btts=0, over_2_5=1
 *  [G]  non-definitive status → InvalidArgumentException
 *  [H]  home_score_ft null → InvalidArgumentException
 *  [I]  away_score_ft null → InvalidArgumentException
 *  [J]  negative score → InvalidArgumentException
 *  [K]  binary labels always sum to 1
 *  [L]  output has exactly the 10 required keys
 *  [M]  build() makes no DB queries for an in-memory model
 *  [N]  ET/penalty scores are ignored; labels use FT regulation time only
 */
class MatchOutcomeLabelBuilderTest extends TestCase
{
    private function finished(array $attrs = []): FootballMatch
    {
        return new FootballMatch(array_merge([
            'status'        => 'finished',
            'home_score_ft' => 1,
            'away_score_ft' => 0,
        ], $attrs));
    }

    // ── [A] Home win 2-1 ─────────────────────────────────────────────────────

    public function test_home_win_2_1(): void
    {
        $labels = MatchOutcomeLabelBuilder::build($this->finished([
            'home_score_ft' => 2,
            'away_score_ft' => 1,
        ]));

        $this->assertSame(2,   $labels['home_goals']);
        $this->assertSame(1,   $labels['away_goals']);
        $this->assertSame(1,   $labels['goal_diff']);
        $this->assertSame(3,   $labels['total_goals']);
        $this->assertSame('H', $labels['result_1x2']);
        $this->assertSame(1,   $labels['home_win']);
        $this->assertSame(0,   $labels['draw']);
        $this->assertSame(0,   $labels['away_win']);
        $this->assertSame(1,   $labels['btts']);
        $this->assertSame(1,   $labels['over_2_5']);
    }

    // ── [B] Draw 1-1 ─────────────────────────────────────────────────────────

    public function test_draw_1_1(): void
    {
        $labels = MatchOutcomeLabelBuilder::build($this->finished([
            'home_score_ft' => 1,
            'away_score_ft' => 1,
        ]));

        $this->assertSame(0,   $labels['goal_diff']);
        $this->assertSame('D', $labels['result_1x2']);
        $this->assertSame(0,   $labels['home_win']);
        $this->assertSame(1,   $labels['draw']);
        $this->assertSame(0,   $labels['away_win']);
        $this->assertSame(1,   $labels['btts']);
        $this->assertSame(0,   $labels['over_2_5']);
    }

    // ── [C] Away win 0-2 ─────────────────────────────────────────────────────

    public function test_away_win_0_2(): void
    {
        $labels = MatchOutcomeLabelBuilder::build($this->finished([
            'home_score_ft' => 0,
            'away_score_ft' => 2,
        ]));

        $this->assertSame(-2,  $labels['goal_diff']);
        $this->assertSame('A', $labels['result_1x2']);
        $this->assertSame(0,   $labels['home_win']);
        $this->assertSame(0,   $labels['draw']);
        $this->assertSame(1,   $labels['away_win']);
        $this->assertSame(0,   $labels['btts']);
        $this->assertSame(0,   $labels['over_2_5']);
    }

    // ── [D] 0-0 ──────────────────────────────────────────────────────────────

    public function test_0_0_draw_btts_0_over25_0(): void
    {
        $labels = MatchOutcomeLabelBuilder::build($this->finished([
            'home_score_ft' => 0,
            'away_score_ft' => 0,
        ]));

        $this->assertSame(1,   $labels['draw']);
        $this->assertSame(0,   $labels['btts']);
        $this->assertSame(0,   $labels['over_2_5']);
        $this->assertSame(0,   $labels['total_goals']);
    }

    // ── [E] 2-2 ──────────────────────────────────────────────────────────────

    public function test_2_2_draw_btts_1_over25_1(): void
    {
        $labels = MatchOutcomeLabelBuilder::build($this->finished([
            'home_score_ft' => 2,
            'away_score_ft' => 2,
        ]));

        $this->assertSame(1, $labels['draw']);
        $this->assertSame(1, $labels['btts']);
        $this->assertSame(1, $labels['over_2_5']);
    }

    // ── [F] 3-0 ──────────────────────────────────────────────────────────────

    public function test_3_0_home_win_btts_0_over25_1(): void
    {
        $labels = MatchOutcomeLabelBuilder::build($this->finished([
            'home_score_ft' => 3,
            'away_score_ft' => 0,
        ]));

        $this->assertSame(1,   $labels['home_win']);
        $this->assertSame(0,   $labels['btts']);
        $this->assertSame(1,   $labels['over_2_5']);
        $this->assertSame('H', $labels['result_1x2']);
    }

    // ── [G] Non-definitive status ─────────────────────────────────────────────

    public function test_non_definitive_status_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not a definitive outcome status/');

        MatchOutcomeLabelBuilder::build($this->finished(['status' => 'scheduled']));
    }

    public function test_live_status_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MatchOutcomeLabelBuilder::build($this->finished(['status' => 'live']));
    }

    public function test_postponed_status_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MatchOutcomeLabelBuilder::build($this->finished(['status' => 'postponed']));
    }

    // ── [H] home_score_ft null ───────────────────────────────────────────────

    public function test_null_home_score_ft_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/home_score_ft is null/');

        MatchOutcomeLabelBuilder::build($this->finished(['home_score_ft' => null]));
    }

    // ── [I] away_score_ft null ───────────────────────────────────────────────

    public function test_null_away_score_ft_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/away_score_ft is null/');

        MatchOutcomeLabelBuilder::build($this->finished(['away_score_ft' => null]));
    }

    // ── [J] Negative score ───────────────────────────────────────────────────

    public function test_negative_home_score_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/negative score/');

        MatchOutcomeLabelBuilder::build($this->finished(['home_score_ft' => -1]));
    }

    public function test_negative_away_score_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/negative score/');

        MatchOutcomeLabelBuilder::build($this->finished(['away_score_ft' => -2]));
    }

    // ── [K] Binary labels always sum to 1 ────────────────────────────────────

    public function test_binary_labels_always_sum_to_1(): void
    {
        $cases = [
            [3, 0],  // home win
            [1, 1],  // draw
            [0, 2],  // away win
            [0, 0],  // 0-0 draw
            [2, 2],  // draw with goals
            [5, 1],  // big home win
        ];

        foreach ($cases as [$home, $away]) {
            $labels = MatchOutcomeLabelBuilder::build($this->finished([
                'home_score_ft' => $home,
                'away_score_ft' => $away,
            ]));
            $this->assertSame(
                1,
                $labels['home_win'] + $labels['draw'] + $labels['away_win'],
                "Binary labels must sum to 1 for score {$home}-{$away}."
            );
        }
    }

    // ── [L] Output has exactly 10 required keys ───────────────────────────────

    public function test_output_has_exactly_10_required_keys(): void
    {
        $labels = MatchOutcomeLabelBuilder::build($this->finished([
            'home_score_ft' => 1,
            'away_score_ft' => 0,
        ]));

        $expected = [
            'home_goals', 'away_goals', 'goal_diff', 'total_goals',
            'result_1x2', 'home_win', 'draw', 'away_win',
            'btts', 'over_2_5',
        ];

        $this->assertCount(10, $labels);
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $labels, "Missing key: '{$key}'");
        }
    }

    // ── [M] No DB queries for in-memory model ────────────────────────────────

    public function test_build_makes_no_db_queries_for_in_memory_model(): void
    {
        $match = $this->finished(['home_score_ft' => 2, 'away_score_ft' => 0]);

        DB::enableQueryLog();
        MatchOutcomeLabelBuilder::build($match);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries, 'build() must not issue any DB queries.');
    }

    // ── [N] ET/PEN scores are ignored — FT regulation time only ─────────────

    public function test_et_score_is_ignored_labels_use_ft_only(): void
    {
        // Mirrors real match #8480 (finished 1-1 FT, 2-1 AET).
        // Labels must reflect the 1-1 regulation draw, not the AET winner.
        $match = new FootballMatch([
            'status'               => 'finished',
            'home_score_ft'        => 1,
            'away_score_ft'        => 1,
            'home_score_et'        => 1,
            'away_score_et'        => 0,
            'home_score_penalties' => null,
            'away_score_penalties' => null,
        ]);

        $labels = MatchOutcomeLabelBuilder::build($match);

        $this->assertSame(1,   $labels['home_goals']);
        $this->assertSame(1,   $labels['away_goals']);
        $this->assertSame('D', $labels['result_1x2']);
        $this->assertSame(1,   $labels['draw']);
        $this->assertSame(0,   $labels['home_win']);
        $this->assertSame(0,   $labels['away_win']);
    }

    public function test_penalty_score_is_ignored_labels_use_ft_only(): void
    {
        // Hypothetical PEN match: finished 1-1 FT, away wins on penalties 5-3.
        // Labels must reflect the 1-1 FT draw.
        $match = new FootballMatch([
            'status'               => 'finished',
            'home_score_ft'        => 1,
            'away_score_ft'        => 1,
            'home_score_et'        => null,
            'away_score_et'        => null,
            'home_score_penalties' => 3,
            'away_score_penalties' => 5,
        ]);

        $labels = MatchOutcomeLabelBuilder::build($match);

        $this->assertSame('D', $labels['result_1x2']);
        $this->assertSame(1,   $labels['draw']);
    }

    // ── Definitive status variants: awarded/walkover accepted if FT present ──

    public function test_awarded_match_with_valid_ft_score_produces_labels(): void
    {
        $match = new FootballMatch([
            'status'        => 'awarded',
            'home_score_ft' => 0,
            'away_score_ft' => 0,
        ]);

        $labels = MatchOutcomeLabelBuilder::build($match);

        $this->assertSame('D', $labels['result_1x2']);
        $this->assertSame(0, $labels['btts']);
    }

    public function test_walkover_match_with_ft_score_produces_labels(): void
    {
        $match = new FootballMatch([
            'status'        => 'walkover',
            'home_score_ft' => 3,
            'away_score_ft' => 0,
        ]);

        $labels = MatchOutcomeLabelBuilder::build($match);

        $this->assertSame('H', $labels['result_1x2']);
        $this->assertSame(1, $labels['home_win']);
    }

    public function test_awarded_match_with_null_ft_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $match = new FootballMatch([
            'status'        => 'awarded',
            'home_score_ft' => null,
            'away_score_ft' => null,
        ]);

        MatchOutcomeLabelBuilder::build($match);
    }
}
