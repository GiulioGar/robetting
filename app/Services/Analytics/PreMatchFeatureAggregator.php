<?php

namespace App\Services\Analytics;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Services\Matches\PreferredMatchStatisticResolver;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Aggregates all pre-match features for a single target match into one
 * deterministic, leakage-free snapshot.
 *
 * Orchestrates the existing Analytics calculator stack — never re-implements
 * formulas.  All data slicing enforces strictly kickoff_at < target; each
 * downstream calculator also applies its own independent guard.
 *
 * ── Output sections ───────────────────────────────────────────────────────────
 *   identity      — match / teams / season / competition identifiers
 *   core          — CORE V1 features; null = data not available for this match
 *                   (e.g. absence/continuity null for pre-2025/26 matches)
 *   experimental  — null-sentinel features; GBM decides relevance at training time
 *   metadata      — generated_at, cutoff, known gaps, per-block leakage audit
 *
 * ── xG gap ────────────────────────────────────────────────────────────────────
 *   No rolling xG calculator exists in App\Services\Analytics.  The
 *   experimental.xg block is always null.  A future TeamRollingXgCalculator
 *   would populate it without changing this interface.
 *
 * ── Anti-leakage guarantee ────────────────────────────────────────────────────
 *   All data-loading queries use WHERE kickoff_at < $match->kickoff_at.
 *   Calculators that accept a Collection use caller-pre-filtered input;
 *   calculators that take FootballMatch directly enforce their own inner guard.
 *
 * ── Query budget (approximate per call) ──────────────────────────────────────
 *   2  × previousMatches (home + away)                        →  2 queries
 *   2  × scheduleHistory (home + away)                        →  2 queries
 *   1  × matchStatistics (merged match-ID set)                →  1 query
 *   1  × leagueContextMatches                                 →  1 query
 *   1  × leagueContextStatistics                              →  1 query
 *   1  × h2hMatches                                           →  1 query
 *   1  × DataSource slug lookup (transfermarkt)               →  1 query
 *   1  × season->teams() for league_mean_elo                  →  1 query
 *   1  × shared Elo replay (OPT-1B: all windows + target)   →  1 query
 *   ~1 × TeamStrengthComparisonCalculator (structural only)  →  1 query
 *   ~2 × TeamOpponentQualityCalculator × 2 (structural only) →  2 queries
 *   ~2 × TeamAbsenceImpactCalculator × 2 teams               →  4 queries
 *   ~1 × TeamStarterContinuityCalculator × 2 teams           →  2 queries
 *   ~1 × TeamAgeProfileCalculator × 2 teams                  →  2 queries
 *   Total: ~24 queries.
 *
 * Top 3 optimisation targets (V2):
 *   1. [DONE — OPT-1B] Share the Elo replay across E6 (TeamStrengthComparisonCalculator)
 *      and E9 (TeamOpponentQualityCalculator): 3 replays → 1 shared context.
 *   2. Merge absence / continuity / age internal queries per team into a single
 *      shared lineup + player_absences load.
 *   3. [DONE — OPT-1B] Pass the already-computed eloContext from E9 back into E6
 *      via the shared pre-computed context in the aggregator.
 */
class PreMatchFeatureAggregator
{
    private const H2H_LIMIT = 10;

