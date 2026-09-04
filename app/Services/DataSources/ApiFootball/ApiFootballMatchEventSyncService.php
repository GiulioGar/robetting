<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\MatchExternalId;
use App\Models\Season;
use App\Models\TeamExternalId;
use Illuminate\Support\Facades\Log;

class ApiFootballMatchEventSyncService
{
    private ?DataSource $ds = null;

    public function __construct(private readonly ApiFootballClient $client) {}

    private function dataSource(): DataSource
    {
        return $this->ds ??= DataSource::where('slug', 'api-football')->firstOrFail();
    }

    /**
     * Fetch and upsert events for a single definitive match.
     * Skips the API call if events_fetched_at is already set.
     * Sets events_fetched_at on any valid 2xx response.
     * Throws ApiFootballException on HTTP failure so the caller can log and retry.
     *
     * @return array{outcome:string,api_calls:int,events_count:int}
     */
    public function syncSingle(FootballMatch $match, string $extId): array
    {
        if ($match->events_fetched_at !== null) {
            return ['outcome' => 'skipped_complete', 'api_calls' => 0, 'events_count' => 0];
        }

        return $this->fetchAndUpsertEvents($match, $extId, markComplete: true);
    }

    /**
     * Force-fetch events for a single match, ignoring events_fetched_at.
     * Used by the admin manual tool so re-triggering always refreshes scorers.
     * Sets events_fetched_at on any valid 2xx response.
     * Throws ApiFootballException on HTTP failure so the caller can log and retry.
     *
     * @return array{outcome:string,api_calls:int,events_count:int}
     */
    public function forceSyncSingle(FootballMatch $match, string $extId): array
    {
        return $this->fetchAndUpsertEvents($match, $extId, markComplete: true);
    }

    /**
     * Fetch and upsert events for a single live match.
     * Always fetches — no sentinel guard. Never sets events_fetched_at.
     * Throws ApiFootballException on HTTP failure so the caller can log and retry.
     *
     * @return array{outcome:string,api_calls:int,events_count:int}
     */
    public function syncLiveSingle(FootballMatch $match, string $extId): array
    {
        return $this->fetchAndUpsertEvents($match, $extId, markComplete: false);
    }

    /**
     * Backfill events for all definitive matches in the target season that have an
     * API-Football external ID but have never had events fetched (events_fetched_at IS NULL).
     *
     * @param  int|null  $seasonYear  year_start of the target season; null = current season(s).
     *
     * Retryability:
     *  - HTTP failure (ApiFootballException) → failed++, events_fetched_at unchanged → retryable.
     *  - Unparsable response (non-empty but no valid events) → events_fetched_at unchanged → retryable.
     *  - Empty [] response → events_fetched_at SET (permanent) → not retried.
     *
     * Ordering: kickoff_at DESC. No hard limit — caller responsible for timeout (set_time_limit(0)).
     *
     * @return array{status:string,candidates:int,synced:int,empty:int,failed:int,api_calls:int,daily_remaining:null}
     */
    public function syncMissingHistorical(?int $seasonYear = null): array
    {
        $ds = $this->dataSource();

        if ($seasonYear !== null) {
            $seasonIds = Season::where('year_start', $seasonYear)->pluck('id');
            if ($seasonIds->isEmpty()) {
                return ['status' => 'no_season_found', 'candidates' => 0, 'synced' => 0, 'empty' => 0, 'failed' => 0, 'api_calls' => 0, 'daily_remaining' => null];
            }
        } else {
            $seasonIds = Season::where('is_current', true)->pluck('id');
            if ($seasonIds->isEmpty()) {
                return ['status' => 'no_current_season', 'candidates' => 0, 'synced' => 0, 'empty' => 0, 'failed' => 0, 'api_calls' => 0, 'daily_remaining' => null];
            }
        }

        $matchIds = FootballMatch::whereIn('season_id', $seasonIds)
            ->whereIn('status', ApiFootballFixtureSyncService::DEFINITIVE_STATUSES)
            ->whereNull('events_fetched_at')
            ->pluck('id');

        if ($matchIds->isEmpty()) {
            return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'empty' => 0, 'failed' => 0, 'api_calls' => 0, 'daily_remaining' => null];
        }

        $extIdByMatchId = MatchExternalId::where('data_source_id', $ds->id)
            ->whereIn('match_id', $matchIds)
            ->pluck('external_id', 'match_id')
            ->all();

