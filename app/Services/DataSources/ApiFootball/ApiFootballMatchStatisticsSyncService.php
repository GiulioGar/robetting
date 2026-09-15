<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\TeamExternalId;
use Illuminate\Support\Facades\Log;

class ApiFootballMatchStatisticsSyncService
{
    /**
     * Current statistics schema version.
     *
     * A row at this version was processed with the full current parser.
     * See migration 2026_09_15_000001 for the full version history.
     *
     *   1 = fetched before extended-stats / xG columns existed → incomplete
     *   2 = current: extended stats + expected_goals + goals_prevented + raw_stats
     */
    public const CURRENT_STATS_SCHEMA_VERSION = 2;

    private ?DataSource $ds = null;

    public function __construct(private readonly ApiFootballClient $client) {}

    private function dataSource(): DataSource
    {
        return $this->ds ??= DataSource::where('slug', 'api-football')->firstOrFail();
    }

    /**
     * Fetch statistics for all definitive matches that are absent or below the current schema version.
     * Completeness gate: stats_schema_version >= CURRENT_STATS_SCHEMA_VERSION.
     * One API call per candidate match (no batch endpoint for statistics).
     *
     * @return array{status:string,candidates:int,created:int,updated:int,unchanged:int,skipped:int,warnings:list<string>,api_calls:int,daily_remaining:int|null}
     */
    public function syncAll(): array
    {
        $ds        = $this->dataSource();
        $startedAt = now();

        // Definitive match IDs
        $definitiveMatchIds = FootballMatch::whereIn('status', ApiFootballFixtureSyncService::DEFINITIVE_STATUSES)
            ->pluck('id');

        // Restrict to matches that have an api-football external ID: match_id => external_id
        $extIdByMatchId = MatchExternalId::where('data_source_id', $ds->id)
            ->whereIn('match_id', $definitiveMatchIds)
            ->pluck('external_id', 'match_id')
            ->all();

        // Definitive matches without an external ID → skipped (out-of-source)
        $withExtIdSet   = collect(array_keys($extIdByMatchId));
        $skippedNoExtId = $definitiveMatchIds->diff($withExtIdSet)->count();

        // Existing stats keyed by match_id
        $existingStats = MatchStatistic::where('data_source_id', $ds->id)
            ->whereIn('match_id', $withExtIdSet)
            ->get()
            ->keyBy('match_id')
            ->all();

        // Completeness: row exists AND stats_schema_version >= CURRENT_STATS_SCHEMA_VERSION.
        // A versioned row was processed with the full current parser; any null metric values
        // reflect genuinely absent data from the source (e.g. Bundesliga returns no xG).
        // fetched_at remains informative (last fetch timestamp) but is no longer the gate.
        $candidates = [];
        $unchanged  = 0;

        foreach ($extIdByMatchId as $matchId => $extId) {
            $stat = $existingStats[$matchId] ?? null;
            if ($stat !== null && $stat->stats_schema_version !== null && $stat->stats_schema_version >= self::CURRENT_STATS_SCHEMA_VERSION) {
                $unchanged++;
            } else {
                $candidates[$matchId] = $extId;
            }
        }

        $warnings      = [];
        $created       = 0;
        $updated       = 0;
        $skipped       = $skippedNoExtId;
        $apiCalls      = 0;
        $lastRemaining = null;

        if ($skippedNoExtId > 0) {
            $warnings[] = "{$skippedNoExtId} definitive match(es) have no api-football external_id — skipped";
        }

        if (!empty($candidates)) {
            // Pre-load match rows for home/away team resolution
            $matches = FootballMatch::whereIn('id', array_keys($candidates))->get()->keyBy('id')->all();

            // Pre-load team external IDs so we can identify home vs away in the response
            $teamIds      = collect($matches)->flatMap(fn($m) => [$m->home_team_id, $m->away_team_id])->unique();
            $teamExtIdMap = $teamIds->isNotEmpty()
                ? TeamExternalId::where('data_source_id', $ds->id)
                    ->whereIn('team_id', $teamIds)
                    ->pluck('external_id', 'team_id')
                    ->all()
                : [];

            foreach ($candidates as $matchId => $extId) {
                $match = $matches[$matchId] ?? null;
                if (!$match) {
                    $skipped++;
                    $warnings[] = "match {$matchId}: not found in pre-load";
                    continue;
                }

                $homeExtId = $teamExtIdMap[$match->home_team_id] ?? null;

                try {
                    $response      = $this->client->get('fixtures/statistics', ['fixture' => $extId]);
                    $apiCalls++;
                    $lastRemaining = $response->requestsRemaining;
                    $fetchedAt     = now();

                    if (empty($response->response)) {
                        // Valid HTTP response but source has no stats for this fixture.
                        // Treat as permanently complete: source confirms no stats exist.
                        MatchStatistic::updateOrCreate(
                            ['match_id' => $matchId, 'data_source_id' => $ds->id],
                            ['fetched_at' => $fetchedAt, 'stats_schema_version' => self::CURRENT_STATS_SCHEMA_VERSION],
                        );
                        $skipped++;
                        $warnings[] = "fixture {$extId}: empty statistics response";
                        continue;
                    }

                    $parsed = $this->parseResponse($response->response, $homeExtId);

                    if ($parsed === null) {
                        // Non-empty response but home/away identification failed (unexpected format).
                        // Do NOT set fetched_at or stats_schema_version: data present but unreadable;
                        // a future sync or parser fix should be able to recover it.
                        $skipped++;
                        $warnings[] = "fixture {$extId}: could not map home/away stats — will retry on next sync";
                        Log::warning("api-football-statistics-sync: fixture {$extId} — response present but unparsable");
                        continue;
                    }

                    $existing = $existingStats[$matchId] ?? null;

                    MatchStatistic::updateOrCreate(
                        ['match_id' => $matchId, 'data_source_id' => $ds->id],
                        array_merge($parsed, ['fetched_at' => $fetchedAt, 'stats_schema_version' => self::CURRENT_STATS_SCHEMA_VERSION]),
                    );

                    if ($existing === null) {
                        $created++;
                    } else {
                        $updated++;
                    }
                } catch (ApiFootballException $e) {
                    // HTTP-level failure: transient, do not set fetched_at so retry is allowed.
                    $skipped++;
                    $warnings[] = "fixture {$extId}: {$e->getMessage()}";
                    Log::error("api-football-statistics-sync: fixture {$extId} — {$e->getMessage()}");
                }
            }
        }

        DataSyncRun::create([
            'data_source_id'  => $ds->id,
            'sync_type'       => 'statistics_sync',
            'competition_id'  => null,
            'season_id'       => null,
            'mode'            => null,
            'started_at'      => $startedAt,
            'finished_at'     => now(),
            'status'          => 'ok',
            'created_count'   => $created,
            'updated_count'   => $updated,
            'unchanged_count' => $unchanged,
            'skipped_count'   => $skipped,
            'warnings_count'  => count($warnings),
            'api_calls'       => $apiCalls,
            'daily_remaining' => $lastRemaining,
            'details'         => empty($warnings) ? null : ['warnings' => $warnings],
        ]);

        return [
            'status'          => 'ok',
            'candidates'      => count($candidates),
            'created'         => $created,
            'updated'         => $updated,
            'unchanged'       => $unchanged,
            'skipped'         => $skipped,
            'warnings'        => $warnings,
            'api_calls'       => $apiCalls,
            'daily_remaining' => $lastRemaining,
        ];
    }

