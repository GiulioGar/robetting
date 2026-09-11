<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\TeamStructuralRatingCalculator;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Feature tests for TeamStructuralRatingCalculator.
 *
 * Expected values are derived directly from the formula with config defaults:
 *   base_rating = 1500, beta = 150, reference_market_value = 100_000_000
 *
 *   structural_rating = 1500 + 150 * ln(market_value / 100_000_000)
 *
 * Tests:
 *  [A]  Reference value → rating equals base_rating exactly
 *  [B]  Napoli (€485M) → rating ≈ 1737
 *  [C]  PSV (€275M) → rating ≈ 1652
 *  [D]  Borac Banja Luka (€9.15M) → rating ≈ 1141 (small/minor club)
 *  [E]  structural_raw = ln(market_value) exactly
 *  [F]  Invariance: rating difference depends only on value ratio, not absolutes
 *  [G]  market_value = 0 throws InvalidArgumentException
 *  [H]  market_value < 0 throws InvalidArgumentException
 */
class TeamStructuralRatingCalculatorTest extends TestCase
{
    private const DELTA = 0.01; // float comparison tolerance

    // ─── [A] Reference value → base_rating exactly ───────────────────────────
    //
    // ln(100_000_000 / 100_000_000) = ln(1) = 0
    // structural_rating = 1500 + 150 * 0 = 1500
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reference_market_value_produces_base_rating(): void
    {
        $result = TeamStructuralRatingCalculator::calculateFromMarketValue(100_000_000);

        $this->assertEqualsWithDelta(1500.0, $result['structural_rating'], self::DELTA);
    }

    // ─── [B] Napoli ── €485M → ≈ 1737 ───────────────────────────────────────
    //
    // ln(485_000_000 / 100_000_000) = ln(4.85) ≈ 1.57920
    // structural_rating = 1500 + 150 * 1.57920 ≈ 1736.88
    // ─────────────────────────────────────────────────────────────────────────

    public function test_napoli_market_value_produces_expected_rating(): void
    {
        $expected = 1500 + 150 * log(485_000_000 / 100_000_000);

        $result = TeamStructuralRatingCalculator::calculateFromMarketValue(485_000_000);

        $this->assertEqualsWithDelta($expected, $result['structural_rating'], self::DELTA);
        // Readable sanity: should round to ≈ 1737
        $this->assertEqualsWithDelta(1737.0, $result['structural_rating'], 0.5);
    }

    // ─── [C] PSV ── €275M → ≈ 1652 ──────────────────────────────────────────
    //
    // ln(275_000_000 / 100_000_000) = ln(2.75) ≈ 1.01160
    // structural_rating = 1500 + 150 * 1.01160 ≈ 1651.74
    // ─────────────────────────────────────────────────────────────────────────

    public function test_psv_market_value_produces_expected_rating(): void
    {
        $expected = 1500 + 150 * log(275_000_000 / 100_000_000);

        $result = TeamStructuralRatingCalculator::calculateFromMarketValue(275_000_000);

        $this->assertEqualsWithDelta($expected, $result['structural_rating'], self::DELTA);
        $this->assertEqualsWithDelta(1652.0, $result['structural_rating'], 0.5);
    }

    // ─── [D] Borac Banja Luka ── €9.15M → ≈ 1141 ────────────────────────────
    //
    // ln(9_150_000 / 100_000_000) = ln(0.0915) ≈ −2.39179
    // structural_rating = 1500 + 150 * (−2.39179) ≈ 1141.23
    // ─────────────────────────────────────────────────────────────────────────

    public function test_borac_market_value_produces_expected_rating(): void
    {
        $expected = 1500 + 150 * log(9_150_000 / 100_000_000);

        $result = TeamStructuralRatingCalculator::calculateFromMarketValue(9_150_000);

        $this->assertEqualsWithDelta($expected, $result['structural_rating'], self::DELTA);
        $this->assertEqualsWithDelta(1141.0, $result['structural_rating'], 0.5);
    }

    // ─── [E] structural_raw = ln(market_value) ───────────────────────────────

    public function test_structural_raw_equals_natural_log_of_market_value(): void
    {
        $marketValue = 485_000_000;
        $result      = TeamStructuralRatingCalculator::calculateFromMarketValue($marketValue);

        $this->assertEqualsWithDelta(log($marketValue), $result['structural_raw'], self::DELTA);
    }

    // ─── [F] Invariance: Δrating depends only on value ratio ─────────────────
    //
    // A=200M vs B=100M  → Δ = 150 * ln(2) ≈ 103.97
    // A=400M vs B=200M  → Δ = 150 * ln(2) ≈ 103.97
    //
    // Doubling both values keeps the gap identical.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_rating_difference_depends_only_on_value_ratio(): void
    {
        $r200 = TeamStructuralRatingCalculator::calculateFromMarketValue(200_000_000);
        $r100 = TeamStructuralRatingCalculator::calculateFromMarketValue(100_000_000);
        $diff1 = $r200['structural_rating'] - $r100['structural_rating'];

        $r400 = TeamStructuralRatingCalculator::calculateFromMarketValue(400_000_000);
        $r200b = TeamStructuralRatingCalculator::calculateFromMarketValue(200_000_000);
        $diff2 = $r400['structural_rating'] - $r200b['structural_rating'];

        $this->assertEqualsWithDelta($diff1, $diff2, self::DELTA);
        // Sanity: the gap should equal beta * ln(2)
        $this->assertEqualsWithDelta(150 * log(2), $diff1, self::DELTA);
    }

    // ─── [G] market_value = 0 → InvalidArgumentException ────────────────────

    public function test_zero_market_value_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/market_value must be a positive integer/');

        TeamStructuralRatingCalculator::calculateFromMarketValue(0);
    }

    // ─── [H] market_value < 0 → InvalidArgumentException ────────────────────

    public function test_negative_market_value_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/market_value must be a positive integer/');

        TeamStructuralRatingCalculator::calculateFromMarketValue(-1_000_000);
    }
}
