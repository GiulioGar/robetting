<?php

namespace App\Services\Analytics;

use App\Models\FootballMatch;
use App\Models\MatchPlayerStatistic;
use Carbon\Carbon;

/**
 * Computes per-player recent utilisation metrics for a single team relative to
 * a target match kickoff. Does its own DB fetch (two queries, no N+1); it is
 * NOT a pure Collection-in calculator like TeamScheduleLoadCalculator, because
 * the caller cannot meaningfully pre-build the required cross-table collection.
 *
 * Canonical call sites (controller / feature-engineering layer):
 *
 *   $homeLoad = PlayerRecentLoadCalculator::calculateForMatch($match, $match->home_team_id);
 *   $awayLoad = PlayerRecentLoadCalculator::calculateForMatch($match, $match->away_team_id);
 *
 * ── NULL-minutes policy ──────────────────────────────────────────────────────
 *
 * games_minutes NULL means "the data source did not report a minute count for
 * this appearance" — it is NOT treated as 0 played minutes.  Only non-null
 * values are summed.  When EVERY appearance in a window has null minutes,
 * the corresponding total is null (unknown) rather than 0.  This distinguishes:
 *   - "played but exact minutes unknown" (null)
 *   - "played exactly 0 minutes"        (0, e.g. unused listed sub)
 *
 * appearances_last_5_matches and starts_last_5_matches are always computable
 * (they count rows, not minutes), so they are always int, never null.
 *
 * ── "Last 5 team matches" definition ────────────────────────────────────────
 *
 * The 5 most recent definitive match_ids, strictly before target kickoff, for
 * which AT LEAST ONE player-stat row exists for this team.  This allows all
 * players to be compared against the same 5-match team calendar — a player
 * who was not in the squad for a given match simply has no row (appearances=0
 * for that slot).
 *
 * ── Data-source assumption ───────────────────────────────────────────────────
 *
 * Query 2 fetches ALL rows for the team without filtering by data_source_id.
 * In the current setup (api-football is the sole player-stats source) this is
 * correct.  If a second source is ever added, de-duplicate by (player_id,
 * match_id) before computing — otherwise minute counts inflate.
 *
 * ── No-leakage guarantee ─────────────────────────────────────────────────────
 *
 * The caller MUST pass only matches with kickoff_at < targetKickoff.
 * The calculator ALSO enforces this internally via timestamp comparison as a
 * second line of defence (same pattern as TeamScheduleLoadCalculator).
 */
class PlayerRecentLoadCalculator
{
    private const DEFINITIVE_STATUSES = ['finished', 'awarded', 'walkover'];

    /**
     * @return array<int, array{
     *     minutes_last_7_days:        int|null,
     *     minutes_last_14_days:       int|null,
     *     minutes_last_30_days:       int|null,
     *     minutes_last_5_matches:     int|null,
     *     starts_last_5_matches:      int,
     *     appearances_last_5_matches: int,
     * }>  Keyed by player_id (int). Empty when target kickoff is null or there
     *     are no previous definitive matches / no player-stat rows for the team.
     */
    public static function calculateForMatch(FootballMatch $targetMatch, int $teamId): array
    {
        if ($targetMatch->kickoff_at === null) {
            return [];
        }

        $targetKickoff = $targetMatch->kickoff_at;
        $targetTs      = $targetKickoff->getTimestamp();

        // ─────────────────────────────────────────────────────────────────────
        // Query 1: definitive matches for this team strictly before target
        // Ordered desc so the head of the collection is the most recent.
        // ─────────────────────────────────────────────────────────────────────
        $prevMatches = FootballMatch::whereIn('status', self::DEFINITIVE_STATUSES)
            ->where('kickoff_at', '<', $targetKickoff)
            ->where(function ($q) use ($teamId) {
                $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->orderByDesc('kickoff_at')
            ->get(['id', 'kickoff_at']);

        // Internal anti-leakage guard (second line of defence, timestamp-safe).
        $prevMatches = $prevMatches->filter(
            fn($m) => $m->kickoff_at->getTimestamp() < $targetTs
        );

        if ($prevMatches->isEmpty()) {
            return [];
        }

        // O(1) kickoff lookup: match_id → Carbon.
        $kickoffByMatchId = $prevMatches->pluck('kickoff_at', 'id');

        // ─────────────────────────────────────────────────────────────────────
        // Query 2: all player-stat rows for those matches, this team only.
        // No N+1: single IN query, not one-per-player.
        // ─────────────────────────────────────────────────────────────────────
        $allStats = MatchPlayerStatistic::whereIn('match_id', $prevMatches->pluck('id'))
            ->where('team_id', $teamId)
            ->get(['player_id', 'match_id', 'games_minutes', 'games_substitute']);

        if ($allStats->isEmpty()) {
            return [];
        }

        // "Last 5 team matches": 5 most recent match_ids that have ≥1 stat row
        // for this team, sorted desc by kickoff.
        $last5MatchIds = $allStats
            ->pluck('match_id')
            ->unique()
            ->sortByDesc(fn($mid) => $kickoffByMatchId->get((int) $mid)?->getTimestamp() ?? 0)
            ->take(5)
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        // Time-window left boundaries (target is the exclusive right boundary,
        // enforced by the query + internal guard above).
        $window7  = $targetKickoff->copy()->subDays(7);
        $window14 = $targetKickoff->copy()->subDays(14);
        $window30 = $targetKickoff->copy()->subDays(30);

        $result = [];

        foreach ($allStats->groupBy('player_id') as $rawPlayerId => $playerRows) {
            $mins7   = [];
            $mins14  = [];
            $mins30  = [];
            $mins5   = [];
            $starts5 = 0;
            $apps5   = 0;

            foreach ($playerRows as $row) {
                /** @var Carbon $ko */
                $ko   = $kickoffByMatchId->get((int) $row->match_id);
                $mid  = (int) $row->match_id;
                $mins = $row->games_minutes;  // int|null — see NULL-minutes policy above
                $isSub = (bool) $row->games_substitute; // false = starter

                if ($ko !== null) {
                    if ($ko->gte($window7))  { $mins7[]  = $mins; }
                    if ($ko->gte($window14)) { $mins14[] = $mins; }
                    if ($ko->gte($window30)) { $mins30[] = $mins; }
                }

                if (in_array($mid, $last5MatchIds, true)) {
                    $mins5[] = $mins;
                    $apps5++;
                    if (!$isSub) {
                        $starts5++;
                    }
                }
            }

            $result[(int) $rawPlayerId] = [
                'minutes_last_7_days'         => self::sumNullableMinutes($mins7),
                'minutes_last_14_days'         => self::sumNullableMinutes($mins14),
                'minutes_last_30_days'         => self::sumNullableMinutes($mins30),
                'minutes_last_5_matches'       => self::sumNullableMinutes($mins5),
                'starts_last_5_matches'        => $starts5,
                'appearances_last_5_matches'   => $apps5,
            ];
        }

        return $result;
    }

    /**
     * Sum non-null values only.  Returns null when the array is empty or
     * every element is null (meaning "no minute data available"), NOT 0.
     * Returns an int (possibly 0) when at least one non-null value is present.
     */
    private static function sumNullableMinutes(array $values): ?int
    {
        $nonNull = array_values(array_filter($values, fn($v) => $v !== null));
        if (empty($nonNull)) {
            return null;
        }
        return (int) array_sum($nonNull);
    }
}