    /**
     * Compute the full pre-match feature snapshot for $match.
     *
     * @throws InvalidArgumentException when kickoff_at is null.
     * @return array{identity: array, core: array, experimental: array, metadata: array}
     */
    public static function aggregate(FootballMatch $match): array
    {
        if ($match->kickoff_at === null) {
            throw new InvalidArgumentException(
                "Cannot aggregate features for match #{$match->id}: kickoff_at is null."
            );
        }

        $match->loadMissing(['season', 'competition']);
        $generatedAt = now();
        $kickoff     = $match->kickoff_at;

        // ── 1. Data loading ───────────────────────────────────────────────────

        // Previous matches within the same competition+season, sorted ASC by kickoff.
        // Shared input for TAC (E8), opponent quality (E9), adjusted perf (E10), E12.
        $homePrev = self::loadPreviousMatches($match, (int) $match->home_team_id);
        $awayPrev = self::loadPreviousMatches($match, (int) $match->away_team_id);

        // Window slices used by TAC, E9, E10.
        $homeLast10    = $homePrev->slice(-10)->values();
        $homeLast5     = $homePrev->slice(-5)->values();
        $awayLast10    = $awayPrev->slice(-10)->values();
        $awayLast5     = $awayPrev->slice(-5)->values();
        $homeLast5Home = $homePrev->where('home_team_id', $match->home_team_id)->slice(-5)->values();
        $awayLast5Away = $awayPrev->where('away_team_id', $match->away_team_id)->slice(-5)->values();

        // Match statistics covering both teams' histories + the target match itself.
        $statMatchIds = collect([$match->id])
            ->merge($homePrev->pluck('id'))
            ->merge($awayPrev->pluck('id'))
            ->unique();
        $matchStats = PreferredMatchStatisticResolver::forMatchIds($statMatchIds);

        // Schedule history: cross-competition, cross-season (E4).
        $homeSchedHistory = self::loadScheduleHistory($match, (int) $match->home_team_id);
        $awaySchedHistory = self::loadScheduleHistory($match, (int) $match->away_team_id);

        // League context: all finished competition+season matches before cutoff (E11).
        $leagueMatches = self::loadLeagueContextMatches($match);
        $leagueStats   = PreferredMatchStatisticResolver::forMatchIds($leagueMatches->pluck('id'));

        // H2H: same competition, any season, both orderings — capped at H2H_LIMIT.
        $h2hMatches = self::loadH2HMatches($match);

        // Data source for structural / market-value lookups.
        $tmDsId = self::resolveTransfermarktDsId();

        // League team IDs for E9 league_mean_elo computation (required by E10).
        $leagueTeamIds = $match->season->teams()->pluck('teams.id');

        // OPT-1B: single Elo replay shared across E6, E9-home, and E9-away.
        // Union of all window matches + the target match, deduplicated by ID.
        // calculateRatingsBeforeMatchesWithLeagueMean performs 1 query / 1 replay
        // and captures pre-kickoff state for every entry via capture-before-apply.
        $allRelevantMatches = $homeLast10
            ->merge($homeLast5Home)
            ->merge($awayLast10)
            ->merge($awayLast5Away)
            ->push($match)
            ->unique('id');

        $sharedEloContext = TeamEloCalculator::calculateRatingsBeforeMatchesWithLeagueMean(
            $allRelevantMatches,
            $leagueTeamIds
        );

        // ── 2. Calculator calls ───────────────────────────────────────────────

        // E6 + E7: Pre-match Elo and structural rating via the combined calculator.
        $strength = TeamStrengthComparisonCalculator::calculateForMatch(
            $match,
            $sharedEloContext[$match->id] ?? null
        );

        // E8: Recent performance — TAC over the last-10 window.
        $homeTacL10 = TeamAnalyticsCalculator::calculate(
            $homeLast10, (int) $match->home_team_id, $matchStats
        );
        $awayTacL10 = TeamAnalyticsCalculator::calculate(
            $awayLast10, (int) $match->away_team_id, $matchStats
        );

        // E4: Schedule load — all definitive matches across competitions.
        $homeSchedule = TeamScheduleLoadCalculator::calculate($homeSchedHistory, $kickoff);
        $awaySchedule = TeamScheduleLoadCalculator::calculate($awaySchedHistory, $kickoff);

        // E5: Absence, starter continuity, age profile — each calculator queries
        //     internally with its own double guard (query-level + timestamp check).
        $homeAbsence    = TeamAbsenceImpactCalculator::calculateForMatch($match, (int) $match->home_team_id);
        $awayAbsence    = TeamAbsenceImpactCalculator::calculateForMatch($match, (int) $match->away_team_id);
        $homeContinuity = TeamStarterContinuityCalculator::calculateForMatch($match, (int) $match->home_team_id);
        $awayContinuity = TeamStarterContinuityCalculator::calculateForMatch($match, (int) $match->away_team_id);
        $homeAge        = TeamAgeProfileCalculator::calculateForMatch($match, (int) $match->home_team_id);
        $awayAge        = TeamAgeProfileCalculator::calculateForMatch($match, (int) $match->away_team_id);

        // E11: League context (home_win_rate, draw_rate, avg_goals, etc.).
        $leagueContext = CompetitionStatisticsCalculator::calculateLeagueContext($leagueMatches, $leagueStats);

        // E9: Opponent quality — passes sharedEloContext to skip internal replays.
        $homeOppQuality = TeamOpponentQualityCalculator::calculate(
            (int) $match->home_team_id,
            $homeLast5,
            $homeLast10,
            $homeLast5Home,
            $tmDsId,
            $match->season,
            $leagueTeamIds,
            $sharedEloContext
        );
        $awayOppQuality = TeamOpponentQualityCalculator::calculate(
            (int) $match->away_team_id,
            $awayLast5,
            $awayLast10,
            $awayLast5Away,
            $tmDsId,
            $match->season,
            $leagueTeamIds,
            $sharedEloContext
        );

        // E10: Adjusted performance — consumes the eloContext produced by E9.
        $homeEloCtx = $homeOppQuality['_elo_context'] ?? [];
        $awayEloCtx = $awayOppQuality['_elo_context'] ?? [];

        $homeAdjPerf = TeamOpponentAdjustedPerformanceCalculator::calculate(
            (int) $match->home_team_id,
            $homeLast5,
            $homeLast10,
            $homeLast5Home,
            $homeEloCtx,
            $matchStats
        );
        $awayAdjPerf = TeamOpponentAdjustedPerformanceCalculator::calculate(
            (int) $match->away_team_id,
            $awayLast5,
            $awayLast10,
            $awayLast5Away,
            $awayEloCtx,
            $matchStats
        );

        // H2H (experimental — weak signal in linear models per audit v2).
        $headToHead = HeadToHeadCalculator::calculate(
            $h2hMatches,
            (int) $match->home_team_id,
            (int) $match->away_team_id
        );

        // E12: Time-decayed performance — reuses homePrev/awayPrev and eloContext.
        $homeTimeDecay = TeamTimeDecayedPerformanceCalculator::calculate(
            (int) $match->home_team_id,
            $homePrev,
            $kickoff,
            $homeEloCtx,
            $matchStats
        );
        $awayTimeDecay = TeamTimeDecayedPerformanceCalculator::calculate(
            (int) $match->away_team_id,
            $awayPrev,
            $kickoff,
            $awayEloCtx,
            $matchStats
        );

        // ── 3. Assembly ───────────────────────────────────────────────────────

        return [
            'identity' => [
                'match_id'       => $match->id,
                'season_id'      => $match->season_id,
                'competition_id' => $match->competition_id,
                'home_team_id'   => (int) $match->home_team_id,
                'away_team_id'   => (int) $match->away_team_id,
                'kickoff_at'     => $kickoff->toIso8601String(),
            ],
            'core' => [
                'elo' => [
                    'home_pre_match_elo' => $strength['home_elo'],
                    'away_pre_match_elo' => $strength['away_elo'],
                    'elo_diff'           => $strength['elo_diff'],
                ],
                'recent' => [
                    'home' => self::recentBlock($homeTacL10),
                    'away' => self::recentBlock($awayTacL10),
                ],
                'structural' => [
                    'home_structural_rating' => $strength['home_structural']['structural_rating'] ?? null,
                    'home_market_value'      => $strength['home_structural']['market_value']      ?? null,
                    'away_structural_rating' => $strength['away_structural']['structural_rating'] ?? null,
                    'away_market_value'      => $strength['away_structural']['market_value']      ?? null,
                    'structural_rating_diff' => $strength['structural_rating_diff'],
                    'log_market_value_ratio' => $strength['log_market_value_ratio'],
                ],
                'schedule' => [
                    'home' => $homeSchedule,
                    'away' => $awaySchedule,
                ],
                'absence' => [
                    'home' => $homeAbsence,
                    'away' => $awayAbsence,
                ],
                'continuity' => [
                    'home' => $homeContinuity,
                    'away' => $awayContinuity,
                ],
                'age_profile' => [
                    'home' => $homeAge,
                    'away' => $awayAge,
                ],
                'league_context' => $leagueContext,
                'opponent_quality' => [
                    'home' => self::stripInternalKeys($homeOppQuality),
                    'away' => self::stripInternalKeys($awayOppQuality),
                ],
                'opponent_adjusted' => [
                    'home' => $homeAdjPerf,
                    'away' => $awayAdjPerf,
                ],
            ],
            'experimental' => [
                'xg'         => null, // GAP: no TeamRollingXgCalculator — see metadata.gaps
                'h2h'        => $headToHead,
                'time_decay' => [
                    'home' => $homeTimeDecay,
                    'away' => $awayTimeDecay,
                ],
            ],
            'metadata' => [
                'generated_at'    => $generatedAt->toIso8601String(),
                'cutoff'          => $kickoff->toIso8601String(),
                'cutoff_operator' => 'strictly_before',
                'gaps'            => ['experimental_xg_rolling_calculator_missing'],
                'leakage_audit'   => self::leakageAudit(),
            ],
        ];
    }

