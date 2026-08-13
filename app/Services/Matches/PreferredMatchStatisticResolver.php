<?php

namespace App\Services\Matches;

use App\Models\DataSource;
use App\Models\MatchStatistic;
use Illuminate\Support\Collection;

/**
 * Picks a single MatchStatistic per match_id when more than one source has
 * reported statistics for the same match, following the ordered slug list
 * in config('imports.statistics_source_priority') — first source in the
 * list that has a row for a given match wins.
 *
 * Reusable anywhere a single canonical statistics row per match is needed
 * (Competition Overview today; Match Page / Team Page later).
 */
class PreferredMatchStatisticResolver
{
    /**
     * @param  iterable<int>  $matchIds
     * @return Collection<int, MatchStatistic>  Keyed by match_id.
     */
    public static function forMatchIds(iterable $matchIds): Collection
    {
        $matchIds = collect($matchIds)->filter()->unique()->values();

        $priority = collect((array) config('imports.statistics_source_priority', []))->values();

        if ($matchIds->isEmpty() || $priority->isEmpty()) {
            return collect();
        }

        $sourceIdBySlug = DataSource::whereIn('slug', $priority)->pluck('id', 'slug');

        // Preserve config order, dropping slugs with no matching data_sources row.
        $orderedSourceIds = $priority
            ->map(static fn(string $slug) => $sourceIdBySlug[$slug] ?? null)
            ->filter()
            ->values();

        if ($orderedSourceIds->isEmpty()) {
            return collect();
        }

        $statsByMatch = MatchStatistic::whereIn('match_id', $matchIds)
            ->whereIn('data_source_id', $orderedSourceIds)
            ->get()
            ->groupBy('match_id');

        return $statsByMatch->map(static function (Collection $statsForMatch) use ($orderedSourceIds) {
            foreach ($orderedSourceIds as $sourceId) {
                $preferred = $statsForMatch->firstWhere('data_source_id', $sourceId);
                if ($preferred !== null) {
                    return $preferred;
                }
            }

            return null;
        })->filter();
    }
}
