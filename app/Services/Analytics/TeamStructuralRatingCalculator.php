<?php

namespace App\Services\Analytics;

use App\Models\TeamMarketValueSnapshot;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Structural Rating v1: a market-value-based proxy for the intrinsic, stable
 * quality of a squad — independent of recent form, results, or schedule.
 *
 * ── Formula ──────────────────────────────────────────────────────────────────
 *
 *   structural_raw    = ln(market_value)
 *
 *   structural_rating = base_rating
 *                     + beta * ln(market_value / reference_market_value)
 *
 * Parameters (read from config/analytics.php — never hardcoded here):
 *
 *   base_rating            = 1500   Centre of the rating scale.
 *   beta                   = 150    Spread: how many points separate a 1-neper
 *                                   (≈ e×) difference in market value.
 *   reference_market_value = 100M   Anchor: a €100M squad maps exactly to
 *                                   base_rating.
 *
 * ── Invariance property ──────────────────────────────────────────────────────
 *
 * The rating difference between two squads depends only on their VALUE RATIO,
 * not on their absolute values:
 *
 *   Δrating = beta * ln(A / B)
 *
 * Doubling both values leaves the gap unchanged. This is by design.
 *
 * ── Precision ────────────────────────────────────────────────────────────────
 *
 * All intermediate values are kept as native PHP floats (64-bit IEEE 754).
 * No rounding is applied before the return array. Rounding for display is the
 * responsibility of the caller (controller / blade).
 *
 * ── Snapshot lookup ──────────────────────────────────────────────────────────
 *
 * calculateForTeamAtDate() retrieves the most recent snapshot with
 * snapshot_date <= reference_date for the given data source, then delegates to
 * calculateFromMarketValue(). No snapshot → returns null. No fallback, no
 * invented defaults. dataSourceId is required: the caller always decides which
 * source to use; no implicit cross-source selection ever occurs.
 */
class TeamStructuralRatingCalculator
{
    /**
     * Compute structural_raw and structural_rating from a squad's market value.
     *
     * @param  int  $marketValue  Total squad market value in EUR (must be > 0).
     * @return array{structural_raw: float, structural_rating: float}
     *
     * @throws InvalidArgumentException if $marketValue is not a positive integer.
     */
    public static function calculateFromMarketValue(int $marketValue): array
    {
        if ($marketValue <= 0) {
            throw new InvalidArgumentException(
                "market_value must be a positive integer, {$marketValue} given."
            );
        }

        $baseRating           = (float) config('analytics.structural_rating.base_rating');
        $beta                 = (float) config('analytics.structural_rating.beta');
        $referenceMarketValue = (float) config('analytics.structural_rating.reference_market_value');

        $raw    = log($marketValue);
        $rating = $baseRating + $beta * log($marketValue / $referenceMarketValue);

        return [
            'structural_raw'    => $raw,
            'structural_rating' => $rating,
        ];
    }

    /**
     * Look up the most recent market-value snapshot for a team at or before
     * $referenceDate and compute the structural rating for that snapshot.
     *
     * Returns null when no snapshot exists with snapshot_date <= $referenceDate.
     * Never uses a snapshot dated after $referenceDate (no leakage).
     *
     * @param  int     $teamId         Canonical teams.id.
     * @param  Carbon  $referenceDate  Upper bound for snapshot selection.
     * @param  int     $dataSourceId   data_sources.id — always required; the
     *                                  caller explicitly chooses the source so
     *                                  no cross-source selection ever occurs.
     *
     * @return array{
     *     team_id:           int,
     *     data_source_id:    int,
     *     snapshot_date:     Carbon,
     *     market_value:      int,
     *     structural_raw:    float,
     *     structural_rating: float,
     * }|null
     */
    public static function calculateForTeamAtDate(
        int $teamId,
        Carbon $referenceDate,
        int $dataSourceId
    ): ?array {
        $snapshot = TeamMarketValueSnapshot::where('team_id', $teamId)
            ->where('data_source_id', $dataSourceId)
            ->where('snapshot_date', '<=', $referenceDate->toDateString())
            ->orderByDesc('snapshot_date')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            return null;
        }

        $computed = self::calculateFromMarketValue((int) $snapshot->market_value);

        return [
            'team_id'           => (int) $snapshot->team_id,
            'data_source_id'    => (int) $snapshot->data_source_id,
            'snapshot_date'     => $snapshot->snapshot_date,
            'market_value'      => (int) $snapshot->market_value,
            'structural_raw'    => $computed['structural_raw'],
            'structural_rating' => $computed['structural_rating'],
        ];
    }
}