    /**
     * Flatten the hierarchical snapshot into a single-level associative array.
     *
     * Key convention: path segments joined with underscores, e.g.
     *   core.elo.home_pre_match_elo → core_elo_home_pre_match_elo
     *
     * Null values are preserved.  Sequential (non-associative) arrays and scalars
     * are kept as leaf values without further flattening.
     *
     * @param  array  $snapshot  Return value of aggregate().
     * @return array<string, mixed>
     */
    public static function flatten(array $snapshot): array
    {
        $flat = [];
        self::flattenRecursive($snapshot, '', $flat);
        return $flat;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * All finished competition+season matches involving $teamId, strictly before
     * $match->kickoff_at, ordered ASC — shared input for TAC, E9, E10, E12.
     */
    private static function loadPreviousMatches(FootballMatch $match, int $teamId): Collection
    {
        return FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->where('competition_id', $match->competition_id)
            ->where('season_id', $match->season_id)
            ->where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->where(function ($q) use ($teamId): void {
                $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->orderBy('kickoff_at')
            ->get();
    }

    /**
     * All definitive matches (any competition, any season) for $teamId before
     * $match->kickoff_at — used exclusively for TeamScheduleLoadCalculator (E4).
     */
    private static function loadScheduleHistory(FootballMatch $match, int $teamId): Collection
    {
        return FootballMatch::whereIn('status', ['finished', 'awarded', 'walkover'])
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->where(function ($q) use ($teamId): void {
                $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->get(['id', 'kickoff_at']);
    }

    /**
     * All finished competition+season matches (any teams) before $match->kickoff_at
     * — used by CompetitionStatisticsCalculator::calculateLeagueContext (E11).
     */
    private static function loadLeagueContextMatches(FootballMatch $match): Collection
    {
        return FootballMatch::where('competition_id', $match->competition_id)
            ->where('season_id', $match->season_id)
            ->where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->get([
                'id', 'competition_id', 'season_id', 'kickoff_at', 'status',
                'home_team_id', 'away_team_id', 'home_score_ft', 'away_score_ft',
            ]);
    }

    /**
     * Previous meetings between the two teams in the same competition (any season),
     * most recent first, capped at H2H_LIMIT.
     */
    private static function loadH2HMatches(FootballMatch $match): Collection
    {
        return FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name', 'season:id,name'])
            ->where('competition_id', $match->competition_id)
            ->where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->where(function ($q) use ($match): void {
                $q->where(function ($inner) use ($match): void {
                    $inner->where('home_team_id', $match->home_team_id)
                          ->where('away_team_id', $match->away_team_id);
                })->orWhere(function ($inner) use ($match): void {
                    $inner->where('home_team_id', $match->away_team_id)
                          ->where('away_team_id', $match->home_team_id);
                });
            })
            ->orderByDesc('kickoff_at')
            ->limit(self::H2H_LIMIT)
            ->get();
    }

    private static function resolveTransfermarktDsId(): ?int
    {
        $id = DataSource::where('slug', 'transfermarkt')->value('id');
        return $id !== null ? (int) $id : null;
    }

    /**
     * Extract the six rolling-average metrics (goals, shots, SoT for/against)
     * from a TeamAnalyticsCalculator result.
     */
    private static function recentBlock(array $tac): array
    {
        $summary   = $tac['summary']   ?? [];
        $technical = $tac['technical'] ?? [];

        return [
            'matches_considered'          => $summary['matches_played']                ?? 0,
            'avg_goals_for'               => $summary['avg_goals_for']                 ?? null,
            'avg_goals_against'           => $summary['avg_goals_against']             ?? null,
            'avg_shots_for'               => $technical['avg_shots_for']               ?? null,
            'avg_shots_against'           => $technical['avg_shots_against']           ?? null,
            'avg_shots_on_target_for'     => $technical['avg_shots_on_target_for']     ?? null,
            'avg_shots_on_target_against' => $technical['avg_shots_on_target_against'] ?? null,
        ];
    }

    /** Remove internal-use keys (prefixed with _) before exposing to callers. */
    private static function stripInternalKeys(array $data): array
    {
        return array_filter(
            $data,
            static fn (string $key): bool => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY
        );
    }

    private static function leakageAudit(): array
    {
        return [
            'elo'               => 'CLEAN — TeamStrengthComparisonCalculator replays all matches strictly < kickoff_at',
            'recent'            => 'CLEAN — homePrev/awayPrev enforce kickoff_at < target; TAC performs no DB query',
            'structural'        => 'CLEAN — snapshot_date <= referenceDate; null for matches predating first snapshot',
            'schedule'          => 'CLEAN — TeamScheduleLoadCalculator internal guard: kickoff_at < targetKickoff',
            'absence'           => 'CLEAN — double guard (query + timestamp) in TeamAbsenceImpactCalculator',
            'continuity'        => 'CLEAN — double guard (query + timestamp) in TeamStarterContinuityCalculator',
            'age_profile'       => 'CLEAN — double guard (query + timestamp) in TeamAgeProfileCalculator',
            'league_context'    => 'CLEAN — leagueMatches query enforces kickoff_at < target',
            'opponent_quality'  => 'CLEAN — Elo replay on caller-filtered windows; structural uses season-baseline semantics',
            'opponent_adjusted' => 'CLEAN — 0 DB queries; consumes pre-loaded eloContext + matchStats',
            'xg'                => 'N/A — calculator not yet implemented (see gaps)',
            'h2h'               => 'CLEAN — h2hMatches query enforces kickoff_at < target; spans seasons within competition',
            'time_decay'        => 'CLEAN — internal guard: kickoff_at < targetKickoff + max_horizon_days cap',
        ];
    }

    /**
     * Recursively flatten nested associative arrays with underscore-joined keys.
     * Sequential (integer-indexed 0..N) arrays and scalars/null are leaf values.
     *
     * @param  array<string, mixed>  $data
     * @param  string                $prefix
     * @param  array<string, mixed>  &$flat
     */
    private static function flattenRecursive(array $data, string $prefix, array &$flat): void
    {
        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix . '_' . $key;

            if (is_array($value) && !empty($value) && self::isAssociative($value)) {
                self::flattenRecursive($value, $fullKey, $flat);
            } else {
                $flat[$fullKey] = $value;
            }
        }
    }

    private static function isAssociative(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
