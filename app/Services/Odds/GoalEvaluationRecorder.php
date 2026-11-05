<?php

namespace App\Services\Odds;

use App\Models\FootballMatch;
use App\Models\GoalMarketEvaluation;
use App\Models\GoalOddsSnapshot;
use App\Models\Prediction;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Records, for upcoming matches, the GoalMarketComparator evaluation of the OFFICIAL Candidate47 prediction against the
 * bookmaker quotes observed so far (append-only goal_market_evaluations). No API, no recomputation of formulas.
 *
 * Sampling policy: each run takes one photograph at evaluation_at = now (UTC) for every scheduled match that has an
 * already-registered official prediction and certified pre-match quotes. A selection is stored only if the same
 * (prediction, selection, set of quote observations) was not stored before, so restarts without new quotes add nothing,
 * while a new quote observation produces a new photograph. Only strictly pre-kickoff; nothing is back-filled and
 * nothing is stored for selections without a usable bookmaker consensus (never invented).
 */
final class GoalEvaluationRecorder
{
    /** @return array{matches:int, created:int, duplicates:int} */
    public static function recordPending(?CarbonInterface $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy()->utc();
        $out = ['matches' => 0, 'created' => 0, 'duplicates' => 0];

        $withPrediction = Prediction::where('purpose', Prediction::PURPOSE_OFFICIAL)
            ->where('model_key', GoalMarketComparator::MODEL_KEY)
            ->where('generated_at', '<=', $now)
            ->select('match_id');
        $withQuotes = GoalOddsSnapshot::where('fetched_at', '<=', $now)->select('match_id');

        $matches = FootballMatch::where('status', 'scheduled')
            ->whereNotNull('kickoff_at')
            ->where('kickoff_at', '>', $now)
            ->whereIn('id', $withPrediction)
            ->whereIn('id', $withQuotes)
            ->orderBy('id')
            ->get(['id', 'kickoff_at']);

        foreach ($matches as $match) {
            $r = self::recordMatch((int) $match->id, $match->kickoff_at, $now);
            $out['matches']++;
            $out['created']    += $r['created'];
            $out['duplicates'] += $r['duplicates'];
        }

        return $out;
    }

    /** @return array{created:int, duplicates:int} */
    public static function recordMatch(int $matchId, CarbonInterface $kickoffAt, CarbonInterface $now): array
    {
        $out = ['created' => 0, 'duplicates' => 0];
        $now = $now->copy()->utc();

        if (! $now->lt($kickoffAt)) {
            return $out;   // pre-match only
        }

        $result = GoalMarketComparator::forMatch($matchId, $now);
        $predictionId = $result['prediction']['id'] ?? null;
        if ($result['status'] !== 'ok' || $predictionId === null) {
            return $out;
        }

        $prediction = Prediction::find($predictionId);
        if ($prediction === null || ! $now->lt($prediction->kickoff_at) || ! $prediction->generated_at->lte($now)) {
            return $out;
        }

        foreach ($result['markets'] as $e) {
            if ($e['status'] !== 'ok') {
                continue;
            }

            $best   = $e['best_bookmakers'][0];   // lowest bookmaker_id among the equal-best quotes (sorted)
            $obsIds = array_map(fn ($b) => (string) $b['observation_id'], $e['bookmakers']);
            sort($obsIds);

            $row = [
                'prediction_id'            => $prediction->id,
                'match_id'                 => $matchId,
                'model_key'                => $prediction->model_key,
                'model_version'            => $prediction->model_version,
                'kickoff_at'               => self::utc($kickoffAt),
                'market'                   => $e['market'],
                'line'                     => $e['line'],
                'line_key'                 => $e['line'] === null ? '-' : number_format((float) $e['line'], 1, '.', ''),
                'selection'                => $e['selection'],
                'model_probability'        => $e['model_probability'],
                'model_fair_odd'           => $e['model_fair_odd'],
                'consensus_probability'    => $e['consensus_probability'],
                'probability_edge'         => $e['probability_edge'],
                'theoretical_ev'           => $e['theoretical_ev'],
                'best_odd'                 => $e['best_odd'],
                'best_bookmaker_id'        => $best['bookmaker_id'],
                'best_bookmaker_name'      => $best['name'],
                'best_observation_id'      => $best['observation_id'],
                'bookmaker_count'          => $e['bookmaker_count'],
                'bookmakers_json'          => json_encode(array_map(fn ($b) => [
                    'bookmaker_id'     => $b['bookmaker_id'],
                    'name'             => $b['name'],
                    'odd'              => $b['odd'],
                    'fair_probability' => $b['fair_probability'],
                    'observation_id'   => $b['observation_id'],
                    'fetched_at'       => self::utc($b['fetched_at']),
                ], $e['bookmakers'])),
                'quote_state_hash'         => sha1(implode('|', $obsIds)),
                'prediction_generated_at'  => self::utc($prediction->generated_at),
                'quotes_latest_fetched_at' => self::utc($e['latest_fetched_at']),
                'quotes_oldest_fetched_at' => self::utc($e['oldest_fetched_at']),
                'evaluation_at'            => self::utc($now),
            ];

            // The unique key makes this idempotent (insertOrIgnore returns 0 on a duplicate).
            if (GoalMarketEvaluation::insertOrIgnore($row) > 0) {
                $out['created']++;
            } else {
                $out['duplicates']++;
            }
        }

        return $out;
    }

    private static function utc(CarbonInterface $t): string
    {
        return $t->copy()->utc()->format('Y-m-d H:i:s');
    }
}
