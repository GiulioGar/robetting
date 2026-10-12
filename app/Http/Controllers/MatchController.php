<?php

namespace App\Http\Controllers;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Services\Analytics\HeadToHeadCalculator;
use App\Services\Analytics\PlayerRecentLoadCalculator;
use App\Services\Analytics\TeamAgeProfileCalculator;
use App\Services\Analytics\TeamAnalyticsCalculator;
use App\Services\Analytics\TeamScheduleLoadCalculator;
use App\Services\Analytics\TeamAbsenceImpactCalculator;
use App\Services\Analytics\TeamStarterContinuityCalculator;
use App\Services\Analytics\CompetitionStatisticsCalculator;
use App\Services\Analytics\TeamOpponentAdjustedPerformanceCalculator;
use App\Services\Analytics\TeamOpponentQualityCalculator;
use App\Services\Analytics\TeamStrengthComparisonCalculator;
use App\Services\Analytics\TeamTimeDecayedPerformanceCalculator;
use App\Models\Prediction;
use App\Services\Matches\PreferredMatchStatisticResolver;
use App\Services\Prediction\PublicMatchPredictionService;
use App\Services\Prediction\PublicPredictionRefreshService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Serves scheduled, finished, and any other-status match on the same route,
 * but — Step 1B.2 — NOT with the same computation. `show()` classifies the
 * match into one of three view modes and only ever runs the heavy pre-match
 * analytics pipeline (13 calculators + their 2 historical-scan queries) for
 * 'pre_match'. 'finished' and 'other' get exclusively the real post-match
 * data (result/statistics/events/lineups) — no pre-match query/calculator is
 * executed for them, not merely hidden in the view. See $matchViewMode.
 */
class MatchController extends Controller
{
    private const H2H_LIMIT = 5;

    /**
     * Step 1B.2 — three internal view modes, deliberately NOT a general
     * enum/abstraction (per instructions, kept minimal for this step):
     *   - 'pre_match': status = scheduled. Unchanged behaviour (Step 1B.1
     *     baseline) — full pre-match analytics pipeline.
     *   - 'finished': status = finished. Real post-match content only.
     *   - 'other': anything else (today: awarded, abandoned; anticipated but
     *     never yet observed: live/postponed/suspended/cancelled/walkover).
     *     Safe fallback — same reduced computation as 'finished', no
     *     per-status special-casing invented here.
     */
    private function classifyViewMode(string $status): string
    {
        return match ($status) {
            'scheduled' => 'pre_match',
            'finished'  => 'finished',
            default     => 'other',
        };
    }
    private const DISPLAY_TIMEZONE = 'Europe/Rome';

    /**
     * Day-by-day match index (Struttura portale, Step 1A). DB-only, no API
     * calls. "Day" boundaries are computed in Europe/Rome (same display
     * timezone used by matches.show) and converted to UTC for the query,
     * since kickoff_at is stored in UTC. No lower/upper bound on navigation —
     * ?date= accepts any Y-m-d, invalid/missing falls back to today.
     */
    public function index(Request $request): View
    {
        $date = $this->resolveRequestedDate($request->query('date'));

        // Step 1D — half-open interval [start of day, start of NEXT day),
        // not inclusive endOfDay(): equivalent for any normal datetime value,
        // but immune to any theoretical microsecond-boundary edge case and
        // the more conventional pattern for a day-range query. Both bounds
        // are computed from the same Europe/Rome-anchored $date, converted
        // to UTC only for the comparison, exactly as before — no change in
        // which day a match belongs to, forward or backward.
        $dayStartUtc = $date->copy()->utc();
        $nextDayStartUtc = $date->copy()->addDay()->utc();

        $matches = FootballMatch::with(['competition:id,name', 'homeTeam:id,name', 'awayTeam:id,name'])
            ->where('kickoff_at', '>=', $dayStartUtc)
            ->where('kickoff_at', '<', $nextDayStartUtc)
            ->orderBy('kickoff_at')
            ->get();

        // Step 1D — public_latest is only ever generated for scheduled
        // fixtures (PublicPredictionRefreshService); skipping the lookup for
        // every other status avoids a pointless IN(...) over finished/other
        // match ids on a day with a lot of past matches, and keeps the blade
        // from ever needing to special-case a stale row left over from
        // before a match was played.
        $scheduledMatchIds = $matches->where('status', 'scheduled')->pluck('id');
        $predictions = $this->publicPredictions($scheduledMatchIds);

        $matchesByCompetition = $matches->groupBy(fn (FootballMatch $m) => $m->competition->name);

        $today = Carbon::now(self::DISPLAY_TIMEZONE)->startOfDay();

        return view('matches.index', [
            'date'       => $date,
            'matchesByCompetition' => $matchesByCompetition,
            'predictions' => $predictions,
            'prevDate'   => $date->copy()->subDay()->format('Y-m-d'),
            'nextDate'   => $date->copy()->addDay()->format('Y-m-d'),
            'todayDate'  => $today->format('Y-m-d'),
            'isToday'    => $date->isSameDay($today),
        ]);
    }

