<?php

namespace App\Services\DataSources\ApiFootball;

/**
 * Pure parser/normaliser for the GOAL bets of an API-Football `/odds` bookmaker block.
 *
 * Accepted (and nothing else):
 *   - total_goals     (bet 5)  : Over/Under, lines 0.5 .. 4.5
 *   - btts            (bet 8)  : Yes/No
 *   - team_goals_home (bet 16) / team_goals_away (bet 17): Over/Under, lines 0.5 .. 2.5
 *
 * Lines must end in .5 and sit in the range above: Asian lines (.25/.75) and whole lines
 * (push/refund) are discarded, as is any line whose selections are incomplete, duplicated or whose
 * odd is not numeric / not > 1.0 (the pair is dropped as a whole — never a single side).
 * Half-time / second-half bets are never accepted: only the four bet ids in $marketByBet are read.
 */
final class GoalOddsParser
{
    public const TOTAL_LINES = [0.5, 1.5, 2.5, 3.5, 4.5];
    public const TEAM_LINES  = [0.5, 1.5, 2.5];

    /**
     * @param  array<int, string>              $marketByBet  bet_id => market key
     * @param  array<int, array<string,mixed>> $values       provider "values" of one bet
     * @return array{observations: list<array{market:string, line:?float, selections: array<string, array{odd:float, label:string}>}>, discarded:int}
     */
    public static function parse(int $betId, array $marketByBet, array $values): array
    {
        $market = $marketByBet[$betId] ?? null;
        if ($market === null) {
            return ['observations' => [], 'discarded' => 0];
        }

        return $market === 'btts' ? self::parseBtts($values) : self::parseOverUnder($market, $values);
    }

    /** @return array{observations: list<array<string,mixed>>, discarded:int} */
    private static function parseOverUnder(string $market, array $values): array
    {
        $allowed = $market === 'total_goals' ? self::TOTAL_LINES : self::TEAM_LINES;

        $byLine = [];   // "2.5" => [selection => [odd,label][]]
        $discarded = 0;
        foreach ($values as $v) {
            $label = trim((string) ($v['value'] ?? ''));
            if (! preg_match('/^(over|under)\s+(\d+(?:\.\d+)?)$/i', $label, $m)) {
                $discarded++;
                continue;
            }
            $line = (float) $m[2];
            if (! in_array($line, $allowed, true)) {
                $discarded++;   // asian (.25/.75), whole (push) or out-of-range lines
                continue;
            }
            $byLine[number_format($line, 1, '.', '')][strtolower($m[1])][] = ['odd' => $v['odd'] ?? null, 'label' => $label];
        }

        $observations = [];
        foreach ($byLine as $lineKey => $sides) {
            $over  = self::single($sides['over'] ?? []);
            $under = self::single($sides['under'] ?? []);
            if ($over === null || $under === null) {
                $discarded++;
                continue;
            }
            $observations[] = ['market' => $market, 'line' => (float) $lineKey, 'selections' => ['over' => $over, 'under' => $under]];
        }

        usort($observations, fn ($a, $b) => $a['line'] <=> $b['line']);

        return ['observations' => $observations, 'discarded' => $discarded];
    }

    /** @return array{observations: list<array<string,mixed>>, discarded:int} */
    private static function parseBtts(array $values): array
    {
        $sides = [];
        $discarded = 0;
        foreach ($values as $v) {
            $label = trim((string) ($v['value'] ?? ''));
            $key   = strtolower($label);
            if (! in_array($key, ['yes', 'no'], true)) {
                $discarded++;
                continue;
            }
            $sides[$key][] = ['odd' => $v['odd'] ?? null, 'label' => $label];
        }

        $yes = self::single($sides['yes'] ?? []);
        $no  = self::single($sides['no'] ?? []);
        if ($yes === null || $no === null) {
            return ['observations' => [], 'discarded' => $discarded + 1];
        }

        return [
            'observations' => [['market' => 'btts', 'line' => null, 'selections' => ['yes' => $yes, 'no' => $no]]],
            'discarded'    => $discarded,
        ];
    }

    /**
     * Exactly one entry with a valid decimal odd (> 1.0), else null (missing, duplicated/ambiguous or invalid).
     *
     * @param  list<array{odd:mixed,label:string}> $entries
     * @return array{odd:float,label:string}|null
     */
    private static function single(array $entries): ?array
    {
        if (count($entries) !== 1) {
            return null;
        }
        $odd = $entries[0]['odd'];
        if (! is_numeric($odd) || (float) $odd <= 1.0 || ! is_finite((float) $odd)) {
            return null;
        }

        return ['odd' => (float) $odd, 'label' => $entries[0]['label']];
    }
}
