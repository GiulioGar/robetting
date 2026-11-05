<?php

namespace App\Http\Controllers;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Services\Analytics\HeadToHeadCalculator;
use App\Services\Analytics\UefaPhase;
use App\Services\Analytics\PlayerRecentLoadCalculator;
use App\Services\Analytics\TeamAgeProfileCalculator;
use App\Services\Analytics\TeamAnalyticsCalculator;
use App\Services\Analytics\TeamScheduleLoadCalculator;
use App\Services\Analytics\TeamAbsenceImpactCalculator;
use App\Services\Analytics\TeamAvailabilityCalculator;
use App\Services\Analytics\TeamStarterContinuityCalculator;
use App\Services\Analytics\CompetitionStatisticsCalculator;
use App\Services\Analytics\TeamOpponentAdjustedPerformanceCalculator;
use App\Services\Analytics\TeamOpponentQualityCalculator;
use App\Services\Analytics\TeamStrengthComparisonCalculator;
use App\Services\Analytics\TeamTimeDecayedPerformanceCalculator;
use App\Models\Prediction;
use App\Services\Matches\MatchMarketOddsBuilder;
use App\Services\Odds\MarketConsensusService;
use App\Services\Odds\ModelMarketDiagnostics;
use App\Services\Matches\PreferredMatchStatisticResolver;
use App\Services\Prediction\TeamHistoryMatchEligibility;
use App\Services\DataSources\ApiFootball\ApiFootballGoalOddsSyncService;
use App\Services\Odds\GoalMarketComparator;
use App\Services\Prediction\GoalMarketProbabilityCalculator;
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

    public function show(Request $request, FootballMatch $match, PublicMatchPredictionService $publicPredictionService): View
    {
        $match->load(['competition.country', 'season', 'homeTeam', 'awayTeam']);

        $matchViewMode = $this->classifyViewMode($match->status);

        // Opt-in only (?debug_predict=1) — never shown by default. Diagnostic-only
        // display of model_used/fallback_reason for internal visual testing (e.g.
        // UEFA Champions League fixtures); the public "Pronostico Robetting" box
        // otherwise never leaks technical details (P17B invariant, unchanged).
        $showPredictionDiagnostics = $request->boolean('debug_predict');

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
            'showPredictionDiagnostics' => $showPredictionDiagnostics,
            // Raw 1X2 market (Odds V1, certified pre-match snapshots only) — information only, no value logic.
            'marketOdds'    => MatchMarketOddsBuilder::forMatch((int) $match->id),
        ];

        if ($matchViewMode === 'pre_match') {
            // Real match-level statistics are still shown for a pre-match
            // fixture if it somehow already has any (defensive — normally
            // empty before kickoff), so this stays outside the mode branch
            // in spirit but is computed together with the rest below since
            // it needs $homePrevious/$awayPrevious either way.
            $data = array_merge($data, $this->buildPreMatchData($match, $publicPredictionService, $showPredictionDiagnostics));
        } else {
            // 'finished' / 'other' — Step 1B.2: NOT merely hidden in the
            // view, genuinely never computed. No previousMatches()/
            // allCompetitionPreviousMatches() scan, none of the 13 pre-match
            // calculators, no prediction pipeline call.
            $matchStatistics = PreferredMatchStatisticResolver::forMatchIds(collect([$match->id]));
            $data['matchStatistic']   = $matchStatistics->get($match->id);
            $data['publicPrediction'] = null;
        }

        // Value Engine V1 — diagnostic only (no value/threshold/stake/recommendation).
        $data['marketComparison'] = $this->marketComparison($match, $matchViewMode, $data['publicPrediction'] ?? null);

        // GOAL markets derived from the lambdas of the SAME public prediction (no second prediction call, nothing stored).
        $data['goalMarkets'] = $this->goalMarkets($data['publicPrediction'] ?? null);

        // Robetting vs bookmaker GOAL quotes (diagnostic, no recommendation): the SAME current prediction as above,
        // evaluated as of now(). forMatch() (official predictions, historical evaluation) is deliberately not used here.
        $data['goalComparison'] = $data['goalMarkets'] === null
            ? null
            : $this->goalComparison($match, $data['publicPrediction']);
        // Distinguishes "not yet collected" from "never collected for this competition" in the empty-state message.
        $data['goalOddsInScope'] = ApiFootballGoalOddsSyncService::inScope($match->competition?->slug);

        return view('matches.show', $data);
    }

    /**
     * "Probabilità GOAL Robetting": Bivariate Poisson markets from the Candidate47 lambdas already used by the page.
     * Null (section hidden) when the prediction is missing, is the FULL59 fallback, or the lambdas are not valid.
     *
     * @param array<string, mixed>|null $publicPrediction
     * @return array<string, mixed>|null
     */
    private function goalMarkets(?array $publicPrediction): ?array
    {
        if ($publicPrediction === null
            || ($publicPrediction['fallback_used'] ?? true)
            || ($publicPrediction['model_used'] ?? null) !== PublicMatchPredictionService::MODEL_CANDIDATE_V2
            || ($publicPrediction['lambda3'] ?? null) === null) {
            return null;
        }

        $lh = (float) $publicPrediction['lambda_home'];
        $la = (float) $publicPrediction['lambda_away'];

        try {
            return GoalMarketProbabilityCalculator::calculate(
                $lh,
                $la,
                GoalMarketProbabilityCalculator::effectiveLambda3($lh, $la, (float) $publicPrediction['lambda3'])
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $publicPrediction  eligible Candidate47 prediction (see goalMarkets())
     * @return array<string, mixed>
     */
    private function goalComparison(FootballMatch $match, array $publicPrediction): array
    {
        $asOf = now();

        return GoalMarketComparator::compare([
            'id'           => null,
            'generated_at' => $asOf,
            'lambda_home'  => (float) $publicPrediction['lambda_home'],
            'lambda_away'  => (float) $publicPrediction['lambda_away'],
            'lambda3'      => (float) $publicPrediction['lambda3'],
        ], GoalMarketComparator::quoteRows((int) $match->id, $asOf), $asOf);
    }

    /**
     * "Confronto Robetting vs mercato" (diagnostic). Candidate47 only.
     *  - pre_match: live Candidate47 prediction vs the market as of now();
     *  - finished: ONLY if an official Candidate47 prediction exists, vs the
     *    market as of that prediction's generated_at (never today's/closing odds);
     *  - otherwise: no comparison.
     *
     * @return array<string, mixed>|null
     */
    private function marketComparison(FootballMatch $match, string $mode, ?array $publicPrediction): ?array
    {
        $model = null;
        $asOf  = null;
        $source = null;
        $generatedAt = null;

        if ($mode === 'pre_match') {
            if ($publicPrediction !== null
                && ($publicPrediction['model_used'] ?? null) === PublicMatchPredictionService::MODEL_CANDIDATE_V2
                && ! ($publicPrediction['fallback_used'] ?? true)) {
                $model  = [
                    'home' => (float) $publicPrediction['probability_home'],
                    'draw' => (float) $publicPrediction['probability_draw'],
                    'away' => (float) $publicPrediction['probability_away'],
                ];
                $asOf   = now();
                $source = 'live';
            }
        } elseif ($match->status === 'finished') {
            $prediction = Prediction::where('match_id', $match->id)
                ->where('purpose', Prediction::PURPOSE_OFFICIAL)
                ->where('model_key', PublicMatchPredictionService::MODEL_CANDIDATE_V2)
                ->orderByDesc('generated_at')
                ->orderByDesc('id')
                ->first();

            if ($prediction !== null) {
                $model = [
                    'home' => (float) $prediction->probability_home,
                    'draw' => (float) $prediction->probability_draw,
                    'away' => (float) $prediction->probability_away,
                ];
                $asOf        = $prediction->generated_at;
                $generatedAt = $prediction->generated_at;
                $source      = 'official';
            }
        }

        if ($model === null) {
            return null;
        }

        $market = MarketConsensusService::asOf((int) $match->id, $asOf);
        if ($market === null) {
            return null;
        }

        return [
            'source'            => $source,
            'as_of'             => $asOf,
            'generated_at'      => $generatedAt,
            'bookmaker_count'   => $market['bookmaker_count'],
            'latest_fetched_at' => $market['latest_fetched_at'],
            'rows'              => ModelMarketDiagnostics::compare($model, $market),
        ];
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
    private function buildPreMatchData(
        FootballMatch $match,
        PublicMatchPredictionService $publicPredictionService,
        bool $experimentalPrediction = false,
    ): array {
        $homePrevious = $this->previousMatches($match, $match->home_team_id);
        $awayPrevious = $this->previousMatches($match, $match->away_team_id);
        $h2hMatches   = $this->headToHead($match);

        // Team History (cross-competition, cross-season, CORE+TEAM_HISTORY, as-of
        // kickoff): the standard Recent window, same eligibility the prediction
        // pipeline (PreMatchFeatureAggregator, recentWindowMode=team_history) uses.
        // Competition-specific blocks (season trend, home/away split, E9/E10/E12,
        // league context, H2H) keep reading $homePrevious/$awayPrevious.
        $homeTeamHistory = $match->kickoff_at !== null
            ? TeamHistoryMatchEligibility::matchesBefore((int) $match->home_team_id, $match->kickoff_at)
            : collect();
        $awayTeamHistory = $match->kickoff_at !== null
            ? TeamHistoryMatchEligibility::matchesBefore((int) $match->away_team_id, $match->kickoff_at)
            : collect();

        // Descriptive scopes (Totale | Competizione) share ONE definition of "all matches available": every finished
        // match in the DB before the kickoff, any competition. "Totale" season = same season year across competitions.
        $homeAll = $this->allFinishedBefore($match, (int) $match->home_team_id);
        $awayAll = $this->allFinishedBefore($match, (int) $match->away_team_id);
        $seasonYear = (int) $match->season->year_start;
        $inSeasonYear = fn (Collection $c): Collection => $c->filter(fn ($m) => (int) $m->season?->year_start === $seasonYear)->values();
        $homeTotalSeason = $inSeasonYear($homeAll);
        $awayTotalSeason = $inSeasonYear($awayAll);

        $matchStatistics = PreferredMatchStatisticResolver::forMatchIds(
            collect([$match->id])
                ->merge($homePrevious->pluck('id'))
                ->merge($awayPrevious->pluck('id'))
                ->merge($homeTeamHistory->pluck('id'))
                ->merge($awayTeamHistory->pluck('id'))
                ->merge($homeTotalSeason->pluck('id'))
                ->merge($awayTotalSeason->pluck('id'))
                ->merge($homeAll->slice(-10)->pluck('id'))
                ->merge($awayAll->slice(-10)->pluck('id'))
        );

        $matchStatistic = $matchStatistics->get($match->id);

        // Descriptive season blocks (Confronto pre-match / Split / Trend). For UEFA club fixtures they use ONLY matches of
        // the same phase (qualification / league stage / knockout) of the same season; other blocks keep the full set.
        $isUefa    = UefaPhase::isUefaClubSlug($match->competition?->slug);
        $uefaPhase = $isUefa ? UefaPhase::fromRound($match->round) : null;
        $samePhase = fn (Collection $c): Collection => $uefaPhase === null
            ? $c
            : $c->filter(fn ($m) => UefaPhase::fromRound($m->round) === $uefaPhase)->values();
        $homeSeasonSet = $samePhase($homePrevious);
        $awaySeasonSet = $samePhase($awayPrevious);

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

        // Recent windows (Forma prima del match, Prestazione recente) — Team History.
        $homeRecent5     = $this->lastN($homeTeamHistory, 5);
        $homeRecent10    = $this->lastN($homeTeamHistory, 10);
        $homeRecent5Home = $this->lastN($homeTeamHistory->where('home_team_id', $match->home_team_id)->values(), 5);
        $awayRecent5     = $this->lastN($awayTeamHistory, 5);
        $awayRecent10    = $this->lastN($awayTeamHistory, 10);
        $awayRecent5Away = $this->lastN($awayTeamHistory->where('away_team_id', $match->away_team_id)->values(), 5);

        $homeSeasonAnalytics     = TeamAnalyticsCalculator::calculate($homeSeasonSet, $match->home_team_id, $matchStatistics);
        $homeLast5Analytics      = TeamAnalyticsCalculator::calculate($homeRecent5, $match->home_team_id, $matchStatistics);
        $homeLast10Analytics     = TeamAnalyticsCalculator::calculate($homeRecent10, $match->home_team_id, $matchStatistics);
        $homeHomeAnalytics       = TeamAnalyticsCalculator::calculate($homeSeasonSet->where('home_team_id', $match->home_team_id)->values(), $match->home_team_id, $matchStatistics);
        $homeRecentHomeAnalytics = TeamAnalyticsCalculator::calculate($homeRecent5Home, $match->home_team_id, $matchStatistics);

        $awaySeasonAnalytics     = TeamAnalyticsCalculator::calculate($awaySeasonSet, $match->away_team_id, $matchStatistics);
        $awayLast5Analytics      = TeamAnalyticsCalculator::calculate($awayRecent5, $match->away_team_id, $matchStatistics);
        $awayLast10Analytics     = TeamAnalyticsCalculator::calculate($awayRecent10, $match->away_team_id, $matchStatistics);
        $awayAwayAnalytics       = TeamAnalyticsCalculator::calculate($awaySeasonSet->where('away_team_id', $match->away_team_id)->values(), $match->away_team_id, $matchStatistics);
        $awayRecentAwayAnalytics = TeamAnalyticsCalculator::calculate($awayRecent5Away, $match->away_team_id, $matchStatistics);

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

        // Availability V1 (display only — never feeds the prediction). Fail-closed:
        // injuries_covered is false unless a PRE-MATCH-certified snapshot exists.
        $homeAvailability = TeamAvailabilityCalculator::calculateForMatch($match, (int) $match->home_team_id);
        $awayAvailability = TeamAvailabilityCalculator::calculateForMatch($match, (int) $match->away_team_id);
        $availabilityPlayerIds = collect($homeAvailability['absentees'])->merge($awayAvailability['absentees'])->pluck('player_id')->unique()->values();
        $availabilityPlayerNames = $availabilityPlayerIds->isEmpty()
            ? collect()
            : Player::whereIn('id', $availabilityPlayerIds)->pluck('name', 'id');

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
        // Step C-DEBUG-EXPERIMENTAL: $experimentalPrediction (= ?debug_predict=1)
        // switches ONLY this call's internals (Team History Recent + Elo Extended +
        // the 3 experimental artifacts) — never the normal/default request path.
        $publicPrediction = $publicPredictionService->predict($match, $experimentalPrediction);

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
            'homeAvailability'         => $homeAvailability,
            'awayAvailability'         => $awayAvailability,
            'availabilityPlayerNames'  => $availabilityPlayerNames,
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
            // Descriptive statistics, two scopes (Totale | Competizione): same definition for every block, descriptive only.
            'scopes'                      => [
                'total'       => $this->scopeData('total', 'Totale', $match, $matchStatistics,
                    $homeTotalSeason, $awayTotalSeason, $homeAll, $awayAll, $homeAll, $awayAll),
                'competition' => $this->scopeData('competition', 'Competizione', $match, $matchStatistics,
                    $homeSeasonSet, $awaySeasonSet, $homeSeasonSet, $awaySeasonSet, $homeAll, $awayAll),
            ],
            // UEFA fixtures only: phase of this match and each team's last UEFA matches (descriptive, not a model input).
            'uefaPhaseLabel'              => $uefaPhase !== null ? UefaPhase::label($uefaPhase) : null,
            'uefaForm'                    => $isUefa ? [
                'home' => $this->uefaForm($match, (int) $match->home_team_id),
                'away' => $this->uefaForm($match, (int) $match->away_team_id),
            ] : null,
        ];
    }

    /**
     * Every finished match of the team (any competition in the DB) strictly before the kickoff, oldest first, with the
     * relations needed by the descriptive blocks. The single query behind both scopes.
     */
    private function allFinishedBefore(FootballMatch $match, int $teamId): Collection
    {
        if ($match->kickoff_at === null) {
            return collect();
        }

        return FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name', 'competition:id,name,slug', 'season:id,name,year_start'])
            ->where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->orderBy('kickoff_at')
            ->get();
    }

    /**
     * One descriptive scope (Totale | Competizione): season set (Confronto/Split/Trend), form windows (last 5/10),
     * popover texts, sample info and national-league coverage — all computed from the same two sets per team.
     *
     * @return array<string,mixed>
     */
    private function scopeData(
        string $key, string $label, FootballMatch $match, Collection $stats,
        Collection $homeSeason, Collection $awaySeason, Collection $homeForm, Collection $awayForm,
        Collection $homeAll, Collection $awayAll,
    ): array {
        $homeId = (int) $match->home_team_id;
        $awayId = (int) $match->away_team_id;
        $fallbackComp = $match->competition?->name;
        $coreSlugs = array_values((array) config('api-football.core_leagues', []));
        $covered = fn (Collection $all): bool => $all->contains(fn ($m) => in_array($m->competition?->slug, $coreSlugs, true));

        return [
            'key'   => $key,
            'label' => $label,
            'season' => [
                'home' => TeamAnalyticsCalculator::calculate($homeSeason, $homeId, $stats),
                'away' => TeamAnalyticsCalculator::calculate($awaySeason, $awayId, $stats),
            ],
            'split' => [
                'home' => TeamAnalyticsCalculator::calculate($homeSeason->where('home_team_id', $homeId)->values(), $homeId, $stats),
                'away' => TeamAnalyticsCalculator::calculate($awaySeason->where('away_team_id', $awayId)->values(), $awayId, $stats),
            ],
            'form' => [
                'home' => ['last5'  => TeamAnalyticsCalculator::calculate($this->lastN($homeForm, 5), $homeId, $stats),
                           'last10' => TeamAnalyticsCalculator::calculate($this->lastN($homeForm, 10), $homeId, $stats)],
                'away' => ['last5'  => TeamAnalyticsCalculator::calculate($this->lastN($awayForm, 5), $awayId, $stats),
                           'last10' => TeamAnalyticsCalculator::calculate($this->lastN($awayForm, 10), $awayId, $stats)],
            ],
            'tooltips' => [
                'home' => ['last5' => $this->matchTooltips($this->lastN($homeForm, 5), $fallbackComp), 'last10' => $this->matchTooltips($this->lastN($homeForm, 10), $fallbackComp)],
                'away' => ['last5' => $this->matchTooltips($this->lastN($awayForm, 5), $fallbackComp), 'last10' => $this->matchTooltips($this->lastN($awayForm, 10), $fallbackComp)],
            ],
            'sample' => [
                'season' => ['home' => $this->sampleInfo($homeSeason), 'away' => $this->sampleInfo($awaySeason)],
                'form10' => ['home' => $this->sampleInfo($this->lastN($homeForm, 10)), 'away' => $this->sampleInfo($this->lastN($awayForm, 10))],
            ],
            // National league present in the DB (a core-league match exists for the team)? Drives the coverage note.
            'covered' => ['home' => $covered($homeAll), 'away' => $covered($awayAll)],
        ];
    }

    /** @return list<string> e.g. "22/10/2026 · Serie A · AC Milan 0-3 Atalanta", in the order of $window (oldest first). */
    private function matchTooltips(Collection $window, ?string $fallbackCompetition = null): array
    {
        return $window->map(fn ($m) => $m->kickoff_at->format('d/m/Y') . ' · ' . ($m->competition?->name ?? $fallbackCompetition ?? '?') . ' · '
            . ($m->homeTeam?->name ?? '?') . ' ' . $m->home_score_ft . '-' . $m->away_score_ft . ' ' . ($m->awayTeam?->name ?? '?'))->values()->all();
    }

    /**
     * Last UEFA club-competition matches actually played by the team (any UEFA competition/season in the DB, finished,
     * strictly before the kickoff), oldest first, each tagged with its phase. Independent of MatchDataScope.
     *
     * @return list<array<string,mixed>>
     */
    private function uefaForm(FootballMatch $match, int $teamId, int $limit = 5): array
    {
        if ($match->kickoff_at === null) {
            return [];
        }

        return FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name', 'competition:id,name,slug'])
            ->whereHas('competition', fn ($q) => $q->whereIn('slug', UefaPhase::CLUB_SLUGS))
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->orderByDesc('kickoff_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(function ($m) use ($teamId) {
                $home = (int) $m->home_team_id === $teamId;
                $for = (int) ($home ? $m->home_score_ft : $m->away_score_ft);
                $against = (int) ($home ? $m->away_score_ft : $m->home_score_ft);
                $phase = UefaPhase::fromRound($m->round);

                return [
                    'match_id'    => (int) $m->id,
                    'date'        => $m->kickoff_at,
                    'phase'       => $phase,
                    'phase_label' => UefaPhase::label($phase),
                    'round'       => $m->round,
                    'competition' => $m->competition?->name,
                    'opponent'    => ($home ? $m->awayTeam : $m->homeTeam)?->name ?? '?',
                    'venue'       => $home ? 'C' : 'T',
                    'score'       => $for . '-' . $against,
                    'result'      => $for > $against ? 'W' : ($for === $against ? 'D' : 'L'),
                    'tooltip'     => $m->kickoff_at->format('d/m/Y') . ' · ' . ($m->competition?->name ?? '?') . ' · ' . ($m->homeTeam?->name ?? '?') . ' ' . $m->home_score_ft . '-' . $m->away_score_ft . ' ' . ($m->awayTeam?->name ?? '?'),
                ];
            })
            ->all();
    }

    /** Size and date range of a match sample (shown next to the statistics built from it). */
    private function sampleInfo(Collection $matches): array
    {
        return [
            'n'     => $matches->count(),
            'first' => $matches->min('kickoff_at'),
            'last'  => $matches->max('kickoff_at'),
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
