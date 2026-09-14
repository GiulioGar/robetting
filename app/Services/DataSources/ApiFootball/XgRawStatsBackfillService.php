<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\MatchStatistic;

/**
 * Populates home/away_expected_goals and home/away_goals_prevented from
 * already-stored raw_stats JSON — no API calls.
 *
 * Designed to run once after the xG migration on the 97 2026/27 rows that
 * have raw_stats. Safe to re-run (idempotent when --force is not used).
 */
class XgRawStatsBackfillService
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  bool $force  When true, overwrite existing non-null xG values.
     *
     * @return array{scanned:int, updated:int, unchanged:int, missing_xg:int, missing_goals_prevented:int}
     */
    public function backfill(bool $force = false): array
    {
        $scanned             = 0;
        $updated             = 0;
        $unchanged           = 0;
        $missingXg           = 0;
        $missingGoalsPrevented = 0;

        MatchStatistic::whereNotNull('raw_stats')
            ->chunk(self::CHUNK_SIZE, function ($rows) use (
                $force,
                &$scanned, &$updated, &$unchanged, &$missingXg, &$missingGoalsPrevented
            ) {
                foreach ($rows as $stat) {
                    $scanned++;

                    // Skip row if xG already populated and not forcing overwrite.
                    if (!$force && $stat->home_expected_goals !== null) {
                        $unchanged++;
                        continue;
                    }

                    $raw = $stat->raw_stats; // cast to array by model
                    $homeRaw = $raw['home'] ?? [];
                    $awayRaw = $raw['away'] ?? [];

                    $homeXg              = $this->floatFromRaw($homeRaw['expected_goals'] ?? null);
                    $awayXg              = $this->floatFromRaw($awayRaw['expected_goals'] ?? null);
                    $homeGoalsPrevented  = $this->floatFromRaw($homeRaw['goals_prevented'] ?? null);
                    $awayGoalsPrevented  = $this->floatFromRaw($awayRaw['goals_prevented'] ?? null);

                    if ($homeXg === null && $awayXg === null) {
                        $missingXg++;
                    }

                    if ($homeGoalsPrevented === null && $awayGoalsPrevented === null) {
                        $missingGoalsPrevented++;
                    }

                    $stat->home_expected_goals  = $homeXg;
                    $stat->away_expected_goals  = $awayXg;
                    $stat->home_goals_prevented = $homeGoalsPrevented;
                    $stat->away_goals_prevented = $awayGoalsPrevented;

                    // Only persist if at least one xG value was found or forcing.
                    if ($homeXg !== null || $awayXg !== null || $homeGoalsPrevented !== null || $awayGoalsPrevented !== null || $force) {
                        $stat->timestamps = false; // don't bump updated_at for a data backfill
                        $stat->save();
                        $updated++;
                    } else {
                        $unchanged++;
                    }
                }
            });

        return [
            'scanned'               => $scanned,
            'updated'               => $updated,
            'unchanged'             => $unchanged,
            'missing_xg'            => $missingXg,
            'missing_goals_prevented' => $missingGoalsPrevented,
        ];
    }

    /**
     * Parse a raw value from raw_stats JSON to float.
     * Handles string "1.08", float 1.43, negative "-1.34", null, "".
     */
    private function floatFromRaw(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $normalized = str_replace(',', '.', trim($value));
            if ($normalized === '' || !is_numeric($normalized)) {
                return null;
            }
            return (float) $normalized;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        return null;
    }
}