        if (empty($extIdByMatchId)) {
            return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'empty' => 0, 'failed' => 0, 'api_calls' => 0, 'daily_remaining' => null];
        }

        $matchModels = FootballMatch::whereIn('id', array_keys($extIdByMatchId))
            ->orderByDesc('kickoff_at')
            ->get()
            ->all();

        $candidates = 0;
        $synced     = 0;
        $empty      = 0;
        $failed     = 0;
        $apiCalls   = 0;

        foreach ($matchModels as $match) {
            $extId = $extIdByMatchId[$match->id] ?? null;
            if ($extId === null) {
                continue;
            }

            $candidates++;

            try {
                $result    = $this->syncSingle($match, $extId);
                $apiCalls += $result['api_calls'];

                match ($result['outcome']) {
                    'synced' => $synced++,
                    'empty'  => $empty++,
                    default  => null,
                };
            } catch (ApiFootballException $e) {
                $failed++;
                Log::error("api-football-historical-events: fixture {$extId} — {$e->getMessage()}");
            }
        }

        return [
            'status'          => 'ok',
            'candidates'      => $candidates,
            'synced'          => $synced,
            'empty'           => $empty,
            'failed'          => $failed,
            'api_calls'       => $apiCalls,
            'daily_remaining' => null,
        ];
    }

    /**
     * Fetch events for all currently-live matches with an API-Football external ID.
     * Never sets events_fetched_at. HTTP failures are caught and logged as warnings
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
            Log::warning('api-football-live-events: ' . $liveIds->count() . ' live match(es) but none have api-football external IDs');
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
                Log::warning("api-football-live-events: fixture {$extId} — {$e->getMessage()}");
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
     * Fetch events for all definitive matches past the grace period that have no events_fetched_at yet.
     *
     * Grace period anchor: definitive_at (not kickoff_at), so ET/penalties matches are handled correctly.
     * Legacy fallback for rows where definitive_at IS NULL: kickoff_at + 90 + grace.
     *
     * @return array{status:string,candidates:int,synced:int,failed:int,api_calls:int}
     */
    public function syncPending(int $gracePeriodMinutes = 10): array
    {
        $ds           = $this->dataSource();
        $cutoff       = now()->subMinutes($gracePeriodMinutes);
        $legacyCutoff = now()->subMinutes(90 + $gracePeriodMinutes);

        $definitiveIds = FootballMatch::whereIn('status', ApiFootballFixtureSyncService::DEFINITIVE_STATUSES)
            ->whereNull('events_fetched_at')
            ->where(function ($q) use ($cutoff, $legacyCutoff) {
                $q->where('definitive_at', '<=', $cutoff)
                  ->orWhere(function ($q2) use ($legacyCutoff) {
                      $q2->whereNull('definitive_at')
                         ->where('kickoff_at', '<=', $legacyCutoff);
                  });
            })
            ->pluck('id');

        if ($definitiveIds->isEmpty()) {
            return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'api_calls' => 0];
        }

        $extIdByMatchId = MatchExternalId::where('data_source_id', $ds->id)
            ->whereIn('match_id', $definitiveIds)
            ->pluck('external_id', 'match_id')
            ->all();

        if (empty($extIdByMatchId)) {
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
                $result    = $this->syncSingle($match, $extId);
                $apiCalls += $result['api_calls'];
                if (in_array($result['outcome'], ['synced', 'empty'], true)) {
                    $synced++;
                }
            } catch (ApiFootballException $e) {
                $failed++;
                Log::error("api-football-events-sync: fixture {$extId} — {$e->getMessage()}");
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
     * Core fetch + upsert. Called by both post-match and live flows.
     * $markComplete=true → sets events_fetched_at on success (post-match).
     * $markComplete=false → never touches events_fetched_at (live).
     *
     * @return array{outcome:string,api_calls:int,events_count:int}
     */
    private function fetchAndUpsertEvents(FootballMatch $match, string $extId, bool $markComplete): array
    {
        $ds = $this->dataSource();

        $teamExtIdMap = $this->buildTeamExtIdMap($match, $ds);

        // May throw ApiFootballException — caller handles.
        $response = $this->client->get('fixtures/events', ['fixture' => $extId]);

        if (empty($response->response)) {
            if ($markComplete) {
                $match->update(['events_fetched_at' => now()]);
            }
            return ['outcome' => 'empty', 'api_calls' => 1, 'events_count' => 0];
        }

        $events = $this->parseEvents($response->response, $match->id, $ds->id, $teamExtIdMap);

        // Non-empty API response that produced zero parseable events: completely unexpected structure.
        // Do not set events_fetched_at so the next cycle can retry (or a parser fix can recover it).
        if (empty($events)) {
            Log::warning("api-football-events-sync: fixture {$extId} — non-empty response produced no valid events");
            return ['outcome' => 'unparsable', 'api_calls' => 1, 'events_count' => 0];
        }

        foreach ($events as $event) {
            MatchEvent::updateOrCreate(
                [
                    'match_id'         => $match->id,
                    'data_source_id'   => $ds->id,
                    'source_event_key' => $event['source_event_key'],
                ],
                $event,
            );
        }

        if ($markComplete) {
            $match->update(['events_fetched_at' => now()]);
        }

        return ['outcome' => 'synced', 'api_calls' => 1, 'events_count' => count($events)];
    }

    /** [api_external_id_string => canonical_team_id] for the match's home and away teams. */
    private function buildTeamExtIdMap(FootballMatch $match, DataSource $ds): array
    {
        return TeamExternalId::where('data_source_id', $ds->id)
            ->whereIn('team_id', [$match->home_team_id, $match->away_team_id])
            ->pluck('team_id', 'external_id')
            ->all();
    }

    /**
     * Convert the API response array into a list of MatchEvent attribute arrays.
     * Events missing required fields (elapsed, team.id, type) are skipped with a warning.
     * Returns [] if all items are unparseable.
     */
    private function parseEvents(
        array $items,
        int $matchId,
        int $dataSourceId,
        array $teamExtIdMap,
    ): array {
        $events = [];

        foreach ($items as $item) {
            $sourceKey = $this->buildSourceKey($item);

            if ($sourceKey === '') {
                Log::warning(
                    "api-football-events-sync: match {$matchId} — event missing required fields, skipped: "
                    . json_encode($item)
                );
                continue;
            }

            $elapsed    = isset($item['time']['elapsed']) ? (int) $item['time']['elapsed'] : null;
            $extra      = (isset($item['time']['extra']) && $item['time']['extra'] !== null)
                          ? (int) $item['time']['extra']
                          : null;
            $type       = (string) ($item['type']         ?? '');
            $detail     = (string) ($item['detail']       ?? '');
            $apiTeamId  = $item['team']['id']              ?? null;
            $playerId   = $item['player']['id']            ?? null;
            $playerName = $item['player']['name']          ?? null;
            $assistId   = $item['assist']['id']            ?? null;
            $assistName = $item['assist']['name']          ?? null;

            $canonicalTeamId = ($apiTeamId !== null)
                ? ($teamExtIdMap[(string) $apiTeamId] ?? null)
                : null;

            $detailArr = [];
            if ($type !== '') {
                $detailArr['api_type'] = $type;
            }
            if ($detail !== '') {
                $detailArr['api_detail'] = $detail;
            }
            $comments = $item['comments'] ?? null;
            if ($comments !== null && $comments !== '') {
                $detailArr['comments'] = $comments;
            }

            $events[] = [
                'match_id'                   => $matchId,
                'data_source_id'             => $dataSourceId,
                'event_type'                 => $this->mapEventType($type, $detail),
                'minute'                     => $elapsed,
                'minute_label'               => $elapsed !== null ? $this->buildMinuteLabel($elapsed, $extra) : null,
                'team_id'                    => $canonicalTeamId,
                'player_external_id'         => $playerId !== null ? (string) $playerId : null,
                'player_name'                => $playerName ?: null,
                'related_player_external_id' => $assistId !== null ? (string) $assistId : null,
                'related_player_name'        => $assistName ?: null,
                'detail'                     => !empty($detailArr) ? $detailArr : null,
                'source_event_key'           => $sourceKey,
            ];
        }

        return $events;
    }

    /**
     * Build the deterministic idempotency key for a single event item.
     * Format: {elapsed}_{extra|0}_{api_team_id}_{type}_{detail}_{player_id|player_name}
     * Returns '' if elapsed, team.id, or type is absent (event cannot be identified).
     */
    private function buildSourceKey(array $item): string
    {
        $elapsed    = $item['time']['elapsed'] ?? null;
        $extra      = $item['time']['extra']   ?? null;
        $apiTeamId  = $item['team']['id']      ?? null;
        $type       = $item['type']            ?? null;
        $detail     = $item['detail']          ?? '';
        $playerId   = $item['player']['id']    ?? null;
        $playerName = $item['player']['name']  ?? null;

        if ($elapsed === null || $apiTeamId === null || $type === null) {
            return '';
        }

        $playerIdentifier = $playerId !== null
            ? (string) $playerId
            : (string) ($playerName ?? '');

        return implode('_', [
            (int) $elapsed,
            (int) ($extra ?? 0),
            (int) $apiTeamId,
            $type,
            (string) $detail,
            $playerIdentifier,
        ]);
    }

    private function mapEventType(string $type, string $detail): string
    {
        return match (true) {
            $type === 'Goal' && $detail === 'Normal Goal'     => 'goal',
            $type === 'Goal' && $detail === 'Own Goal'        => 'own_goal',
            $type === 'Goal' && $detail === 'Penalty'         => 'goal',
            $type === 'Goal' && $detail === 'Missed Penalty'  => 'missed_penalty',
            $type === 'Card' && $detail === 'Yellow Card'     => 'yellow_card',
            $type === 'Card' && $detail === 'Red Card'        => 'red_card',
            $type === 'Card' && $detail === 'Yellow Red Card' => 'yellow_red_card',
            $type === 'subst'                                 => 'substitution',
            $type === 'Var'                                   => 'var',
            default => strtolower(preg_replace('/[^a-z0-9]+/i', '_', $type)),
        };
    }

    private function buildMinuteLabel(int $elapsed, ?int $extra): string
    {
        return ($extra !== null && $extra > 0)
            ? "{$elapsed}+{$extra}"
            : (string) $elapsed;
    }
}