    private function resolveRequestedDate(?string $dateParam): Carbon
    {
        if ($dateParam !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
            try {
                $parsed = Carbon::createFromFormat('!Y-m-d', $dateParam, self::DISPLAY_TIMEZONE);
                if ($parsed !== false && $parsed->format('Y-m-d') === $dateParam) {
                    return $parsed->startOfDay();
                }
            } catch (\Exception) {
                // falls through to today below
            }
        }

        return Carbon::now(self::DISPLAY_TIMEZONE)->startOfDay();
    }

    /**
     * Step 1A.5 — PUBLIC_LATEST 1X2 preview for the day's matches, read ONLY
     * from already-persisted `predictions` rows (purpose=public_latest,
     * model_key=candidate47_structural_log) via a single batch query. NO
     * live pipeline call, NO FULL59 fallback, NO per-match loop: a match with
     * no persisted row simply gets no entry in the returned map (the view
     * already renders that as "no percentages shown", same as before).
     *
     * The application-level invariant is "at most one current row per
     * match_id+model_key", but this query stays robust even if that were
     * ever violated: ordering ascending by generated_at before keyBy() means
     * the LAST (freshest) row for a given match_id is the one that survives
     * the dedup, never an older duplicate.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $matchIds
     * @return \Illuminate\Support\Collection<int, Prediction>  keyed by match_id
     */
    private function publicPredictions(Collection $matchIds): Collection
    {
        if ($matchIds->isEmpty()) {
            return collect();
        }

        return Prediction::whereIn('match_id', $matchIds)
            ->where('purpose', Prediction::PURPOSE_PUBLIC_LATEST)
            ->where('model_key', PublicPredictionRefreshService::MODEL_KEY)
            ->orderBy('generated_at')
            ->get()
            ->keyBy('match_id');
    }

    public function show(FootballMatch $match, PublicMatchPredictionService $publicPredictionService): View
    {
        $match->load(['competition.country', 'season', 'homeTeam', 'awayTeam']);

        $matchViewMode = $this->classifyViewMode($match->status);

        $lineupDsSlug = config('imports.lineups_source_slug', 'api-football');
        $lineupDs     = DataSource::where('slug', $lineupDsSlug)->first();
        $lineups      = $lineupDs
            ? $match->lineups()
                ->with('players')
                ->where('data_source_id', $lineupDs->id)
                ->get()
                ->keyBy('team_id')
            : collect();

        $matchEvents = MatchEvent::where('match_id', $match->id)
            ->orderBy('minute')
            ->orderBy('id')
            ->get();

        $data = [
            'match'         => $match,
            'matchViewMode' => $matchViewMode,
            'matchEvents'   => $matchEvents,
            'lineups'       => $lineups,
        ];

        if ($matchViewMode === 'pre_match') {
            // Real match-level statistics are still shown for a pre-match
            // fixture if it somehow already has any (defensive — normally
            // empty before kickoff), so this stays outside the mode branch
            // in spirit but is computed together with the rest below since
            // it needs $homePrevious/$awayPrevious either way.
            $data = array_merge($data, $this->buildPreMatchData($match, $publicPredictionService));
        } else {
            // 'finished' / 'other' — Step 1B.2: NOT merely hidden in the
            // view, genuinely never computed. No previousMatches()/
            // allCompetitionPreviousMatches() scan, none of the 13 pre-match
            // calculators, no prediction pipeline call.
            $matchStatistics = PreferredMatchStatisticResolver::forMatchIds(collect([$match->id]));
            $data['matchStatistic']   = $matchStatistics->get($match->id);
            $data['publicPrediction'] = null;
        }

        return view('matches.show', $data);
    }