    /**
     * Fetch and upsert statistics for a single definitive match.
     * Skips the API call if stats_schema_version >= CURRENT_STATS_SCHEMA_VERSION.
     * Sets fetched_at and stats_schema_version on any valid 2xx response.
     * Throws ApiFootballException on HTTP failure so the caller can log and continue.
     *
     * @return array{outcome:string,api_calls:int}
     */
    public function syncSingle(FootballMatch $match, string $extId): array
    {
        $ds = $this->dataSource();

        $existing = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $ds->id)
            ->first();

        if ($existing !== null && $existing->stats_schema_version !== null && $existing->stats_schema_version >= self::CURRENT_STATS_SCHEMA_VERSION) {
            return ['outcome' => 'skipped_complete', 'api_calls' => 0];
        }

        return $this->fetchAndUpsertStats($match, $extId, markComplete: true);
    }

    /**
     * Fetch and upsert statistics for a single live match.
     * Always fetches — no sentinel guard. Never sets fetched_at.
     * Throws ApiFootballException on HTTP failure so the caller can log and continue.
     *
     * @return array{outcome:string,api_calls:int}
     */
    public function syncLiveSingle(FootballMatch $match, string $extId): array
    {
        return $this->fetchAndUpsertStats($match, $extId, markComplete: false);
    }

    /**
     * Fetch statistics for all currently-live matches with an API-Football external ID.
     * Never sets fetched_at. HTTP failures are caught and logged as warnings
     * so the result refresh cycle continues uninterrupted.
     *
     * @return array{status:string,candidates:int,synced:int,failed:int,api_calls:int}
     */
    public function syncLive(): array
    {
        $ds = $this->dataSource();

        $liveIds = FootballMatch::where('status', 'live')->pluck('id');

        if ($liveIds->isEmpty()) {
            return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'api_calls' => 0];
        }

        $extIdByMatchId = MatchExternalId::where('data_source_id', $ds->id)
            ->whereIn('match_id', $liveIds)
            ->pluck('external_id', 'match_id')
            ->all();

        if (empty($extIdByMatchId)) {
            Log::warning('api-football-live-stats: ' . $liveIds->count() . ' live match(es) but none have api-football external IDs');
            return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'api_calls' => 0];
        }

        $matchModels = FootballMatch::whereIn('id', array_keys($extIdByMatchId))
            ->get()
            ->keyBy('id')
            ->all();

        $candidates = 0;
        $synced     = 0;
        $failed     = 0;
        $apiCalls   = 0;

        foreach ($extIdByMatchId as $matchId => $extId) {
            $candidates++;
            $match = $matchModels[$matchId] ?? null;
            if (!$match) {
                continue;
            }

            try {
                $result    = $this->syncLiveSingle($match, $extId);
                $apiCalls += $result['api_calls'];
                if (in_array($result['outcome'], ['synced', 'empty'], true)) {
                    $synced++;
                }
            } catch (ApiFootballException $e) {
                $failed++;
                Log::warning("api-football-live-stats: fixture {$extId} — {$e->getMessage()}");
            }
        }

        return [
            'status'     => 'ok',
            'candidates' => $candidates,
            'synced'     => $synced,
            'failed'     => $failed,
            'api_calls'  => $apiCalls,
        ];
    }

    /**
     * Backfill statistics for all definitive matches in the target season that have an
     * API-Football external ID but are absent or below the current schema version.
     *
     * @param  int|null  $seasonYear  year_start of the target season; null = current season(s).
     *
     * Candidacy: absent MatchStatistic row OR stats_schema_version < CURRENT_STATS_SCHEMA_VERSION (or null).
     * Resolved (excluded): stats_schema_version >= CURRENT_STATS_SCHEMA_VERSION.
     *
     * Retryability:
     *  - HTTP failure (ApiFootballException) → failed++, version unchanged → retryable.
     *  - Unparsable response → version unchanged → retryable.
     *  - Empty [] response → stats_schema_version SET to CURRENT (source confirmed no data) → not retried.
     *
     * Ordering: kickoff_at DESC. No hard limit — caller responsible for timeout (set_time_limit(0)).
     *
     * @return array{status:string,candidates:int,created:int,updated:int,unchanged:int,failed:int,api_calls:int,daily_remaining:null}
     */
    public function syncMissingHistorical(?int $seasonYear = null): array
    {
        $ds = $this->dataSource();

        if ($seasonYear !== null) {
            $seasonIds = Season::where('year_start', $seasonYear)->pluck('id');
            if ($seasonIds->isEmpty()) {
                return ['status' => 'no_season_found', 'candidates' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'api_calls' => 0, 'daily_remaining' => null];
            }
        } else {
            $seasonIds = Season::where('is_current', true)->pluck('id');
            if ($seasonIds->isEmpty()) {
                return ['status' => 'no_current_season', 'candidates' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'api_calls' => 0, 'daily_remaining' => null];
            }
        }

        $matchIds = FootballMatch::whereIn('season_id', $seasonIds)
            ->whereIn('status', ApiFootballFixtureSyncService::DEFINITIVE_STATUSES)
            ->pluck('id');

        if ($matchIds->isEmpty()) {
            return ['status' => 'ok', 'candidates' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'api_calls' => 0, 'daily_remaining' => null];
        }

        $extIdByMatchId = MatchExternalId::where('data_source_id', $ds->id)
            ->whereIn('match_id', $matchIds)
            ->pluck('external_id', 'match_id')
            ->all();

        if (empty($extIdByMatchId)) {
            return ['status' => 'ok', 'candidates' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'api_calls' => 0, 'daily_remaining' => null];
        }

        // Pre-load existing stats to separate candidates from already-complete rows.
        $existingByMatchId = MatchStatistic::where('data_source_id', $ds->id)
            ->whereIn('match_id', array_keys($extIdByMatchId))
            ->get()
            ->keyBy('match_id')
            ->all();

        $candidateExtIds = [];
        $unchanged       = 0;

        foreach ($extIdByMatchId as $matchId => $extId) {
            $stat = $existingByMatchId[$matchId] ?? null;
            if ($stat !== null && $stat->stats_schema_version !== null && $stat->stats_schema_version >= self::CURRENT_STATS_SCHEMA_VERSION) {
                $unchanged++;
            } else {
                $candidateExtIds[$matchId] = $extId;
            }
        }

        if (empty($candidateExtIds)) {
            return ['status' => 'ok', 'candidates' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => $unchanged, 'failed' => 0, 'api_calls' => 0, 'daily_remaining' => null];
        }

        $matchModels = FootballMatch::whereIn('id', array_keys($candidateExtIds))
            ->orderByDesc('kickoff_at')
            ->get()
            ->all();

        $candidates = 0;
        $created    = 0;
        $updated    = 0;
        $failed     = 0;
        $apiCalls   = 0;

        foreach ($matchModels as $match) {
            $extId = $candidateExtIds[$match->id] ?? null;
            if ($extId === null) {
                continue;
            }

            $candidates++;
            $hadExistingRow = isset($existingByMatchId[$match->id]);

            try {
                $result    = $this->syncSingle($match, $extId);
                $apiCalls += $result['api_calls'];

                // 'synced' and 'empty' both resolve the row (fetched_at set).
                // 'unparsable' leaves fetched_at unset → silently retryable on next run.
                if (in_array($result['outcome'], ['synced', 'empty'], true)) {
                    $hadExistingRow ? $updated++ : $created++;
                }
            } catch (ApiFootballException $e) {
                $failed++;
                Log::error("api-football-historical-stats: fixture {$extId} — {$e->getMessage()}");
            }
        }

        return [
            'status'          => 'ok',
            'candidates'      => $candidates,
            'created'         => $created,
            'updated'         => $updated,
            'unchanged'       => $unchanged,
            'failed'          => $failed,
            'api_calls'       => $apiCalls,
            'daily_remaining' => null,
        ];
    }

    /**
     * Fetch statistics for definitive matches past the grace period that have no fetched_at yet.
     *
     * Grace period cutoff: kickoff_at <= now() - (90 + $gracePeriodMinutes).
     * Waiting ensures the stats API has had time to process the match, so an empty []
     * response is treated as permanent ("no stats for this fixture") rather than transient —
     * fetched_at is set and the match is never retried. HTTP failures and unparsable responses
     * leave fetched_at unset so the next cycle can retry.
     *
     * @return array{status:string,candidates:int,synced:int,skipped:int,failed:int,api_calls:int}
     */
    public function syncPending(int $gracePeriodMinutes = 10): array
    {
        $ds     = $this->dataSource();
        // Primary criterion: definitive_at is set and past the grace window.
        $cutoff = now()->subMinutes($gracePeriodMinutes);
        // Legacy fallback: for matches that became definitive before definitive_at existed,
        // approximate using kickoff_at + 90 min (standard match duration).
        $legacyCutoff = now()->subMinutes(90 + $gracePeriodMinutes);

        $definitiveIds = FootballMatch::whereIn('status', ApiFootballFixtureSyncService::DEFINITIVE_STATUSES)
            ->where(function ($q) use ($cutoff, $legacyCutoff) {
                $q->where('definitive_at', '<=', $cutoff)
                  ->orWhere(function ($q2) use ($legacyCutoff) {
                      $q2->whereNull('definitive_at')
                         ->where('kickoff_at', '<=', $legacyCutoff);
                  });
            })
            ->pluck('id');

        if ($definitiveIds->isEmpty()) {
            return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'skipped' => 0, 'failed' => 0, 'api_calls' => 0];
        }

        $extIdByMatchId = MatchExternalId::where('data_source_id', $ds->id)
            ->whereIn('match_id', $definitiveIds)
            ->pluck('external_id', 'match_id')
            ->all();

        if (empty($extIdByMatchId)) {
            return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'skipped' => 0, 'failed' => 0, 'api_calls' => 0];
        }

        // Exclude matches already at the current schema version — these are complete.
        $alreadyComplete = MatchStatistic::where('data_source_id', $ds->id)
            ->whereIn('match_id', array_keys($extIdByMatchId))
            ->where('stats_schema_version', '>=', self::CURRENT_STATS_SCHEMA_VERSION)
            ->pluck('match_id')
            ->flip()
            ->all();

        $matchModels = FootballMatch::whereIn('id', array_keys($extIdByMatchId))
            ->get()
            ->keyBy('id')
            ->all();

        $candidates = 0;
        $synced     = 0;
        $skipped    = 0;
        $failed     = 0;
        $apiCalls   = 0;

        foreach ($extIdByMatchId as $matchId => $extId) {
            if (isset($alreadyComplete[$matchId])) {
                continue;
            }

            $candidates++;
            $match = $matchModels[$matchId] ?? null;

            if (!$match) {
                $skipped++;
                Log::warning("api-football-pending-stats: match {$matchId} not found in pre-load");
                continue;
            }

            try {
                $result    = $this->syncSingle($match, $extId);
                $apiCalls += $result['api_calls'];

                if (in_array($result['outcome'], ['synced', 'empty'], true)) {
                    $synced++;
                } else {
                    $skipped++;
                }
            } catch (ApiFootballException $e) {
                $failed++;
                Log::error("api-football-pending-stats: fixture {$extId} — {$e->getMessage()}");
            }
        }

        return [
            'status'     => 'ok',
            'candidates' => $candidates,
            'synced'     => $synced,
            'skipped'    => $skipped,
            'failed'     => $failed,
            'api_calls'  => $apiCalls,
        ];
    }

    /**
     * Core fetch + upsert for a single match.
     * $markComplete=true  → sets fetched_at + stats_schema_version = CURRENT on success (post-match).
     * $markComplete=false → never touches fetched_at or stats_schema_version (live flow).
     *
     * Version is set ONLY on success (synced or empty):
     *  - HTTP failure → version unchanged (retryable).
     *  - Unparsable response → version unchanged (retryable).
     *  - Empty API response → treated as permanently complete (source has no stats).
     *  - Null metric values from source (e.g. Bundesliga no xG) are NOT a failure → version set.
     *
     * @return array{outcome:string,api_calls:int}
     */
    private function fetchAndUpsertStats(FootballMatch $match, string $extId, bool $markComplete): array
    {
        $ds = $this->dataSource();

        $homeExtId = TeamExternalId::where('data_source_id', $ds->id)
            ->where('team_id', $match->home_team_id)
            ->value('external_id');

        // May throw ApiFootballException — caller handles gracefully.
        $response = $this->client->get('fixtures/statistics', ['fixture' => $extId]);

        if (empty($response->response)) {
            if ($markComplete) {
                MatchStatistic::updateOrCreate(
                    ['match_id' => $match->id, 'data_source_id' => $ds->id],
                    ['fetched_at' => now(), 'stats_schema_version' => self::CURRENT_STATS_SCHEMA_VERSION],
                );
            }
            return ['outcome' => 'empty', 'api_calls' => 1];
        }

        $parsed = $this->parseResponse($response->response, $homeExtId);

        if ($parsed === null) {
            Log::warning("api-football-statistics-sync: fixture {$extId} — response present but unparsable");
            return ['outcome' => 'unparsable', 'api_calls' => 1];
        }

        $data = $markComplete
            ? array_merge($parsed, ['fetched_at' => now(), 'stats_schema_version' => self::CURRENT_STATS_SCHEMA_VERSION])
            : $parsed;

        MatchStatistic::updateOrCreate(
            ['match_id' => $match->id, 'data_source_id' => $ds->id],
            $data,
        );

        return ['outcome' => 'synced', 'api_calls' => 1];
    }

    /**
     * Fetch and upsert statistics for all definitive matches in the requested season
     * that are below the current schema version (v1 or null).
     *
     * Completeness gate: rows with stats_schema_version >= CURRENT_STATS_SCHEMA_VERSION
     * are skipped unless $force = true.
     *
     * $force = true bypasses the gate and re-fetches every definitive match in the
     * season regardless of existing version. Use only for schema upgrades or data repair.
     *
     * HTTP failures are caught per-fixture so a single error never blocks remaining matches.
     * Always sets fetched_at = now() and stats_schema_version = CURRENT on success.
     *
     * @return array{status:string,candidates:int,updated:int,unchanged:int,failed:int,api_calls:int,daily_remaining:null}
     */
    public function backfillExtendedHistorical(int $seasonYear, bool $force = false): array
    {
        $ds = $this->dataSource();

        $seasonIds = Season::where('year_start', $seasonYear)->pluck('id');
        if ($seasonIds->isEmpty()) {
            return ['status' => 'no_season_found', 'candidates' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'api_calls' => 0, 'daily_remaining' => null];
        }

        $matchIds = FootballMatch::whereIn('season_id', $seasonIds)
            ->whereIn('status', ApiFootballFixtureSyncService::DEFINITIVE_STATUSES)
            ->pluck('id');

        if ($matchIds->isEmpty()) {
            return ['status' => 'ok', 'candidates' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'api_calls' => 0, 'daily_remaining' => null];
        }

        $extIdByMatchId = MatchExternalId::where('data_source_id', $ds->id)
            ->whereIn('match_id', $matchIds)
            ->pluck('external_id', 'match_id')
            ->all();

        if (empty($extIdByMatchId)) {
            return ['status' => 'ok', 'candidates' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'api_calls' => 0, 'daily_remaining' => null];
        }

        // Pre-load existing stats to apply the version gate efficiently.
        $existingByMatchId = MatchStatistic::where('data_source_id', $ds->id)
            ->whereIn('match_id', array_keys($extIdByMatchId))
            ->get()
            ->keyBy('match_id')
            ->all();

        $matchModels = FootballMatch::whereIn('id', array_keys($extIdByMatchId))
            ->orderByDesc('kickoff_at')
            ->get()
            ->all();

        $candidates = 0;
        $updated    = 0;
        $unchanged  = 0;
        $failed     = 0;
        $apiCalls   = 0;

        foreach ($matchModels as $match) {
            $extId = $extIdByMatchId[$match->id] ?? null;
            if ($extId === null) {
                continue;
            }

            // Version gate: skip rows already at the current schema version unless forced.
            $existingStat = $existingByMatchId[$match->id] ?? null;
            if (!$force
                && $existingStat !== null
                && $existingStat->stats_schema_version !== null
                && $existingStat->stats_schema_version >= self::CURRENT_STATS_SCHEMA_VERSION
            ) {
                $unchanged++;
                continue;
            }

            $candidates++;

            try {
                $result    = $this->fetchAndUpsertStats($match, $extId, markComplete: true);
                $apiCalls += $result['api_calls'];

                if (in_array($result['outcome'], ['synced', 'empty'], true)) {
                    $updated++;
                }
            } catch (ApiFootballException $e) {
                $failed++;
                Log::error("api-football-extended-backfill: fixture {$extId} — {$e->getMessage()}");
            }
        }

        return [
            'status'          => 'ok',
            'candidates'      => $candidates,
            'updated'         => $updated,
            'unchanged'       => $unchanged,
            'failed'          => $failed,
            'api_calls'       => $apiCalls,
            'daily_remaining' => null,
        ];
    }

    /**
     * Attempt late enrichment for v2 rows that are missing any advanced statistic.
     *
     * API-Football adds expected_goals and goals_prevented retroactively. This method
     * targets rows already at the current schema version but missing any of the four
     * advanced columns, re-fetching them according to a configurable timing policy.
     *
     * Candidate criteria:
     *  1. stats_schema_version >= CURRENT (already fully processed, not a backfill gap)
     *  2. Any of: home_expected_goals, away_expected_goals,
     *             home_goals_prevented, away_goals_prevented IS NULL
     *  3. Match kickoff_at >= now() - late_stats_max_age_days
     *  4. Match status IN DEFINITIVE_STATUSES
     *  5. Timing gate (two-phase):
     *     A) Never enrichment-checked (advanced_stats_checked_at IS NULL):
     *        COALESCE(fetched_at, kickoff_at) <= now() - late_stats_initial_delay_days
     *        Using kickoff_at as fallback covers v2 rows where fetched_at was never set
     *        (not re-fetched by normal sync since they are already v2). If both are null
     *        (degenerate row), COALESCE returns NULL → condition fails → excluded.
     *     B) Already enrichment-checked (advanced_stats_checked_at IS NOT NULL):
     *        advanced_stats_checked_at <= now() - late_stats_retry_days
     *
     * @param  int|null  $seasonYear  year_start of the target season; null = current season(s).
     *
     * @return array{status:string,candidates:int,api_calls:int,xg_recovered:int,goals_prevented_recovered:int,still_missing_advanced:int,updated:int,failed:int,skipped:int}
     */
    public function refreshLateStats(?int $seasonYear = null): array
    {
        $ds = $this->dataSource();

        $initialDelayDays   = (int) config('api-football.late_stats_initial_delay_days', 2);
        $retryDays          = (int) config('api-football.late_stats_retry_days', 7);
        $maxAgeDays         = (int) config('api-football.late_stats_max_age_days', 90);
        $initialDelayBefore = now()->subDays($initialDelayDays);
        $retryBefore        = now()->subDays($retryDays);
        $ageCutoff          = now()->subDays($maxAgeDays);

        if ($seasonYear !== null) {
            $seasonIds = Season::where('year_start', $seasonYear)->pluck('id');
        } else {
            $seasonIds = Season::where('is_current', true)->pluck('id');
        }

        if ($seasonIds->isEmpty()) {
            return ['status' => 'no_season_found', 'candidates' => 0, 'api_calls' => 0, 'xg_recovered' => 0, 'goals_prevented_recovered' => 0, 'still_missing_advanced' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0];
        }

        // Definitive matches within the age window for the target seasons.
        $matchIds = FootballMatch::whereIn('season_id', $seasonIds)
            ->whereIn('status', ApiFootballFixtureSyncService::DEFINITIVE_STATUSES)
            ->where('kickoff_at', '>=', $ageCutoff)
            ->pluck('id');

        if ($matchIds->isEmpty()) {
            return ['status' => 'ok', 'candidates' => 0, 'api_calls' => 0, 'xg_recovered' => 0, 'goals_prevented_recovered' => 0, 'still_missing_advanced' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0];
        }

        // External IDs for the candidate matches.
        $extIdByMatchId = MatchExternalId::where('data_source_id', $ds->id)
            ->whereIn('match_id', $matchIds)
            ->pluck('external_id', 'match_id')
            ->all();

        if (empty($extIdByMatchId)) {
            return ['status' => 'ok', 'candidates' => 0, 'api_calls' => 0, 'xg_recovered' => 0, 'goals_prevented_recovered' => 0, 'still_missing_advanced' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0];
        }

        // Narrow to v2 rows missing any advanced stat, past the two-phase timing gate.
        // JOIN with matches to access kickoff_at as COALESCE fallback for fetched_at.
        $candidateMatchIds = MatchStatistic::from('match_statistics')
            ->join('matches', 'match_statistics.match_id', '=', 'matches.id')
            ->where('match_statistics.data_source_id', $ds->id)
            ->whereIn('match_statistics.match_id', array_keys($extIdByMatchId))
            ->where('match_statistics.stats_schema_version', '>=', self::CURRENT_STATS_SCHEMA_VERSION)
            ->where(function ($q) {
                // Any missing advanced stat makes this row a candidate.
                $q->whereNull('match_statistics.home_expected_goals')
                  ->orWhereNull('match_statistics.away_expected_goals')
                  ->orWhereNull('match_statistics.home_goals_prevented')
                  ->orWhereNull('match_statistics.away_goals_prevented');
            })
            ->where(function ($q) use ($initialDelayBefore, $retryBefore) {
                $q->where(function ($inner) use ($initialDelayBefore) {
                    // Never enrichment-checked arm: use COALESCE(fetched_at, kickoff_at) as reference.
                    // Covers v2 rows where fetched_at was never set (normal sync skips v2 rows).
                    // If both fetched_at and kickoff_at are null, COALESCE returns NULL → excluded.
                    $inner->whereNull('match_statistics.advanced_stats_checked_at')
                          ->whereRaw(
                              'COALESCE(match_statistics.fetched_at, matches.kickoff_at) <= ?',
                              [$initialDelayBefore],
                          );
                })->orWhere(function ($inner) use ($retryBefore) {
                    // Already enrichment-checked arm: wait for retry interval.
                    $inner->whereNotNull('match_statistics.advanced_stats_checked_at')
                          ->where('match_statistics.advanced_stats_checked_at', '<=', $retryBefore);
                });
            })
            ->pluck('match_statistics.match_id')
            ->all();

        if (empty($candidateMatchIds)) {
            return ['status' => 'ok', 'candidates' => 0, 'api_calls' => 0, 'xg_recovered' => 0, 'goals_prevented_recovered' => 0, 'still_missing_advanced' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $candidateExtIds = array_intersect_key($extIdByMatchId, array_flip($candidateMatchIds));

        $matchModels = FootballMatch::whereIn('id', array_keys($candidateExtIds))
            ->get()
            ->keyBy('id')
            ->all();

        $candidates   = count($candidateExtIds);
        $apiCalls     = 0;
        $xgRecovered  = 0;
        $gpRecovered  = 0;
        $stillMissing = 0;
        $updated      = 0;
        $failed       = 0;
        $skipped      = 0;

        foreach ($candidateExtIds as $matchId => $extId) {
            $match = $matchModels[$matchId] ?? null;
            if (!$match) {
                $skipped++;
                Log::warning("api-football-late-stats: match {$matchId} not found in pre-load");
                continue;
            }

            try {
                $result    = $this->fetchAndEnrichLateStats($match, $extId);
                $apiCalls += $result['api_calls'];

                if ($result['outcome'] === 'unparsable') {
                    $skipped++;
                } else {
                    // 'synced' or 'empty' — advanced_stats_checked_at was updated
                    $updated++;
                    if ($result['xg_recovered'])              $xgRecovered++;
                    if ($result['goals_prevented_recovered'])  $gpRecovered++;
                    if ($result['still_missing_advanced'])     $stillMissing++;
                }
            } catch (ApiFootballException $e) {
                $failed++;
                Log::error("api-football-late-stats: fixture {$extId} — {$e->getMessage()}");
            }
        }

        return [
            'status'                    => 'ok',
            'candidates'                => $candidates,
            'api_calls'                 => $apiCalls,
            'xg_recovered'              => $xgRecovered,
            'goals_prevented_recovered' => $gpRecovered,
            'still_missing_advanced'    => $stillMissing,
            'updated'                   => $updated,
            'failed'                    => $failed,
            'skipped'                   => $skipped,
        ];
    }

    /**
     * Attempt to retrieve late-arriving advanced statistics for a single v2 match.
     *
     * Semantics:
     *  - HTTP success + payload   → upserts full parsed stats; sets fetched_at and advanced_stats_checked_at
     *  - HTTP success + empty []  → only sets advanced_stats_checked_at; preserves existing data
     *  - Unparsable response      → no DB write (retryable when parser is fixed)
     *  - HTTP failure             → throws ApiFootballException; caller handles (failed++)
     *
     * stats_schema_version is intentionally NOT modified — the schema version has not changed.
     *
     * @return array{outcome:'synced'|'empty'|'unparsable',xg_recovered:bool,goals_prevented_recovered:bool,still_missing_advanced:bool,api_calls:int}
     */
    private function fetchAndEnrichLateStats(FootballMatch $match, string $extId): array
    {
        $ds = $this->dataSource();

        $homeExtId = TeamExternalId::where('data_source_id', $ds->id)
            ->where('team_id', $match->home_team_id)
            ->value('external_id');

        // May throw ApiFootballException — caller catches and increments failed.
        $response = $this->client->get('fixtures/statistics', ['fixture' => $extId]);

        if (empty($response->response)) {
            // Source returned [] for a v2 row: mark attempted so the retry interval is respected.
            // Do NOT wipe existing shots/fouls/passes data from the original fetch.
            MatchStatistic::where('match_id', $match->id)
                ->where('data_source_id', $ds->id)
                ->update(['advanced_stats_checked_at' => now()]);

            return ['outcome' => 'empty', 'xg_recovered' => false, 'goals_prevented_recovered' => false, 'still_missing_advanced' => true, 'api_calls' => 1];
        }

        $parsed = $this->parseResponse($response->response, $homeExtId);

        if ($parsed === null) {
            Log::warning("api-football-late-stats: fixture {$extId} — response present but unparsable");
            return ['outcome' => 'unparsable', 'xg_recovered' => false, 'goals_prevented_recovered' => false, 'still_missing_advanced' => false, 'api_calls' => 1];
        }

        // xG is recovered when BOTH home and away expected_goals are non-null.
        // goals_prevented is recovered when BOTH home and away goals_prevented are non-null.
        // still_missing_advanced = any of the four advanced fields is still null after this fetch.
        $xgRecovered  = $parsed['home_expected_goals'] !== null && $parsed['away_expected_goals'] !== null;
        $gpRecovered  = $parsed['home_goals_prevented'] !== null && $parsed['away_goals_prevented'] !== null;
        $stillMissing = !$xgRecovered || !$gpRecovered;

        MatchStatistic::updateOrCreate(
            ['match_id' => $match->id, 'data_source_id' => $ds->id],
            array_merge($parsed, [
                'fetched_at'                => now(),
                'advanced_stats_checked_at' => now(),
                'stats_schema_version'      => self::CURRENT_STATS_SCHEMA_VERSION,
            ]),
        );

        return ['outcome' => 'synced', 'xg_recovered' => $xgRecovered, 'goals_prevented_recovered' => $gpRecovered, 'still_missing_advanced' => $stillMissing, 'api_calls' => 1];
    }

    /**
     * Parse the two-team statistics response from API-Football.
     * Uses homeExtId to match the home team; falls back to positional (index 0 = home).
     * Returns null if fewer than 2 team entries are present.
     *
     * All API metric keys are documented below; unmapped keys are captured in raw_stats.
     */
    private function parseResponse(array $responseItems, ?string $homeExtId): ?array
    {
        if (count($responseItems) < 2) {
            return null;
        }

        $homeStats = null;
        $awayStats = null;

        if ($homeExtId !== null) {
            foreach ($responseItems as $item) {
                $apiTeamId = (string) ($item['team']['id'] ?? '');
                $indexed   = $this->indexStats($item['statistics'] ?? []);
                if ($apiTeamId === $homeExtId) {
                    $homeStats = $indexed;
                } else {
                    $awayStats = $indexed;
                }
            }
        }

        // Positional fallback when home team external ID is unknown or unmatched
        if ($homeStats === null || $awayStats === null) {
            $homeStats = $this->indexStats($responseItems[0]['statistics'] ?? []);
            $awayStats = $this->indexStats($responseItems[1]['statistics'] ?? []);
        }

        return [
            // --- shots ---
            'home_shots'              => $this->intStat($homeStats, 'Total Shots'),
            'away_shots'              => $this->intStat($awayStats, 'Total Shots'),
            'home_shots_on_target'    => $this->intStat($homeStats, 'Shots on Goal'),
            'away_shots_on_target'    => $this->intStat($awayStats, 'Shots on Goal'),
            'home_shots_off_target'   => $this->intStat($homeStats, 'Shots off Goal'),
            'away_shots_off_target'   => $this->intStat($awayStats, 'Shots off Goal'),
            'home_blocked_shots'      => $this->intStat($homeStats, 'Blocked Shots'),
            'away_blocked_shots'      => $this->intStat($awayStats, 'Blocked Shots'),
            'home_shots_insidebox'    => $this->intStat($homeStats, 'Shots insidebox'),
            'away_shots_insidebox'    => $this->intStat($awayStats, 'Shots insidebox'),
            'home_shots_outsidebox'   => $this->intStat($homeStats, 'Shots outsidebox'),
            'away_shots_outsidebox'   => $this->intStat($awayStats, 'Shots outsidebox'),
            // --- discipline ---
            'home_fouls'              => $this->intStat($homeStats, 'Fouls'),
            'away_fouls'              => $this->intStat($awayStats, 'Fouls'),
            'home_yellow_cards'       => $this->intStat($homeStats, 'Yellow Cards'),
            'away_yellow_cards'       => $this->intStat($awayStats, 'Yellow Cards'),
            'home_red_cards'          => $this->intStat($homeStats, 'Red Cards'),
            'away_red_cards'          => $this->intStat($awayStats, 'Red Cards'),
            // --- set pieces ---
            'home_corners'            => $this->intStat($homeStats, 'Corner Kicks'),
            'away_corners'            => $this->intStat($awayStats, 'Corner Kicks'),
            'home_offsides'           => $this->intStat($homeStats, 'Offsides'),
            'away_offsides'           => $this->intStat($awayStats, 'Offsides'),
            // --- possession & saves ---
            'home_possession'         => $this->percentStat($homeStats, 'Ball Possession'),
            'away_possession'         => $this->percentStat($awayStats, 'Ball Possession'),
            'home_goalkeeper_saves'   => $this->intStat($homeStats, 'Goalkeeper Saves'),
            'away_goalkeeper_saves'   => $this->intStat($awayStats, 'Goalkeeper Saves'),
            // --- passes ---
            'home_passes_total'       => $this->intStat($homeStats, 'Total passes'),
            'away_passes_total'       => $this->intStat($awayStats, 'Total passes'),
            'home_passes_accurate'    => $this->intStat($homeStats, 'Passes accurate'),
            'away_passes_accurate'    => $this->intStat($awayStats, 'Passes accurate'),
            'home_passes_percentage'  => $this->percentStat($homeStats, 'Passes %'),
            'away_passes_percentage'  => $this->percentStat($awayStats, 'Passes %'),
            // --- xG (unmapped by default; stored in raw_stats AND dedicated columns) ---
            'home_expected_goals'     => $this->floatStat($homeStats, 'expected_goals'),
            'away_expected_goals'     => $this->floatStat($awayStats, 'expected_goals'),
            'home_goals_prevented'    => $this->floatStat($homeStats, 'goals_prevented'),
            'away_goals_prevented'    => $this->floatStat($awayStats, 'goals_prevented'),
            // --- raw payload: preserves ALL API keys including unmapped ones ---
            'raw_stats'               => ['home' => $homeStats, 'away' => $awayStats],
        ];
    }

    /** Build a type → value map from the raw statistics array. */
    private function indexStats(array $statistics): array
    {
        $map = [];
        foreach ($statistics as $stat) {
            $type = $stat['type'] ?? '';
            if ($type !== '') {
                $map[$type] = $stat['value'] ?? null;
            }
        }
        return $map;
    }

    /** Cast a stat value to int, returning null for null or non-numeric values. */
    private function intStat(array $stats, string $key): ?int
    {
        $value = $stats[$key] ?? null;
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        return (int) $value;
    }

    /**
     * Parse a decimal/float stat from the API.
     * Handles string "1.08", float 1.43, negative "-1.34", "0.00", null, "", comma decimals.
     * Returns null for null, empty string, or non-numeric values — never throws.
     * Differs from percentStat: no "%" stripping; supports negative values.
     */
    private function floatStat(array $stats, string $key): ?float
    {
        $value = $stats[$key] ?? null;
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

    /**
     * Parse a percentage stat from the API.
     * Handles string "55%" → 55.0 and bare numeric values.
     * Returns null for null, empty string, or unparseable values — never throws.
     */
    private function percentStat(array $stats, string $key): ?float
    {
        $value = $stats[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $numeric = rtrim(trim($value), '%');
            if ($numeric === '' || !is_numeric($numeric)) {
                return null;
            }
            return (float) $numeric;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        return null;
    }
}
