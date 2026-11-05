<?php

namespace App\Services\Odds;

use App\Models\GoalOddsSnapshot;
use App\Models\Prediction;
use App\Services\Prediction\GoalMarketProbabilityCalculator;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * GOAL market comparator (diagnostic, read-only): Robetting (Candidate47 lambdas -> Bivariate Poisson) vs the
 * bookmaker market for Over/Under totale, BTTS and Team Goals casa/trasferta. Multigol is out of scope.
 *
 * As-of semantics (same spirit as MarketConsensusService for the 1X2):
 *   - prediction: the latest OFFICIAL Candidate47 row with generated_at <= $asOf and generated_at < its kickoff_at;
 *   - quotes: only goal_odds_snapshots with fetched_at <= $asOf and certified pre-match (fetched_at < kickoff_at);
 *     nothing received after $asOf is ever used;
 *   - per (bookmaker, market, line) the LATEST COMPLETE observation: both selections come from the SAME
 *     observation_id (never mixed across fetches), with valid odds (> 1.0) and no duplicated selection.
 *   Only exactly equivalent (market, line, selection) are compared.
 *
 * Two-way de-vig (same method as the 1X2 consensus): per bookmaker p_i = 1/odd_i, fair_i = p_i / sum(p);
 * per selection the MEDIAN of fair_i across bookmakers (mean of the two middle values for an even count), then the
 * two medians are re-normalised to sum to 1.
 *
 *   model_fair_odd   = 1 / model_probability
 *   probability_edge = model_probability - consensus_probability
 *   theoretical_ev   = model_probability * best_odd - 1      (best_odd = max odd over the considered bookmakers)
 *
 * NO threshold, classification, stake, bet selection or recommendation is produced here; odds are never fed back to
 * any model. Missing/unusable inputs yield an explicit status and null values, never invented numbers.
 */
final class GoalMarketComparator
{
    public const MODEL_KEY = 'candidate47_structural_log';

    private const SELECTIONS = [
        'total_goals'     => ['over', 'under'],
        'team_goals_home' => ['over', 'under'],
        'team_goals_away' => ['over', 'under'],
        'btts'            => ['yes', 'no'],
    ];

    /**
     * Loads the prediction and quotes available at $asOf for a match and compares them.
     *
     * @return array<string, mixed>  see compare()
     */
    public static function forMatch(int $matchId, CarbonInterface $asOf): array
    {
        $asOfUtc = $asOf->copy()->utc();   // DB values are UTC; SQL bindings are formatted in the instance's own timezone

        $prediction = Prediction::where('match_id', $matchId)
            ->where('purpose', Prediction::PURPOSE_OFFICIAL)
            ->where('model_key', self::MODEL_KEY)
            ->where('generated_at', '<=', $asOfUtc)
            ->whereColumn('generated_at', '<', 'kickoff_at')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->first();

        return self::compare($prediction === null ? null : [
            'id'           => $prediction->id,
            'generated_at' => $prediction->generated_at,
            'lambda_home'  => $prediction->lambda_home,
            'lambda_away'  => $prediction->lambda_away,
            'lambda3'      => $prediction->lambda3,
        ], self::quoteRows($matchId, $asOf), $asOf);
    }

    /**
     * Stored GOAL quote rows for a match available at $asOf (fetched_at <= $asOf, certified pre-match), in the row
     * shape compare() expects. Shared by forMatch() and by callers that already hold the prediction (match page).
     *
     * @return list<array<string,mixed>>
     */
    public static function quoteRows(int $matchId, CarbonInterface $asOf): array
    {
        return GoalOddsSnapshot::where('match_id', $matchId)
            ->where('fetched_at', '<=', $asOf->copy()->utc())   // DB values are UTC (see forMatch)
            ->whereColumn('fetched_at', '<', 'kickoff_at')
            ->orderBy('id')
            ->get()
            ->map(fn (GoalOddsSnapshot $r) => [
                'id'                  => $r->id,
                'observation_id'      => $r->observation_id,
                'bookmaker_id'        => $r->bookmaker_id,
                'bookmaker_name'      => $r->bookmaker_name,
                'market'              => $r->market,
                'line'                => $r->line,
                'selection'           => $r->selection,
                'odd'                 => $r->odd,
                'provider_updated_at' => $r->provider_updated_at,
                'fetched_at'          => $r->fetched_at,
                'kickoff_at'          => $r->kickoff_at,
            ])
            ->all();
    }

