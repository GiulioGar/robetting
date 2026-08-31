<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\Player;
use App\Models\PlayerAbsence;
use App\Models\PlayerExternalId;
use App\Models\Season;
use App\Models\TeamExternalId;
use Illuminate\Support\Facades\Log;

/**
 * Syncs pre-match player absences from API-Football /injuries?fixture={id}.
 *
 * Snapshot semantics:
 *   Each successful fetch replaces the injury snapshot for that fixture+source.
 *   Recovered players (present in old snapshot, absent from new) are deleted.
 *   Only absences from mapped teams are managed; unmapped-team rows are left intact.
 *
 * Sentinel semantics:
 *   injuries_last_attempt_at — set on every attempt, including HTTP errors.
 *                              THIS is the throttle gate field.
 *   injuries_fetched_at      — set only on valid 2xx response (including empty []).
 *                              Represents the timestamp of the last confirmed snapshot.
 *                              NOT used for throttle; for data quality / consumer use.
 *
 * Throttle (applied in syncPending, not in syncMissingHistorical):
 *   Gate field: injuries_last_attempt_at (includes failed attempts).
 *   Using last_attempt ensures HTTP errors count against the quota window —
 *   a failed call is treated identically to a successful one for retry purposes.
 *   kickoff > 48 h away   → max 1 attempt per 24 h
 *   12 h < kickoff ≤ 48 h → max 1 attempt per  6 h
 *   kickoff ≤ 12 h away   → max 1 attempt per  2 h
 *
 * Historical support:
 *   syncMissingHistorical() is provided but historical availability requires real
 *   API coverage validation — this has NOT been verified with live API calls.
 *   An empty response [] for a past fixture does not prove data was recorded;
 *   it only guarantees no false absences are stored, which is safe.
 */
class ApiFootballInjurySyncService
{
    private ?DataSource $ds = null;

    public function __construct(private readonly ApiFootballClient $client) {}

