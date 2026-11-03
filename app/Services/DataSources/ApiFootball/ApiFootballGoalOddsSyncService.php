<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\Competition;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\GoalOddsSnapshot;
use App\Models\MatchExternalId;
use App\Models\Season;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Odds GOAL — prospective, append-only collection of PRE-MATCH goal-market quotes
 * (Over/Under totale 0.5-4.5, BTTS, Team Goals casa/trasferta 0.5-2.5) for the core leagues.
 * Raw quotes only: no fair probability, edge, EV or recommendation, and nothing in the
 * Prediction Engine reads goal_odds_snapshots. The 1X2 collection (ApiFootballOddsSyncService)
 * is independent and untouched.
 *
 * ── Call strategy (Step 45 probe) ───────────────────────────────────────────
 * One UNFILTERED request family per (league, season): `/odds?league=&season=&page=N`
 * returns every market of ~10 fixtures per page (Bundesliga: 3 pages for the whole league),
 * versus one filtered family per bet (4 bets => ~4x the calls). The bets are filtered
 * explicitly at parse time (config api-football.goal_odds.bets, see GoalOddsParser).
 * A competition is queried only if it has a scheduled core fixture inside `horizon_days`, at most
 * once per `throttle_minutes` (failed attempt: `error_retry_minutes`); the throttle gate is the
 * latest `data_sync_runs` row with sync_type=goal_odds_sync per competition.
 *
 * ── Append-only + dedup ─────────────────────────────────────────────────────
 * One observation = the COMPLETE selections of one (match, bookmaker, market, line) at one fetch,
 * written together under a shared observation_id. If the latest stored observation has identical
 * odds for every selection AND the same provider_updated_at it is a duplicate; otherwise a new
 * observation is appended.
 *
 * ── Pre-match validity ──────────────────────────────────────────────────────
 * Stored only if the match is still `scheduled`, fetched_at < kickoff_at (kickoff as known at fetch
 * time, stored on the row) and the provider update time, when present, is also before kickoff.
 *
 * No historical backfill: only upcoming fixtures are ever queried.
 */
class ApiFootballGoalOddsSyncService
{
    private const SYNC_TYPE = 'goal_odds_sync';

    private ?DataSource $ds = null;

    public function __construct(private readonly ApiFootballClient $client) {}

    private function dataSource(): DataSource
    {
        return $this->ds ??= DataSource::where('slug', 'api-football')->firstOrFail();
    }

    /**
     * @return array{enabled:bool,competitions:int,api_calls:int,created:int,duplicates:int,skipped:int,discarded:int,errors:int,skipped_throttle:int}
     */
    public function syncPending(?string $onlySlug = null, bool $ignoreThrottle = false): array
    {
        $summary = [
            'enabled' => (bool) config('api-football.goal_odds.enabled', true),
            'competitions' => 0, 'api_calls' => 0, 'created' => 0, 'duplicates' => 0,
            'skipped' => 0, 'discarded' => 0, 'errors' => 0, 'skipped_throttle' => 0,
        ];

        if (! $summary['enabled']) {
            return $summary;
        }

        // Core leagues only (api league id => slug).
        foreach ((array) config('api-football.core_leagues', []) as $leagueId => $slug) {
            if ($onlySlug !== null && $slug !== $onlySlug) {
                continue;
            }

            $competition = Competition::where('slug', $slug)->first();
            if ($competition === null) {
                continue;
            }

            $candidates = $this->candidateMatches($competition);
            if ($candidates->isEmpty()) {
                continue;
            }

            if (! $ignoreThrottle && $this->throttled($competition)) {
                $summary['skipped_throttle']++;
                continue;
            }

            $summary['competitions']++;
            $r = $this->syncCompetition($competition, (int) $leagueId, $candidates);
            foreach (['api_calls', 'created', 'duplicates', 'skipped', 'discarded'] as $k) {
                $summary[$k] += $r[$k];
            }
            $summary['errors'] += $r['error'] ? 1 : 0;
        }

        return $summary;
    }

