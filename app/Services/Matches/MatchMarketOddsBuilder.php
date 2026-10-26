<?php

namespace App\Services\Matches;

use App\Models\OddsSnapshot;
use Carbon\CarbonInterface;

/**
 * Raw "Mercato 1X2" data for the Match Page, read-only over the append-only
 * odds_snapshots table (Odds V1). Market information only: no fair
 * probability, consensus, edge, value, ROI, stake or bookmaker preference.
 *
 *  - only snapshots certified pre-match (fetched_at < kickoff_at) and already
 *    received at $asOf (fetched_at <= $asOf, default now) for the Match Winner bet;
 *  - per bookmaker, the LATEST such snapshot only (older ones are history);
 *  - incomplete snapshots (any selection missing / <= 1) are skipped;
 *  - bookmakers sorted by name (stable), no "official" bookmaker;
 *  - per bookmaker: raw overround = 1/home + 1/draw + 1/away - 1;
 *  - best available odd per outcome, listing every bookmaker tied at the max.
 */
class MatchMarketOddsBuilder
{
    /**
     * @return array{
     *   bookmakers: array<int, array{bookmaker_id:int, name:string, home:float, draw:float, away:float,
     *                                 overround:float, fetched_at:CarbonInterface, provider_updated_at:?CarbonInterface}>,
     *   best: array{home:?array{odd:float,bookmakers:array<int,string>}, draw:?array{odd:float,bookmakers:array<int,string>}, away:?array{odd:float,bookmakers:array<int,string>}}
     * }
     */
    public static function forMatch(int $matchId, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= now();

        $rows = OddsSnapshot::where('match_id', $matchId)
            ->where('bet_id', (int) config('api-football.odds.match_winner_bet_id', 1))
            ->certifiedPreMatch()
            ->where('fetched_at', '<=', $asOf)
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->get();

        $latest = [];
        foreach ($rows as $row) {
            if (isset($latest[$row->bookmaker_id])) {
                continue; // already have the most recent snapshot of this bookmaker
            }
            $latest[$row->bookmaker_id] = $row;
        }

        $bookmakers = [];
        foreach ($latest as $row) {
            $h = $row->home_odd;
            $d = $row->draw_odd;
            $a = $row->away_odd;
            if ($h === null || $d === null || $a === null || $h <= 1.0 || $d <= 1.0 || $a <= 1.0) {
                continue;
            }

            $bookmakers[] = [
                'bookmaker_id'        => (int) $row->bookmaker_id,
                'name'                => (string) $row->bookmaker_name,
                'home'                => $h,
                'draw'                => $d,
                'away'                => $a,
                'overround'           => 1 / $h + 1 / $d + 1 / $a - 1,
                'fetched_at'          => $row->fetched_at,
                'provider_updated_at' => $row->provider_updated_at,
            ];
        }

        usort($bookmakers, static fn (array $x, array $y) => [mb_strtolower($x['name']), $x['bookmaker_id']] <=> [mb_strtolower($y['name']), $y['bookmaker_id']]);

        return ['bookmakers' => $bookmakers, 'best' => self::best($bookmakers)];
    }

    /** @param array<int, array<string, mixed>> $bookmakers */
    private static function best(array $bookmakers): array
    {
        $best = ['home' => null, 'draw' => null, 'away' => null];
        foreach (array_keys($best) as $outcome) {
            foreach ($bookmakers as $b) {
                if ($best[$outcome] === null || $b[$outcome] > $best[$outcome]['odd']) {
                    $best[$outcome] = ['odd' => $b[$outcome], 'bookmakers' => [$b['name']]];
                } elseif ($b[$outcome] === $best[$outcome]['odd']) {
                    $best[$outcome]['bookmakers'][] = $b['name'];
                }
            }
        }

        return $best;
    }
}