    /**
     * Step 1B.2 — the full pre-match analytics pipeline, unchanged from the
     * pre-Step-1B.2 behaviour (Step 1B.1 baseline): same queries, same 13
     * calculators, same public prediction call. Extracted verbatim into its
     * own method only so show() can skip it entirely for 'finished'/'other'
     * — no formula, calculator, or cutoff logic was touched.
     *
     * @return array<string, mixed>
     */
    private function buildPreMatchData(FootballMatch $match, PublicMatchPredictionService $publicPredictionService): array
    {
        $homePrevious = $this->previousMatches($match, $match->home_team_id);
        $awayPrevious = $this->previousMatches($match, $match->away_team_id);
        $h2hMatches   = $this->headToHead($match);

        $matchStatistics = PreferredMatchStatisticResolver::forMatchIds(
            collect([$match->id])
                ->merge($homePrevious->pluck('id'))
                ->merge($awayPrevious->pluck('id'))
        );

        $matchStatistic = $matchStatistics->get($match->id);

        $homeHomeOnly = $homePrevious->where('home_team_id', $match->home_team_id)->values();
        $awayAwayOnly = $awayPrevious->where('away_team_id', $match->away_team_id)->values();

        // Extract window collections as variables so they can be reused by both
        // TeamAnalyticsCalculator (E8) and TeamOpponentQualityCalculator (E9).
        $homeLast5     = $this->lastN($homePrevious, 5);
        $homeLast10    = $this->lastN($homePrevious, 10);
        $awayLast5     = $this->lastN($awayPrevious, 5);
        $awayLast10    = $this->lastN($awayPrevious, 10);
        $homeLast5Home = $this->lastN($homeHomeOnly, 5);
        $awayLast5Away = $this->lastN($awayAwayOnly, 5);

        $homeSeasonAnalytics     = TeamAnalyticsCalculator::calculate($homePrevious, $match->home_team_id, $matchStatistics);
        $homeLast5Analytics      = TeamAnalyticsCalculator::calculate($homeLast5, $match->home_team_id, $matchStatistics);
        $homeLast10Analytics     = TeamAnalyticsCalculator::calculate($homeLast10, $match->home_team_id, $matchStatistics);
        $homeHomeAnalytics       = TeamAnalyticsCalculator::calculate($homeHomeOnly, $match->home_team_id, $matchStatistics);
        $homeRecentHomeAnalytics = TeamAnalyticsCalculator::calculate($homeLast5Home, $match->home_team_id, $matchStatistics);

        $awaySeasonAnalytics     = TeamAnalyticsCalculator::calculate($awayPrevious, $match->away_team_id, $matchStatistics);
        $awayLast5Analytics      = TeamAnalyticsCalculator::calculate($awayLast5, $match->away_team_id, $matchStatistics);
        $awayLast10Analytics     = TeamAnalyticsCalculator::calculate($awayLast10, $match->away_team_id, $matchStatistics);
        $awayAwayAnalytics       = TeamAnalyticsCalculator::calculate($awayAwayOnly, $match->away_team_id, $matchStatistics);
        $awayRecentAwayAnalytics = TeamAnalyticsCalculator::calculate($awayLast5Away, $match->away_team_id, $matchStatistics);

        $headToHead = HeadToHeadCalculator::calculate($h2hMatches, $match->home_team_id, $match->away_team_id);

        $homeScheduleLoad = TeamScheduleLoadCalculator::calculate(
            $this->scheduleHistory($match, $match->home_team_id),
            $match->kickoff_at ?? now(),
        );
        $awayScheduleLoad = TeamScheduleLoadCalculator::calculate(
            $this->scheduleHistory($match, $match->away_team_id),
            $match->kickoff_at ?? now(),
        );

        $homePlayerLoad = PlayerRecentLoadCalculator::calculateForMatch($match, $match->home_team_id);
        $awayPlayerLoad = PlayerRecentLoadCalculator::calculateForMatch($match, $match->away_team_id);

        $allPlayerIds = array_unique(array_merge(array_keys($homePlayerLoad), array_keys($awayPlayerLoad)));
        $playerNames  = $allPlayerIds
            ? Player::whereIn('id', $allPlayerIds)->get(['id', 'name'])->keyBy('id')
            : collect();

        $homeTopPlayers = $this->buildTopPlayers($homePlayerLoad, $playerNames, 8);
        $awayTopPlayers = $this->buildTopPlayers($awayPlayerLoad, $playerNames, 8);

        $homeAgeProfile = TeamAgeProfileCalculator::calculateForMatch($match, $match->home_team_id);
        $awayAgeProfile = TeamAgeProfileCalculator::calculateForMatch($match, $match->away_team_id);

        $homeStarterContinuity = TeamStarterContinuityCalculator::calculateForMatch($match, $match->home_team_id);
        $awayStarterContinuity = TeamStarterContinuityCalculator::calculateForMatch($match, $match->away_team_id);

        $homeAbsenceImpact = TeamAbsenceImpactCalculator::calculateForMatch($match, $match->home_team_id);
        $awayAbsenceImpact = TeamAbsenceImpactCalculator::calculateForMatch($match, $match->away_team_id);

        $strengthComparison = TeamStrengthComparisonCalculator::calculateForMatch($match);

        // Preserve the eloData shape expected by the E6 blade (no view changes needed there).
        $eloData = [
            'home_elo'       => $strengthComparison['home_elo'],
            'away_elo'       => $strengthComparison['away_elo'],
            'elo_difference' => $strengthComparison['elo_diff'],
        ];

        // E9 — Opponent Quality: Elo (pre-match) + Structural (per-match as-of).
        // E10 — Opponent-Adjusted Performance: uses the Elo context from E9.
        $rawTransfermarktId = DataSource::where('slug', 'transfermarkt')->value('id');
        $transfermarktDsId  = $rawTransfermarktId !== null ? (int) $rawTransfermarktId : null;

        // League team IDs for this season — used by E9 to compute per-match
        // league_mean_elo (required by E10).  Loaded once, shared by both teams.
        $leagueTeamIds = $match->season->teams()->pluck('teams.id');

        $homeOpponentQuality = TeamOpponentQualityCalculator::calculate(
            (int) $match->home_team_id,
            $homeLast5,
            $homeLast10,
            $homeLast5Home,
            $transfermarktDsId,
            $match->season,
            $leagueTeamIds
        );
        $awayOpponentQuality = TeamOpponentQualityCalculator::calculate(
            (int) $match->away_team_id,
            $awayLast5,
            $awayLast10,
            $awayLast5Away,
            $transfermarktDsId,
            $match->season,
            $leagueTeamIds
        );

        // E11 — League Context: all finished competition matches before target kickoff.
        $allCompetitionPrevious   = $this->allCompetitionPreviousMatches($match);
        $allCompetitionStatistics = PreferredMatchStatisticResolver::forMatchIds(
            $allCompetitionPrevious->pluck('id')
        );
        $leagueContext = CompetitionStatisticsCalculator::calculateLeagueContext(
            $allCompetitionPrevious,
            $allCompetitionStatistics
        );

        // E10 — extract Elo context produced by E9 and compute adjusted performance.
        $homeEloContext = $homeOpponentQuality['_elo_context'] ?? [];
        $awayEloContext = $awayOpponentQuality['_elo_context'] ?? [];

        $homeAdjustedPerformance = TeamOpponentAdjustedPerformanceCalculator::calculate(
            (int) $match->home_team_id,
            $homeLast5,
            $homeLast10,
            $homeLast5Home,
            $homeEloContext,
            $matchStatistics
        );
        $awayAdjustedPerformance = TeamOpponentAdjustedPerformanceCalculator::calculate(
            (int) $match->away_team_id,
            $awayLast5,
            $awayLast10,
            $awayLast5Away,
            $awayEloContext,
            $matchStatistics
        );

        // E12 — Recent Performance with Time Decay.
        // Reuses $homePrevious / $awayPrevious (no new queries) and the Elo context
        // already produced by E9.  Window selection (max 10, ≤ 90 days) is applied
        // inside the calculator — no pre-slicing needed here.
        $homeTimeDecay = TeamTimeDecayedPerformanceCalculator::calculate(
            (int) $match->home_team_id,
            $homePrevious,
            $match->kickoff_at ?? now(),
            $homeEloContext,
            $matchStatistics
        );
        $awayTimeDecay = TeamTimeDecayedPerformanceCalculator::calculate(
            (int) $match->away_team_id,
            $awayPrevious,
            $match->kickoff_at ?? now(),
            $awayEloContext,
            $matchStatistics
        );

        // P17B — public pre-match 1X2 (Candidate V2, FULL59 technical fallback).
        $publicPrediction = $publicPredictionService->predict($match);

        return [
            'matchStatistic'      => $matchStatistic,
            'homeSeasonAnalytics' => $homeSeasonAnalytics,
            'homeLast5Analytics'  => $homeLast5Analytics,
            'homeLast10Analytics' => $homeLast10Analytics,
            'homeHomeAnalytics'   => $homeHomeAnalytics,
            'awaySeasonAnalytics' => $awaySeasonAnalytics,
            'awayLast5Analytics'  => $awayLast5Analytics,
            'awayLast10Analytics' => $awayLast10Analytics,
            'awayAwayAnalytics'   => $awayAwayAnalytics,
            'headToHead'          => $headToHead,
            'h2hMatches'          => $h2hMatches,
            'homeScheduleLoad'    => $homeScheduleLoad,
            'awayScheduleLoad'    => $awayScheduleLoad,
            'homeTopPlayers'      => $homeTopPlayers,
            'awayTopPlayers'      => $awayTopPlayers,
            'homeAgeProfile'           => $homeAgeProfile,
            'awayAgeProfile'           => $awayAgeProfile,
            'homeStarterContinuity'    => $homeStarterContinuity,
            'awayStarterContinuity'    => $awayStarterContinuity,
            'homeAbsenceImpact'        => $homeAbsenceImpact,
            'awayAbsenceImpact'        => $awayAbsenceImpact,
            'homeRecentHomeAnalytics'  => $homeRecentHomeAnalytics,
            'awayRecentAwayAnalytics'  => $awayRecentAwayAnalytics,
            'eloData'                  => $eloData,
            'strengthComparison'       => $strengthComparison,
            'homeOpponentQuality'         => $homeOpponentQuality,
            'awayOpponentQuality'         => $awayOpponentQuality,
            'homeAdjustedPerformance'     => $homeAdjustedPerformance,
            'awayAdjustedPerformance'     => $awayAdjustedPerformance,
            'leagueContext'               => $leagueContext,
            'homeTimeDecay'               => $homeTimeDecay,
            'awayTimeDecay'               => $awayTimeDecay,
            'publicPrediction'            => $publicPrediction,
        ];
    }

