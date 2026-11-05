<?php

namespace App\Services\Odds;

use App\Models\FootballMatch;
use App\Models\GoalMarketEvaluation;
use Illuminate\Support\Collection;

/**
 * Read-only verification of the GOAL evaluation register against real results (no writes, no API, no thresholds,
 * no stakes: a fixed 1-unit theoretical return only).
 *
 * Result source: matches.home_score_ft / away_score_ft, which API-Football fills from `score.fulltime`, i.e. the
 * REGULATION 90-minute score (extra time and penalties live in the separate *_et / *_penalties columns and are never
 * read; live `current_*` scores are never read). A match is settleable only if status = 'finished', definitive_at is
 * set and both FT scores are present; awarded/walkover (administrative), abandoned, postponed, scheduled or live
 * matches are NOT settleable.
 *
 * Selection policy ("independent of later quote moves"): for every (match, market, line, selection) only the FIRST
 * registered evaluation (ORDER BY evaluation_at, id) enters the primary summary; later photographs stay available
 * through settle(..., all) for follow-up analyses.
 *
 * Sample caveat: selections of the same match are strongly dependent (complementary Over/Under pairs, nested lines,
 * BTTS vs team goals), so the number of INDEPENDENT units is the number of matches, never the number of selections.
 */
final class GoalEvaluationOutcomeService
{
    /**
     * Pure market rule on the regulation score. null = unsupported market/selection/line (never guessed).
     */
    public static function selectionWins(string $market, ?float $line, string $selection, int $homeGoals, int $awayGoals): ?bool
    {
        if ($market === 'btts') {
            return match ($selection) {
                'yes'   => $homeGoals > 0 && $awayGoals > 0,
                'no'    => ! ($homeGoals > 0 && $awayGoals > 0),
                default => null,
            };
        }

        $goals = match ($market) {
            'total_goals'     => $homeGoals + $awayGoals,
            'team_goals_home' => $homeGoals,
            'team_goals_away' => $awayGoals,
            default           => null,
        };
        if ($goals === null || $line === null || fmod($line * 2, 2.0) !== 1.0) {   // only x.5 lines (no push possible)
            return null;
        }

        return match ($selection) {
            'over'  => $goals > $line,
            'under' => $goals < $line,
            default => null,
        };
    }

    /** Regulation score of a settleable match, or null. @return array{0:int,1:int}|null */
    public static function regulationScore(?FootballMatch $match): ?array
    {
        if ($match === null || $match->status !== 'finished' || $match->definitive_at === null
            || $match->home_score_ft === null || $match->away_score_ft === null) {
            return null;
        }

        return [(int) $match->home_score_ft, (int) $match->away_score_ft];
    }

    /**
     * Every registered evaluation with its outcome.
     *
     * @return Collection<int, array<string,mixed>>  status: settled | not_settleable | unsupported
     */
    public static function settle(): Collection
    {
        $evaluations = GoalMarketEvaluation::orderBy('evaluation_at')->orderBy('id')->get();
        $matches = FootballMatch::whereIn('id', $evaluations->pluck('match_id')->unique())->get()->keyBy('id');

        return $evaluations->map(function (GoalMarketEvaluation $e) use ($matches) {
            $row = [
                'id'                    => (int) $e->id,
                'match_id'              => (int) $e->match_id,
                'market'                => $e->market,
                'line_key'              => $e->line_key,
                'selection'             => $e->selection,
                'evaluation_at'         => $e->evaluation_at,
                'model_probability'     => (float) $e->model_probability,
                'consensus_probability' => (float) $e->consensus_probability,
                'best_odd'              => (float) $e->best_odd,
                'theoretical_ev'        => (float) $e->theoretical_ev,
                'status'                => 'not_settleable',
                'won'                   => null,
                'return'                => null,
            ];

            $score = self::regulationScore($matches->get($e->match_id));
            if ($score === null) {
                return $row;
            }

            $won = self::selectionWins($e->market, $e->line, $e->selection, $score[0], $score[1]);
            if ($won === null) {
                $row['status'] = 'unsupported';

                return $row;
            }

            $row['status'] = 'settled';
            $row['won']    = $won;
            $row['return'] = $won ? $row['best_odd'] - 1 : -1.0;

            return $row;
        });
    }

    /**
     * First evaluation per (match, market, line, selection) by evaluation_at then id.
     *
     * @param  Collection<int, array<string,mixed>> $rows  already ordered (settle() order)
     * @return Collection<int, array<string,mixed>>
     */
    public static function firstPerSelection(Collection $rows): Collection
    {
        return $rows
            ->sortBy(fn ($r) => [$r['evaluation_at']->getTimestamp(), $r['id']])
            ->unique(fn ($r) => $r['match_id'] . '|' . $r['market'] . '|' . $r['line_key'] . '|' . $r['selection'])
            ->values();
    }

    /**
     * Summary on the first-evaluation selection.
     *
     * @return array<string,mixed>
     */
    public static function report(): array
    {
        $all   = self::settle();
        $first = self::firstPerSelection($all);
        $set   = $first->where('status', 'settled');

        return [
            'registered_evaluations'  => $all->count(),
            'first_evaluations'       => $first->count(),
            'matches_in_register'     => $all->pluck('match_id')->unique()->count(),
            'not_settleable'          => $first->where('status', 'not_settleable')->count(),
            'unsupported'             => $first->where('status', 'unsupported')->count(),
            'overall'                 => self::summarize($set),
            'by_market'               => $set->groupBy('market')->map(fn ($g) => self::summarize($g))->sortKeys()->all(),
            'by_ev_sign'              => [
                'ev_positive'     => self::summarize($set->filter(fn ($r) => $r['theoretical_ev'] > 0)),
                'ev_non_positive' => self::summarize($set->filter(fn ($r) => $r['theoretical_ev'] <= 0)),
            ],
            'caveats'                 => [
                'independent_units_are_matches' => $set->pluck('match_id')->unique()->count(),
                'note'                          => 'Selections of one match are dependent (complementary Over/Under, nested lines, BTTS/team goals); '
                    . 'the independent sample size is the number of matches. ROI/Brier on few matches are descriptive only; '
                    . 'EV-sign groups are descriptive, not a selection rule.',
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string,mixed>> $settled
     * @return array<string,mixed>
     */
    public static function summarize(Collection $settled): array
    {
        $n = $settled->count();
        $out = ['matches' => $settled->pluck('match_id')->unique()->count(), 'selections' => $n,
            'won' => 0, 'lost' => 0, 'profit' => 0.0, 'roi' => null, 'brier_model' => null, 'brier_consensus' => null,
            'mean_model_probability' => null, 'mean_consensus_probability' => null, 'hit_rate' => null];
        if ($n === 0) {
            return $out;
        }

        $won = $settled->where('won', true)->count();
        $profit = (float) $settled->sum('return');
        $brier = fn (string $key) => $settled->avg(fn ($r) => ($r[$key] - ($r['won'] ? 1.0 : 0.0)) ** 2);

        return array_merge($out, [
            'won'                         => $won,
            'lost'                        => $n - $won,
            'hit_rate'                    => $won / $n,
            'profit'                      => $profit,
            'roi'                         => $profit / $n,
            'brier_model'                 => (float) $brier('model_probability'),
            'brier_consensus'             => (float) $brier('consensus_probability'),
            'mean_model_probability'      => (float) $settled->avg('model_probability'),
            'mean_consensus_probability'  => (float) $settled->avg('consensus_probability'),
        ]);
    }
}