    /**
     * Pure comparison.
     *
     * @param  array{id?:mixed,generated_at?:CarbonInterface,lambda_home:float,lambda_away:float,lambda3:?float}|null $prediction
     * @param  list<array<string,mixed>> $rows  one entry per stored selection:
     *         id, observation_id, bookmaker_id, bookmaker_name, market, line, selection, odd,
     *         provider_updated_at (?CarbonInterface), fetched_at, kickoff_at
     * @return array{
     *   status: string, as_of: CarbonInterface, prediction: ?array<string,mixed>, quoted_markets: int,
     *   markets: list<array<string,mixed>>
     * }  status: ok | no_prediction | invalid_prediction | no_quotes
     */
    public static function compare(?array $prediction, array $rows, CarbonInterface $asOf): array
    {
        $out = ['status' => 'no_prediction', 'as_of' => $asOf, 'prediction' => null, 'quoted_markets' => 0, 'markets' => []];

        if ($prediction === null) {
            return $out;
        }

        $lh = (float) ($prediction['lambda_home'] ?? 0);
        $la = (float) ($prediction['lambda_away'] ?? 0);
        $l3 = $prediction['lambda3'] ?? null;
        if ($l3 === null) {
            $out['status'] = 'invalid_prediction';

            return $out;
        }

        try {
            $l3Effective = GoalMarketProbabilityCalculator::effectiveLambda3($lh, $la, (float) $l3);
            $model = GoalMarketProbabilityCalculator::calculate($lh, $la, $l3Effective);
        } catch (InvalidArgumentException) {
            $out['status'] = 'invalid_prediction';

            return $out;
        }

        $out['prediction'] = [
            'id'           => $prediction['id'] ?? null,
            'generated_at' => $prediction['generated_at'] ?? null,
            'lambda_home'  => $lh,
            'lambda_away'  => $la,
            'lambda3'      => $l3Effective,
        ];

        $latest = self::latestCompleteObservations($rows, $asOf);   // "market|line" => bookmaker_id => observation

        foreach (self::modelEntries($model) as [$market, $lineKey, $selection, $probability]) {
            $group = $latest[$market . '|' . ($lineKey ?? '-')] ?? [];
            $out['markets'][] = self::entry($market, $lineKey, $selection, $probability, $group, $asOf);
        }

        $out['quoted_markets'] = count(array_filter($out['markets'], fn ($e) => $e['status'] === 'ok'));
        $out['status'] = $out['quoted_markets'] > 0 ? 'ok' : 'no_quotes';

        return $out;
    }

    /** @return list<array{0:string,1:?string,2:string,3:float}> market, line key (null for btts), selection, model probability */
    private static function modelEntries(array $model): array
    {
        $entries = [];
        foreach ($model['total_goals'] as $line => $p) {
            $entries[] = ['total_goals', (string) $line, 'over', $p['over']];
            $entries[] = ['total_goals', (string) $line, 'under', $p['under']];
        }
        $entries[] = ['btts', null, 'yes', $model['btts']['yes']];
        $entries[] = ['btts', null, 'no', $model['btts']['no']];
        foreach (['home' => 'team_goals_home', 'away' => 'team_goals_away'] as $side => $market) {
            foreach ($model['team_goals'][$side] as $line => $p) {
                $entries[] = [$market, (string) $line, 'over', $p['over']];
                $entries[] = [$market, (string) $line, 'under', $p['under']];
            }
        }

        return $entries;
    }

    /**
     * Latest complete observation per (market, line, bookmaker) available at $asOf.
     *
     * @param  list<array<string,mixed>> $rows
     * @return array<string, array<int, array<string,mixed>>>
     */
    private static function latestCompleteObservations(array $rows, CarbonInterface $asOf): array
    {
        $byObservation = [];
        foreach ($rows as $r) {
            $byObservation[(string) $r['observation_id']][] = $r;
        }

        $latest = [];
        foreach ($byObservation as $obsRows) {
            $obs = self::validObservation($obsRows, $asOf);
            if ($obs === null) {
                continue;
            }
            $key = $obs['market'] . '|' . ($obs['line_key'] ?? '-');
            $cur = $latest[$key][$obs['bookmaker_id']] ?? null;
            if ($cur === null
                || $obs['fetched_at']->gt($cur['fetched_at'])
                || ($obs['fetched_at']->eq($cur['fetched_at']) && $obs['max_id'] > $cur['max_id'])) {
                $latest[$key][$obs['bookmaker_id']] = $obs;
            }
        }

        return $latest;
    }