    /**
     * All finished competition+season matches strictly before target kickoff,
     * regardless of which teams played — used for E11 league-level statistics.
     * Only the columns needed by CompetitionStatisticsCalculator::calculateLeagueContext()
     * are selected to keep the result set slim.
     */
    private function allCompetitionPreviousMatches(FootballMatch $match): Collection
    {
        if ($match->kickoff_at === null) {
            return collect();
        }

        return FootballMatch::where('competition_id', $match->competition_id)
            ->where('season_id', $match->season_id)
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->get(['id', 'competition_id', 'season_id', 'kickoff_at', 'status',
                   'home_team_id', 'away_team_id', 'home_score_ft', 'away_score_ft']);
    }

    /**
     * Every finished match of the same competition/season for $teamId strictly
     * before this match's kickoff — the as-of-kickoff cutoff that keeps every
     * pre-match analytics block free of data leakage. Never includes the
     * match itself (kickoff_at is never "<" its own value) or any later match.
     * Applies identically whether $match itself is finished or still scheduled.
     */
    private function previousMatches(FootballMatch $match, int $teamId): Collection
    {
        return FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->where('competition_id', $match->competition_id)
            ->where('season_id', $match->season_id)
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where(function ($query) use ($teamId) {
                $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->orderBy('kickoff_at')
            ->get();
    }

    /**
     * Last N matches chronologically. $matches is already ordered ascending
     * by kickoff_at (see previousMatches()), so this is just a tail slice.
     */
    private function lastN(Collection $matches, int $n): Collection
    {
        return $matches->slice(-$n)->values();
    }

    /**
     * Sort enriched player-load rows by minutes_last_30_days DESC (null last),
     * then by player_id ASC as a stable tiebreaker. Returns the top $limit rows.
     */
    private function buildTopPlayers(array $load, Collection $playerNames, int $limit): array
    {
        $enriched = [];
        foreach ($load as $playerId => $metrics) {
            $enriched[] = array_merge(
                ['player_id' => $playerId, 'name' => $playerNames->get($playerId)?->name ?? "Player #{$playerId}"],
                $metrics,
            );
        }

        usort($enriched, function (array $a, array $b): int {
            $diff = ($b['minutes_last_30_days'] ?? -1) <=> ($a['minutes_last_30_days'] ?? -1);
            return $diff !== 0 ? $diff : $a['player_id'] <=> $b['player_id'];
        });

        return array_slice($enriched, 0, $limit);
    }

    /**
     * All definitive matches (finished/awarded/walkover) for $teamId across ALL
     * competitions, strictly before this match's kickoff. Used only for the
     * schedule load calculator — no competition/season filter, no score filter.
     */
    private function scheduleHistory(FootballMatch $match, int $teamId): Collection
    {
        if ($match->kickoff_at === null) {
            return collect();
        }

        return FootballMatch::whereIn('status', ['finished', 'awarded', 'walkover'])
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->where(function ($q) use ($teamId) {
                $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->get(['id', 'kickoff_at']);
    }

    /**
     * Previous meetings between the two teams, either side, within the same
     * competition (never crossing competitions), strictly before this match's
     * kickoff — may span earlier seasons of that competition. Bounded by
     * ORDER BY kickoff_at DESC + LIMIT, so this is one dedicated, capped
     * query rather than a scan of the full match history.
     */
    private function headToHead(FootballMatch $match): Collection
    {
        return FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name', 'season:id,name'])
            ->where('competition_id', $match->competition_id)
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where(function ($query) use ($match) {
                $query->where(function ($q) use ($match) {
                    $q->where('home_team_id', $match->home_team_id)->where('away_team_id', $match->away_team_id);
                })->orWhere(function ($q) use ($match) {
                    $q->where('home_team_id', $match->away_team_id)->where('away_team_id', $match->home_team_id);
                });
            })
            ->orderByDesc('kickoff_at')
            ->limit(self::H2H_LIMIT)
            ->get();
    }
}