    /** @return Collection<string, FootballMatch>  external fixture id => scheduled, still-future match within the horizon */
    private function candidateMatches(Competition $competition): Collection
    {
        $ds = $this->dataSource();

        $matches = FootballMatch::where('competition_id', $competition->id)
            ->where('status', 'scheduled')
            ->whereNotNull('kickoff_at')
            ->where('kickoff_at', '>', now())
            ->where('kickoff_at', '<=', now()->addDays((int) config('api-football.goal_odds.horizon_days', 14)))
            ->get(['id', 'competition_id', 'season_id', 'kickoff_at', 'status']);

        if ($matches->isEmpty()) {
            return collect();
        }

        $ext = MatchExternalId::where('data_source_id', $ds->id)
            ->whereIn('match_id', $matches->pluck('id'))
            ->pluck('external_id', 'match_id');

        return $matches->filter(fn ($m) => isset($ext[$m->id]))
            ->keyBy(fn ($m) => (string) $ext[$m->id]);
    }

    private function throttled(Competition $competition): bool
    {
        $last = DataSyncRun::where('data_source_id', $this->dataSource()->id)
            ->where('sync_type', self::SYNC_TYPE)
            ->where('competition_id', $competition->id)
            ->orderByDesc('started_at')
            ->first();

        if ($last === null) {
            return false;
        }

        $minutes = $last->status === 'ok'
            ? (int) config('api-football.goal_odds.throttle_minutes', 180)
            : (int) config('api-football.goal_odds.error_retry_minutes', 60);

        return $last->started_at->gt(now()->subMinutes($minutes));
    }

