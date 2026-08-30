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
    private ?DataSource $ds = null;

    public function __construct(private readonly ApiFootballClient $client) {}

    private function dataSource(): DataSource
    {
        return $this->ds ??= DataSource::where('slug', 'api-football')->firstOrFail();
    }

    /**
     * Fetch statistics for all definitive matches that are absent or incomplete.
     * Completeness: row exists AND home_shots IS NOT NULL.
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

        // Completeness: row exists AND fetched_at IS NOT NULL.
        // fetched_at is set on any successful HTTP response (even empty/partial),
        // so a fetched row is never re-fetched regardless of which metric values are null.
        $candidates = [];
        $unchanged  = 0;

        foreach ($extIdByMatchId as $matchId => $extId) {
            $stat = $existingStats[$matchId] ?? null;
            if ($stat !== null && $stat->fetched_at !== null) {
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
                        // Mark as fetched so we never loop on it again.
                        MatchStatistic::updateOrCreate(
                            ['match_id' => $matchId, 'data_source_id' => $ds->id],
                            ['fetched_at' => $fetchedAt],
                        );
                        $skipped++;
                        $warnings[] = "fixture {$extId}: empty statistics response";
                        continue;
                    }

                    $parsed = $this->parseResponse($response->response, $homeExtId);

                    if ($parsed === null) {
                        // Non-empty response but home/away identification failed (unexpected format).
                        // Do NOT set fetched_at: the data was present but unreadable;
                        // a future sync or parser fix should be able to recover it.
                        $skipped++;
                        $warnings[] = "fixture {$extId}: could not map home/away stats — will retry on next sync";
                        Log::warning("api-football-statistics-sync: fixture {$extId} — response present but unparsable");
                        continue;
                    }

                    $existing = $existingStats[$matchId] ?? null;

                    MatchStatistic::updateOrCreate(
                        ['match_id' => $matchId, 'data_source_id' => $ds->id],
                        array_merge($parsed, ['fetched_at' => $fetchedAt]),
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
     * Skips the API call if fetched_at is already set (already complete).
     * Sets fetched_at on any valid 2xx response.
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

        if ($existing !== null && $existing->fetched_at !== null) {
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
     * API-Football external ID but have no statistics yet (absent row OR fetched_at IS NULL).
     *
     * @param  int|null  $seasonYear  year_start of the target season; null = current season(s).
     *
     * Candidacy: absent MatchStatistic row OR row with fetched_at IS NULL.
     * Resolved (excluded): row with fetched_at IS NOT NULL — regardless of which metrics are null.
     *
     * Retryability:
     *  - HTTP failure (ApiFootballException) → failed++, fetched_at unchanged → retryable.
     *  - Unparsable response → fetched_at unchanged → retryable.
     *  - Empty [] response → fetched_at SET (permanent, source confirmed no data) → not retried.
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
            if ($stat !== null && $stat->fetched_at !== null) {
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

        // Exclude matches that already have fetched_at — these are permanently complete.
        $alreadyFetched = MatchStatistic::where('data_source_id', $ds->id)
            ->whereIn('match_id', array_keys($extIdByMatchId))
            ->whereNotNull('fetched_at')
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
            if (isset($alreadyFetched[$matchId])) {
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
     * $markComplete=true → sets fetched_at on success (post-match flow).
     * $markComplete=false → never touches fetched_at (live flow).
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
                    ['fetched_at' => now()],
                );
            }
            return ['outcome' => 'empty', 'api_calls' => 1];
        }

        $parsed = $this->parseResponse($response->response, $homeExtId);

        if ($parsed === null) {
            Log::warning("api-football-statistics-sync: fixture {$extId} — response present but unparsable");
            return ['outcome' => 'unparsable', 'api_calls' => 1];
        }

        $data = $markComplete ? array_merge($parsed, ['fetched_at' => now()]) : $parsed;

        MatchStatistic::updateOrCreate(
            ['match_id' => $match->id, 'data_source_id' => $ds->id],
            $data,
        );

        return ['outcome' => 'synced', 'api_calls' => 1];
    }

    /**
     * Parse the two-team statistics response from API-Football.
     * Uses homeExtId to match the home team; falls back to positional (index 0 = home).
     * Returns null if fewer than 2 team entries are present.
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
            'home_shots'           => $this->intStat($homeStats, 'Total Shots'),
            'away_shots'           => $this->intStat($awayStats, 'Total Shots'),
            'home_shots_on_target' => $this->intStat($homeStats, 'Shots on Goal'),
            'away_shots_on_target' => $this->intStat($awayStats, 'Shots on Goal'),
            'home_fouls'           => $this->intStat($homeStats, 'Fouls'),
            'away_fouls'           => $this->intStat($awayStats, 'Fouls'),
            'home_corners'         => $this->intStat($homeStats, 'Corner Kicks'),
            'away_corners'         => $this->intStat($awayStats, 'Corner Kicks'),
            'home_yellow_cards'    => $this->intStat($homeStats, 'Yellow Cards'),
            'away_yellow_cards'    => $this->intStat($awayStats, 'Yellow Cards'),
            'home_red_cards'       => $this->intStat($homeStats, 'Red Cards'),
            'away_red_cards'       => $this->intStat($awayStats, 'Red Cards'),
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
}
