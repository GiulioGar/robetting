<?php

namespace App\Services\Odds;

/**
 * Value Engine V1 (diagnostic only) — per outcome comparison of a model
 * probability with the market consensus and the best available odd:
 *
 *   probability_edge = model_probability - market_fair_probability
 *   theoretical_ev   = model_probability * best_odd - 1
 *
 * Pure arithmetic over already-computed inputs. NO classification (value /
 * no-value), NO threshold, NO stake, NO recommendation; odds are never fed
 * back to any model.
 */
class ModelMarketDiagnostics
{
    /**
     * @param  array{home:float, draw:float, away:float}  $model       model probabilities (1X2)
     * @param  array<string, mixed>                       $market      output of MarketConsensusService::asOf() (non-null)
     * @return array<string, array{model_probability:float, market_fair_probability:float, probability_edge:float,
     *                             best_odd:?float, best_bookmakers:array<int,string>, theoretical_ev:?float}>
     */
    public static function compare(array $model, array $market): array
    {
        $out = [];
        foreach (['home', 'draw', 'away'] as $o) {
            $fair = $market['consensus'][$o];
            $best = $market['best'][$o];

            $out[$o] = [
                'model_probability'       => $model[$o],
                'market_fair_probability' => $fair,
                'probability_edge'        => $model[$o] - $fair,
                'best_odd'                => $best['odd'] ?? null,
                'best_bookmakers'         => $best['bookmakers'] ?? [],
                'theoretical_ev'          => isset($best['odd']) ? $model[$o] * $best['odd'] - 1 : null,
            ];
        }

        return $out;
    }
}