    /**
     * @param  Collection<string, FootballMatch>  $candidates
     * @return array{api_calls:int,created:int,duplicates:int,skipped:int,discarded:int,error:bool}
     */
    private function syncCompetition(Competition $competition, int $leagueId, Collection $candidates): array
    {
        $started = now();
        $apiCalls = $created = $duplicates = $skipped = $discarded = $pages = 0;
        $remaining = null;
        $error = null;

        $seasonYears = Season::whereIn('id', $candidates->pluck('season_id')->unique())->pluck('year_start')->unique();

        try {
            foreach ($seasonYears as $year) {
                $page = 1;
                do {
                    $response  = $this->client->get('odds', ['league' => $leagueId, 'season' => $year, 'page' => $page]);
                    $fetchedAt = now();
                    $apiCalls++;
                    $pages++;
                    $remaining = $response->requestsRemaining ?? $remaining;

                    $r = $this->storePage($response->response, $candidates, $fetchedAt);
                    $created    += $r['created'];
                    $duplicates += $r['duplicates'];
                    $skipped    += $r['skipped'];
                    $discarded  += $r['discarded'];

                    $total = (int) ($response->paging['total'] ?? 1);
                    $page++;
                } while ($page <= $total && $page <= (int) config('api-football.goal_odds.max_pages', 20));
            }
        } catch (ApiFootballException $e) {
            $error = $e->getMessage();
            Log::error("api-football-goal-odds: {$competition->slug} — {$error}");
        }

        DataSyncRun::create([
            'data_source_id'  => $this->dataSource()->id,
            'sync_type'       => self::SYNC_TYPE,
            'competition_id'  => $competition->id,
            'season_id'       => null,
            'mode'            => 'pending',
            'started_at'      => $started,
            'finished_at'     => now(),
            'status'          => $error === null ? 'ok' : 'error',
            'created_count'   => $created,
            'updated_count'   => 0,
            'unchanged_count' => $duplicates,
            'skipped_count'   => $skipped,
            'warnings_count'  => $discarded,
            'api_calls'       => $apiCalls,
            'daily_remaining' => $remaining,
            'details'         => ['pages' => $pages, 'error' => $error],
        ]);

        return [
            'api_calls' => $apiCalls, 'created' => $created, 'duplicates' => $duplicates,
            'skipped' => $skipped, 'discarded' => $discarded, 'error' => $error !== null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  Collection<string, FootballMatch> $candidates
     * @return array{created:int,duplicates:int,skipped:int,discarded:int}  created/duplicates count observations (selection pairs)
     */
    private function storePage(array $items, Collection $candidates, Carbon $fetchedAt): array
    {
        $created = $duplicates = $skipped = $discarded = 0;
        $dsId        = $this->dataSource()->id;
        $marketByBet = array_map('strval', (array) config('api-football.goal_odds.bets', []));

        $pageMatches = [];
        foreach ($items as $item) {
            $match = $candidates->get((string) ($item['fixture']['id'] ?? ''));
            if ($match !== null) {
                $pageMatches[$match->id] = true;
            }
        }
        $latest = $this->latestObservations(array_keys($pageMatches), $dsId);

        foreach ($items as $item) {
            $match = $candidates->get((string) ($item['fixture']['id'] ?? ''));
            if ($match === null) {
                continue; // fixture outside our scope / not a future scheduled match
            }

            $providerUpdatedAt = $this->parseTime($item['update'] ?? null);

            // Pre-match validity: never store anything acquired at/after kickoff.
            if (! $fetchedAt->lt($match->kickoff_at)
                || ($providerUpdatedAt !== null && ! $providerUpdatedAt->lt($match->kickoff_at))) {
                $skipped++;
                continue;
            }

            foreach ($item['bookmakers'] ?? [] as $bookmaker) {
                if (! isset($bookmaker['id'])) {
                    continue;
                }

                foreach ($bookmaker['bets'] ?? [] as $bet) {
                    $betId = (int) ($bet['id'] ?? 0);
                    if (! isset($marketByBet[$betId])) {
                        continue; // not one of the explicit GOAL bets
                    }

                    $parsed = GoalOddsParser::parse($betId, $marketByBet, $bet['values'] ?? []);
                    $discarded += $parsed['discarded'];

                    foreach ($parsed['observations'] as $obs) {
                        $key  = $match->id . '|' . (int) $bookmaker['id'] . '|' . $obs['market'] . '|' . $this->lineKey($obs['line']);
                        $prev = $latest[$key] ?? null;

                        if ($prev !== null && $this->sameObservation($prev, $obs['selections'], $providerUpdatedAt)) {
                            $duplicates++;
                            continue;
                        }

                        $observationId = (string) Str::uuid();
                        $rows = [];
                        DB::transaction(function () use (&$rows, $obs, $observationId, $match, $dsId, $bookmaker, $bet, $betId, $providerUpdatedAt, $fetchedAt) {
                            foreach ($obs['selections'] as $selection => $s) {
                                $rows[$selection] = GoalOddsSnapshot::create([
                                    'match_id'            => $match->id,
                                    'data_source_id'      => $dsId,
                                    'observation_id'      => $observationId,
                                    'bookmaker_id'        => (int) $bookmaker['id'],
                                    'bookmaker_name'      => (string) ($bookmaker['name'] ?? ''),
                                    'bet_id'              => $betId,
                                    'bet_name'            => (string) ($bet['name'] ?? ''),
                                    'market'              => $obs['market'],
                                    'selection'           => $selection,
                                    'line'                => $obs['line'],
                                    'provider_label'      => $s['label'],
                                    'odd'                 => $s['odd'],
                                    'provider_updated_at' => $providerUpdatedAt,
                                    'fetched_at'          => $fetchedAt,
                                    'kickoff_at'          => $match->kickoff_at,
                                ]);
                            }
                        });
                        $latest[$key] = $rows; // a bookmaker repeated within a response must not double-insert
                        $created++;
                    }
                }
            }
        }

        return compact('created', 'duplicates', 'skipped', 'discarded');
    }

    /**
     * Latest stored observation per (match, bookmaker, market, line): selection => row.
     *
     * @return array<string, array<string, GoalOddsSnapshot>>
     */
    private function latestObservations(array $matchIds, int $dsId): array
    {
        if ($matchIds === []) {
            return [];
        }

        $rows = GoalOddsSnapshot::whereIn('id', function ($q) use ($matchIds, $dsId) {
            $q->from('goal_odds_snapshots')->selectRaw('MAX(id)')
                ->whereIn('match_id', $matchIds)
                ->where('data_source_id', $dsId)
                ->groupBy('match_id', 'bookmaker_id', 'market', 'line', 'selection');
        })->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->match_id . '|' . $r->bookmaker_id . '|' . $r->market . '|' . $this->lineKey($r->line)][$r->selection] = $r;
        }

        return $out;
    }

    /**
     * @param array<string, GoalOddsSnapshot>                    $prev  selection => latest stored row
     * @param array<string, array{odd:float,label:string}>       $selections
     */
    private function sameObservation(array $prev, array $selections, ?Carbon $providerUpdatedAt): bool
    {
        if (array_diff_key($selections, $prev) !== [] || array_diff_key($prev, $selections) !== []) {
            return false;
        }

        $newTs = $providerUpdatedAt?->getTimestamp();
        foreach ($selections as $selection => $s) {
            $row = $prev[$selection] ?? null;
            if ($row === null
                || round($row->odd, 3) !== round($s['odd'], 3)
                || $row->provider_updated_at?->getTimestamp() !== $newTs) {
                return false;
            }
        }

        return true;
    }

    private function lineKey(?float $line): string
    {
        return $line === null ? '-' : number_format($line, 1, '.', '');
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