    private function dataSource(): DataSource
    {
        return $this->ds ??= DataSource::where('slug', 'api-football')->firstOrFail();
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Sync injuries for upcoming fixtures within the configured window.
     * Applies per-fixture throttle based on time-to-kickoff.
     * Stops updating a fixture once kickoff_at <= now().
     *
     * @return array{status:string,candidates:int,synced:int,empty:int,skipped_throttle:int,
     *               failed:int,created:int,updated:int,unchanged:int,removed:int,
     *               warnings:int,api_calls:int,daily_remaining:int|null}
     */
    public function syncPending(int $windowDays = 7, ?string $leagueSlug = null): array
    {
        $ds        = $this->dataSource();
        $started   = now();
        $windowEnd = now()->addDays($windowDays);

        $candidateIds = FootballMatch::whereNotIn('status', ApiFootballFixtureSyncService::DEFINITIVE_STATUSES)
            ->whereNotNull('kickoff_at')
            ->where('kickoff_at', '>', now())
            ->where('kickoff_at', '<=', $windowEnd)
            ->when($leagueSlug, fn ($q) => $q->whereHas('competition', fn ($q2) => $q2->where('slug', $leagueSlug)))
            ->pluck('id');

        if ($candidateIds->isEmpty()) {
            return $this->emptyResult('ok', 0);
        }

        $extIdByMatchId = MatchExternalId::where('data_source_id', $ds->id)
            ->whereIn('match_id', $candidateIds)
            ->pluck('external_id', 'match_id')
            ->all();

        if (empty($extIdByMatchId)) {
            Log::warning('api-football-injuries: ' . $candidateIds->count() . ' candidate(s) but none have api-football external IDs');
            return $this->emptyResult('ok', 0);
        }

        $matchModels = FootballMatch::whereIn('id', array_keys($extIdByMatchId))
            ->orderBy('kickoff_at')
            ->get()
            ->keyBy('id')
            ->all();

        $candidates      = 0;
        $synced          = 0;
        $empty           = 0;
        $skippedThrottle = 0;
        $failed          = 0;
        $created         = 0;
        $updated         = 0;
        $unchanged       = 0;
        $removed         = 0;
        $warnings        = 0;
        $apiCalls        = 0;
        $dailyRemaining  = null;

        foreach ($extIdByMatchId as $matchId => $extId) {
            $match = $matchModels[$matchId] ?? null;
            if (!$match) {
                continue;
            }

            $candidates++;

            if (!$this->shouldFetch($match)) {
                $skippedThrottle++;
                continue;
            }

            try {
                $result         = $this->fetchAndUpsertAbsences($match, $extId);
                $apiCalls      += $result['api_calls'];
                $dailyRemaining = $result['daily_remaining'] ?? $dailyRemaining;
                $created       += $result['created'];
                $updated       += $result['updated'];
                $unchanged     += $result['unchanged'];
                $removed       += $result['removed'];
                $warnings      += $result['warnings'];

                match ($result['outcome']) {
                    'synced' => $synced++,
                    'empty'  => $empty++,
                    default  => null,
                };
            } catch (ApiFootballException $e) {
                $failed++;
                Log::error("api-football-injuries: fixture {$extId} — {$e->getMessage()}");
            }
        }

        DataSyncRun::create([
            'data_source_id'  => $ds->id,
            'sync_type'       => 'injury_sync',
            'competition_id'  => null,
            'season_id'       => null,
            'mode'            => 'pending',
            'started_at'      => $started,
            'finished_at'     => now(),
            'status'          => 'ok',
            'created_count'   => $created,
            'updated_count'   => $updated,
            'unchanged_count' => $unchanged,
            'skipped_count'   => $skippedThrottle,
            'warnings_count'  => $warnings,
            'api_calls'       => $apiCalls,
            'daily_remaining' => $dailyRemaining,
            'details'         => ['removed_count' => $removed, 'failed' => $failed],
        ]);

        return [
            'status'           => 'ok',
            'candidates'       => $candidates,
            'synced'           => $synced,
            'empty'            => $empty,
            'skipped_throttle' => $skippedThrottle,
            'failed'           => $failed,
            'created'          => $created,
            'updated'          => $updated,
            'unchanged'        => $unchanged,
            'removed'          => $removed,
            'warnings'         => $warnings,
            'api_calls'        => $apiCalls,
            'daily_remaining'  => $dailyRemaining,
        ];
    }

    /**
     * Backfill injuries for all definitive matches in the target season(s) that have
     * no injuries_fetched_at yet and an api-football external ID.
     *
     * No throttle applied — each fixture is fetched once.
     * On empty API response the match is still marked as fetched (no false absences).
     *
     * IMPORTANT — historical availability has NOT been validated with real API calls.
     * An empty [] response for a past fixture may mean "no injuries recorded" OR
     * "this endpoint had no data for this fixture/season". Do not interpret [] as
     * proof that the API provides reliable coverage for any given historical season.
     * Top-league coverage may be reliable from ≈ 2021/22 onwards, but this must be
     * confirmed via real API testing before relying on it for Prediction Engine inputs.
     *
     * @return array{status:string,candidates:int,synced:int,empty:int,failed:int,
     *               created:int,updated:int,unchanged:int,removed:int,warnings:int,
     *               api_calls:int,daily_remaining:int|null}
     */
    public function syncMissingHistorical(?int $seasonYear = null, ?string $leagueSlug = null): array
    {
        $ds      = $this->dataSource();
        $started = now();

        if ($seasonYear !== null) {
            $seasonIds = Season::where('year_start', $seasonYear)
                ->when($leagueSlug, fn ($q) => $q->whereHas('competition', fn ($q2) => $q2->where('slug', $leagueSlug)))
                ->pluck('id');
            if ($seasonIds->isEmpty()) {
                return array_merge($this->emptyResult('no_season_found', 0), ['status' => 'no_season_found']);
            }
        } else {
            $seasonIds = Season::where('is_current', true)
                ->when($leagueSlug, fn ($q) => $q->whereHas('competition', fn ($q2) => $q2->where('slug', $leagueSlug)))
                ->pluck('id');
            if ($seasonIds->isEmpty()) {
                return array_merge($this->emptyResult('no_current_season', 0), ['status' => 'no_current_season']);
            }
        }

        $matchIds = FootballMatch::whereIn('season_id', $seasonIds)
            ->whereIn('status', ApiFootballFixtureSyncService::DEFINITIVE_STATUSES)
            ->whereNull('injuries_fetched_at')
            ->pluck('id');

        if ($matchIds->isEmpty()) {
            return $this->emptyResult('ok', 0);
        }

        $extIdByMatchId = MatchExternalId::where('data_source_id', $ds->id)
            ->whereIn('match_id', $matchIds)
            ->pluck('external_id', 'match_id')
            ->all();

        if (empty($extIdByMatchId)) {
            return $this->emptyResult('ok', 0);
        }

        $matchModels = FootballMatch::whereIn('id', array_keys($extIdByMatchId))
            ->orderByDesc('kickoff_at')
            ->get()
            ->all();

        $candidates     = 0;
        $synced         = 0;
        $empty          = 0;
        $failed         = 0;
        $created        = 0;
        $updated        = 0;
        $unchanged      = 0;
        $removed        = 0;
        $warnings       = 0;
        $apiCalls       = 0;
        $dailyRemaining = null;

        foreach ($matchModels as $match) {
            $extId = $extIdByMatchId[$match->id] ?? null;
            if ($extId === null) {
                continue;
            }

            $candidates++;

            try {
                $result         = $this->fetchAndUpsertAbsences($match, $extId);
                $apiCalls      += $result['api_calls'];
                $dailyRemaining = $result['daily_remaining'] ?? $dailyRemaining;
                $created       += $result['created'];
                $updated       += $result['updated'];
                $unchanged     += $result['unchanged'];
                $removed       += $result['removed'];
                $warnings      += $result['warnings'];

                match ($result['outcome']) {
                    'synced' => $synced++,
                    'empty'  => $empty++,
                    default  => null,
                };
            } catch (ApiFootballException $e) {
                $failed++;
                Log::error("api-football-injuries-historical: fixture {$extId} — {$e->getMessage()}");
            }
        }

        DataSyncRun::create([
            'data_source_id'  => $ds->id,
            'sync_type'       => 'injury_sync',
            'competition_id'  => null,
            'season_id'       => null,
            'mode'            => 'historical',
            'started_at'      => $started,
            'finished_at'     => now(),
            'status'          => 'ok',
            'created_count'   => $created,
            'updated_count'   => $updated,
            'unchanged_count' => $unchanged,
            'skipped_count'   => 0,
            'warnings_count'  => $warnings,
            'api_calls'       => $apiCalls,
            'daily_remaining' => $dailyRemaining,
            'details'         => ['removed_count' => $removed, 'failed' => $failed],
        ]);

        return [
            'status'          => 'ok',
            'candidates'      => $candidates,
            'synced'          => $synced,
            'empty'           => $empty,
            'failed'          => $failed,
            'created'         => $created,
            'updated'         => $updated,
            'unchanged'       => $unchanged,
            'removed'         => $removed,
            'warnings'        => $warnings,
            'api_calls'       => $apiCalls,
            'daily_remaining' => $dailyRemaining,
        ];
    }

    // =========================================================================
    // Core fetch + snapshot upsert
    // =========================================================================

    /**
     * Fetch /injuries?fixture={extId} and replace the snapshot for this match+source.
     *
     * injuries_last_attempt_at is always set (before the API call).
     * injuries_fetched_at is set only on a valid 2xx response (including empty).
     * HTTP errors propagate as ApiFootballException — caller handles.
     *
     * @return array{outcome:string,api_calls:int,created:int,updated:int,unchanged:int,removed:int,warnings:int,daily_remaining:int|null}
     */
    private function fetchAndUpsertAbsences(FootballMatch $match, string $extId): array
    {
        $ds = $this->dataSource();

        // Team mapping: api_team_ext_id (string) → canonical_team_id
        $teamExtIdMap = TeamExternalId::where('data_source_id', $ds->id)
            ->whereIn('team_id', [$match->home_team_id, $match->away_team_id])
            ->pluck('team_id', 'external_id')
            ->all();

        // Record attempt time BEFORE the API call so it is set even on HTTP failure.
        $match->update(['injuries_last_attempt_at' => now()]);

        // May throw ApiFootballException — injuries_last_attempt_at already set.
        $response = $this->client->get('injuries', ['fixture' => $extId]);

        $items = $response->response;

        // Empty response: API confirmed no injuries for this fixture.
        // Remove all existing absences for this match+source (full recovery).
        if (empty($items)) {
            $removed = PlayerAbsence::where('match_id', $match->id)
                ->where('data_source_id', $ds->id)
                ->delete();

            $match->update(['injuries_fetched_at' => now()]);

            return [
                'outcome'         => 'empty',
                'api_calls'       => 1,
                'created'         => 0,
                'updated'         => 0,
                'unchanged'       => 0,
                'removed'         => (int) $removed,
                'warnings'        => 0,
                'daily_remaining' => $response->requestsRemaining,
            ];
        }

        $created          = 0;
        $updated          = 0;
        $unchanged        = 0;
        $removed          = 0;
        $warnings         = 0;
        $survivingByTeam  = []; // [canonical_team_id => [player_id, ...]]

        foreach ($items as $item) {
            $apiTeamId       = (string) ($item['team']['id'] ?? '');
            $canonicalTeamId = $teamExtIdMap[$apiTeamId] ?? null;

            if ($canonicalTeamId === null) {
                Log::warning("api-football-injuries: fixture {$extId} team {$apiTeamId} not in team_external_ids — skipped");
                $warnings++;
                continue;
            }

            $player = $this->resolveOrCreatePlayer($item['player'] ?? [], $ds->id);
            if ($player === null) {
                $warnings++;
                continue;
            }

            $absence = PlayerAbsence::updateOrCreate(
                [
                    'match_id'       => $match->id,
                    'player_id'      => $player->id,
                    'data_source_id' => $ds->id,
                ],
                [
                    'team_id'      => $canonicalTeamId,
                    'absence_type' => ($item['type'] ?? null) ?: null,
                    'reason'       => ($item['reason'] ?? null) ?: null,
                    'raw_data'     => $item ?: null,
                ],
            );

            if ($absence->wasRecentlyCreated) {
                $created++;
            } elseif ($absence->wasChanged()) {
                $updated++;
            } else {
                $unchanged++;
            }

            $survivingByTeam[$canonicalTeamId][] = $player->id;
        }

        // Remove stale absences only from teams we successfully processed.
        // Unmapped-team rows are left intact (we cannot confirm recovery for them).
        foreach ($survivingByTeam as $canonicalTeamId => $survivingPlayerIds) {
            $removed += (int) PlayerAbsence::where('match_id', $match->id)
                ->where('data_source_id', $ds->id)
                ->where('team_id', $canonicalTeamId)
                ->whereNotIn('player_id', $survivingPlayerIds)
                ->delete();
        }

        // Also remove stale rows for mapped teams that sent NO players in this response
        // (i.e., a team is mapped but appeared with zero injury entries → all recovered).
        $mappedTeamIds = array_values($teamExtIdMap);
        foreach ($mappedTeamIds as $mappedTeamId) {
            if (!isset($survivingByTeam[$mappedTeamId])) {
                $removed += (int) PlayerAbsence::where('match_id', $match->id)
                    ->where('data_source_id', $ds->id)
                    ->where('team_id', $mappedTeamId)
                    ->delete();
            }
        }

        $match->update(['injuries_fetched_at' => now()]);

        return [
            'outcome'         => 'synced',
            'api_calls'       => 1,
            'created'         => $created,
            'updated'         => $updated,
            'unchanged'       => $unchanged,
            'removed'         => $removed,
            'warnings'        => $warnings,
            'daily_remaining' => $response->requestsRemaining,
        ];
    }

    // =========================================================================
    // Throttle
    // =========================================================================

    private function shouldFetch(FootballMatch $match): bool
    {
        if (!$match->kickoff_at?->isFuture()) {
            return false;
        }

        // Never attempted → always fetch
        if ($match->injuries_last_attempt_at === null) {
            return true;
        }

        $hoursToKickoff    = (int) now()->diffInHours($match->kickoff_at);
        $minHours          = $this->throttleHours($hoursToKickoff);
        $hoursSinceAttempt = (int) $match->injuries_last_attempt_at->diffInHours(now());

        return $hoursSinceAttempt >= $minHours;
    }

    private function throttleHours(int $hoursToKickoff): int
    {
        return match (true) {
            $hoursToKickoff > 48 => 24,
            $hoursToKickoff > 12 => 6,
            default              => 2,
        };
    }

    // =========================================================================
    // Player resolution
    // =========================================================================

    private function resolveOrCreatePlayer(array $playerData, int $dsId): ?Player
    {
        $apiId = (string) ($playerData['id'] ?? '');
        if ($apiId === '') {
            return null;
        }

        $extRecord = PlayerExternalId::where('data_source_id', $dsId)
            ->where('external_id', $apiId)
            ->with('player')
            ->first();

        if ($extRecord !== null) {
            return $extRecord->player;
        }

        $name   = ($playerData['name'] ?? null) ?: "Player #{$apiId}";
        $player = Player::create(['name' => $name]);

        PlayerExternalId::create([
            'player_id'      => $player->id,
            'data_source_id' => $dsId,
            'external_id'    => $apiId,
            'external_name'  => $name,
        ]);

        return $player;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function emptyResult(string $status, int $candidates): array
    {
        return [
            'status'           => $status,
            'candidates'       => $candidates,
            'synced'           => 0,
            'empty'            => 0,
            'skipped_throttle' => 0,
            'failed'           => 0,
            'created'          => 0,
            'updated'          => 0,
            'unchanged'        => 0,
            'removed'          => 0,
            'warnings'         => 0,
            'api_calls'        => 0,
            'daily_remaining'  => null,
        ];
    }
}
