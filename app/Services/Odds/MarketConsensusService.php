<?php

namespace App\Services\Odds;

use App\Services\Matches\MatchMarketOddsBuilder;
use Carbon\CarbonInterface;

/**
 * Value Engine V1 (diagnostic) — market 1X2 available AT a given instant.
 *
 * As-of semantics (reusable for prospective backtests, Learning Loop and
 * prediction-vs-market at the real prediction time): for every bookmaker the
 * LATEST snapshot with fetched_at <= $asOf that is certified pre-match
 * (fetched_at < kickoff_at), with complete HOME/DRAW/AWAY all > 1. Nothing
 * received after $asOf is ever used. Read-only.
 *
 * Fair consensus (no bookmaker weights):
 *   1. per bookmaker: raw p_i = 1/odd_i, then de-vig by proportional
 *      normalisation: p_i_fair = p_i / (p_H + p_D + p_A);
 *   2. per outcome: MEDIAN of p_fair across the bookmakers (mean of the two
 *      middle values for an even count);
 *   3. the three medians are re-normalised to sum to 1.
 *
 * Best executable odds: max odd per outcome over the SAME bookmaker set
 * (all bookmakers tied at the max are listed).
 *
 * No edge / value / stake / recommendation is computed here.
 */
class MarketConsensusService
{
    private const OUTCOMES = ['home', 'draw', 'away'];

    /**
     * @return array{
     *   as_of: CarbonInterface,
     *   bookmaker_count: int,
     *   bookmakers: array<int, array{bookmaker_id:int, name:string, home:float, draw:float, away:float,
     *                                 fair: array{home:float, draw:float, away:float}, overround:float, fetched_at:CarbonInterface}>,
     *   latest_fetched_at: CarbonInterface,
     *   consensus: array{home:float, draw:float, away:float},
     *   fair_min: array{home:float, draw:float, away:float},
     *   fair_max: array{home:float, draw:float, away:float},
     *   best: array{home:?array{odd:float,bookmakers:array<int,string>}, draw:?array{odd:float,bookmakers:array<int,string>}, away:?array{odd:float,bookmakers:array<int,string>}},
     * }|null  null when no bookmaker has a valid snapshot as of $asOf
     */
    public static function asOf(int $matchId, CarbonInterface $asOf): ?array
    {
        $market = MatchMarketOddsBuilder::forMatch($matchId, $asOf);
        if ($market['bookmakers'] === []) {
            return null;
        }

        $bookmakers = [];
        foreach ($market['bookmakers'] as $b) {
            $raw = ['home' => 1 / $b['home'], 'draw' => 1 / $b['draw'], 'away' => 1 / $b['away']];
            $sum = array_sum($raw);

            $bookmakers[] = [
                'bookmaker_id' => $b['bookmaker_id'],
                'name'         => $b['name'],
                'home'         => $b['home'],
                'draw'         => $b['draw'],
                'away'         => $b['away'],
                'fair'         => ['home' => $raw['home'] / $sum, 'draw' => $raw['draw'] / $sum, 'away' => $raw['away'] / $sum],
                'overround'    => $sum - 1,
                'fetched_at'   => $b['fetched_at'],
            ];
        }

        $medians = [];
        $min = [];
        $max = [];
        foreach (self::OUTCOMES as $o) {
            $values       = array_column(array_column($bookmakers, 'fair'), $o);
            $medians[$o]  = self::median($values);
            $min[$o]      = min($values);
            $max[$o]      = max($values);
        }
        $total     = array_sum($medians);
        $consensus = array_map(static fn (float $m) => $m / $total, $medians);

        $latest = null;
        foreach ($bookmakers as $b) {
            if ($latest === null || $b['fetched_at']->gt($latest)) {
                $latest = $b['fetched_at'];
            }
        }

        return [
            'as_of'             => $asOf,
            'bookmaker_count'   => count($bookmakers),
            'bookmakers'        => $bookmakers,
            'latest_fetched_at' => $latest,
            'consensus'         => $consensus,
            'fair_min'          => $min,
            'fair_max'          => $max,
            'best'              => $market['best'],
        ];
    }

    /** @param array<int, float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