    /**
     * An observation is usable only if all its rows share bookmaker/market/line/fetch time, carry exactly the
     * expected selections once each, valid odds, and satisfy the as-of / pre-match constraints.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private static function validObservation(array $rows, CarbonInterface $asOf): ?array
    {
        $first = $rows[0];
        $market = (string) $first['market'];
        $expected = self::SELECTIONS[$market] ?? null;
        if ($expected === null || count($rows) !== count($expected)) {
            return null;
        }

        $lineKey = $first['line'] === null ? null : number_format((float) $first['line'], 1, '.', '');
        if (($market === 'btts') !== ($lineKey === null)) {
            return null;
        }

        $odds = [];
        $maxId = 0;
        foreach ($rows as $r) {
            $sameLine = ($r['line'] === null ? null : number_format((float) $r['line'], 1, '.', '')) === $lineKey;
            if ($r['market'] !== $market || ! $sameLine
                || (int) $r['bookmaker_id'] !== (int) $first['bookmaker_id']
                || ! $r['fetched_at']->eq($first['fetched_at'])) {
                return null;
            }
            $selection = (string) $r['selection'];
            if (! in_array($selection, $expected, true) || isset($odds[$selection])) {
                return null;
            }
            if (! is_numeric($r['odd']) || ! is_finite((float) $r['odd']) || (float) $r['odd'] <= 1.0) {
                return null;
            }
            $odds[$selection] = (float) $r['odd'];
            $maxId = max($maxId, (int) ($r['id'] ?? 0));
        }

        $fetchedAt = $first['fetched_at'];
        if ($fetchedAt->gt($asOf) || ! $fetchedAt->lt($first['kickoff_at'])) {
            return null;   // future w.r.t. the evaluation time, or not certified pre-match
        }

        $raw = array_map(fn (float $o) => 1 / $o, $odds);
        $sum = array_sum($raw);

        return [
            'market'              => $market,
            'line_key'            => $lineKey,
            'bookmaker_id'        => (int) $first['bookmaker_id'],
            'bookmaker_name'      => (string) $first['bookmaker_name'],
            'odds'                => $odds,
            'fair'                => array_map(fn (float $p) => $p / $sum, $raw),
            'overround'           => $sum - 1,
            'fetched_at'          => $fetchedAt,
            'provider_updated_at' => $first['provider_updated_at'] ?? null,
            'max_id'              => $maxId,
        ];
    }

    /**
     * @param array<int, array<string,mixed>> $group bookmaker_id => latest complete observation for this market/line
     * @return array<string,mixed>
     */
    private static function entry(string $market, ?string $lineKey, string $selection, float $probability, array $group, CarbonInterface $asOf): array
    {
        $entry = [
            'market'                => $market,
            'line'                  => $lineKey === null ? null : (float) $lineKey,
            'selection'             => $selection,
            'status'                => 'no_quotes',
            'model_probability'     => $probability,
            'model_fair_odd'        => $probability > 0.0 ? 1 / $probability : null,
            'bookmaker_count'       => 0,
            'bookmakers'            => [],
            'consensus_probability' => null,
            'probability_edge'      => null,
            'best_odd'              => null,
            'best_bookmakers'       => [],
            'theoretical_ev'        => null,
            'latest_fetched_at'     => null,
            'oldest_fetched_at'     => null,
            'latest_age_minutes'    => null,
        ];

        if ($group === []) {
            return $entry;
        }

        $obs = array_values($group);
        usort($obs, fn ($a, $b) => $a['bookmaker_id'] <=> $b['bookmaker_id']);

        // two-way consensus: median of each selection's de-vigged probability, re-normalised
        $selections = self::SELECTIONS[$market];
        $medians = [];
        foreach ($selections as $s) {
            $medians[$s] = self::median(array_map(fn ($o) => $o['fair'][$s], $obs));
        }
        $consensus = $medians[$selection] / array_sum($medians);

        $bestOdd = max(array_map(fn ($o) => $o['odds'][$selection], $obs));
        $best = [];
        $latest = $oldest = null;
        foreach ($obs as $o) {
            $entry['bookmakers'][] = [
                'bookmaker_id'        => $o['bookmaker_id'],
                'name'                => $o['bookmaker_name'],
                'odd'                 => $o['odds'][$selection],
                'fair_probability'    => $o['fair'][$selection],
                'overround'           => $o['overround'],
                'fetched_at'          => $o['fetched_at'],
                'provider_updated_at' => $o['provider_updated_at'],
            ];
            if (abs($o['odds'][$selection] - $bestOdd) < 1e-9) {
                $best[] = ['bookmaker_id' => $o['bookmaker_id'], 'name' => $o['bookmaker_name'], 'fetched_at' => $o['fetched_at']];
            }
            $latest = $latest === null || $o['fetched_at']->gt($latest) ? $o['fetched_at'] : $latest;
            $oldest = $oldest === null || $o['fetched_at']->lt($oldest) ? $o['fetched_at'] : $oldest;
        }

        return array_merge($entry, [
            'status'                => 'ok',
            'bookmaker_count'       => count($obs),
            'consensus_probability' => $consensus,
            'probability_edge'      => $probability - $consensus,
            'best_odd'              => $bestOdd,
            'best_bookmakers'       => $best,
            'theoretical_ev'        => $probability * $bestOdd - 1,
            'latest_fetched_at'     => $latest,
            'oldest_fetched_at'     => $oldest,
            'latest_age_minutes'    => (int) round($latest->diffInSeconds($asOf, true) / 60),
        ]);
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
