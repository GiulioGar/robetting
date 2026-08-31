<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\MatchPlayerStatistic;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\Season;
use App\Models\TeamExternalId;
use Illuminate\Support\Facades\Log;

class ApiFootballMatchPlayerStatisticsSyncService
{
    private ?DataSource $ds = null;

    public function __construct(private readonly ApiFootballClient $client) {}

    private function dataSource(): DataSource
    {
        return $this->ds ??= DataSource::where('slug', 'api-football')->firstOrFail();
    }

    // =========================================================================
    // Public API — mirroring ApiFootballMatchEventSyncService conventions
    // =========================================================================

    /**
     * Fetch player statistics for a single definitive match.
     * Skips the API call if player_stats_fetched_at is already set.
     * Sets player_stats_fetched_at on any valid 2xx response.
     * Throws ApiFootballException on HTTP failure.
     *
     * @return array{outcome:string,api_calls:int,players_count:int}
     */
    public function syncSingle(FootballMatch $match, string $extId): array
    {
        if ($match->player_stats_fetched_at !== null) {
            return ['outcome' => 'skipped_complete', 'api_calls' => 0, 'players_count' => 0];
        }

        return $this->fetchAndUpsertPlayerStats($match, $extId, markComplete: true);
    }

    /**
     * Fetch and upsert player statistics for a single live match.
     * Always fetches — no sentinel guard. Never sets player_stats_fetched_at.
     * Throws ApiFootballException on HTTP failure.
     *
     * @return array{outcome:string,api_calls:int,players_count:int}
     */
    public function syncLiveSingle(FootballMatch $match, string $extId): array
    {
        return $this->fetchAndUpsertPlayerStats($match, $extId, markComplete: false);
    }

    /**
     * Fetch player statistics for all currently-live matches with an api-football external ID.
     * Never sets player_stats_fetched_at. HTTP failures are caught per-match.
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
            Log::warning('api-football-live-player-stats: ' . $liveIds->count() . ' live match(es) but none have api-football external IDs');
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
                Log::warning("api-football-live-player-stats: fixture {$extId} — {$e->getMessage()}");
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
     * Fetch player statistics for definitive matches past the grace period that have no
     * player_stats_fetched_at yet.
     *
     * Grace anchor: definitive_at (not kickoff_at), so extra-time/penalties matches are safe.
     * Legacy fallback: kickoff_at + 90 + grace for rows where definitive_at IS NULL.
     *
     * @return array{status:string,candidates:int,synced:int,failed:int,api_calls:int}
     */
    public function syncPending(int $gracePeriodMinutes = 10): array
    {
        $ds           = $this->dataSource();
        $cutoff       = now()->subMinutes($gracePeriodMinutes);
        $legacyCutoff = now()->subMinutes(90 + $gracePeriodMinutes);

        $definitiveIds = FootballMatch::whereIn('status', ApiFootballFixtureSyncService::DEFINITIVE_STATUSES)
            ->whereNull('player_stats_fetched_at')
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
                Log::error("api-football-pending-player-stats: fixture {$extId} — {$e->getMessage()}");
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
     * Backfill player statistics for all definitive matches in the target season that have an
     * api-football external ID but player_stats_fetched_at IS NULL.
     *
     * @param  int|null  $seasonYear  year_start of target season; null = current season(s).
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
            ->whereNull('player_stats_fetched_at')
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
                Log::error("api-football-historical-player-stats: fixture {$extId} — {$e->getMessage()}");
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

    // =========================================================================
    // Core fetch + upsert
    // =========================================================================

    /**
     * Fetch /fixtures/players for one match and upsert all player rows.
     *
     * Sentinel semantics:
     *   - HTTP error          → no sentinel (caller re-raises, retry allowed)
     *   - Empty response []   → sentinel set (permanent: source confirmed no data)
     *   - At least one team mapped → sentinel set; unmapped teams warned and skipped
     *   - ALL teams unmapped  → sentinel NOT set (retry after fixing team external IDs)
     *
     * @return array{outcome:string,api_calls:int,players_count:int}
     */
    private function fetchAndUpsertPlayerStats(FootballMatch $match, string $extId, bool $markComplete): array
    {
        $ds = $this->dataSource();

        // [api_team_ext_id_string => canonical_team_id]
        $teamExtIdMap = TeamExternalId::where('data_source_id', $ds->id)
            ->whereIn('team_id', [$match->home_team_id, $match->away_team_id])
            ->pluck('team_id', 'external_id')
            ->all();

        // May throw ApiFootballException — caller handles.
        $response = $this->client->get('fixtures/players', ['fixture' => $extId]);

        if (empty($response->response)) {
            if ($markComplete) {
                $match->update(['player_stats_fetched_at' => now()]);
            }
            return ['outcome' => 'empty', 'api_calls' => 1, 'players_count' => 0];
        }

        $totalPlayers    = 0;
        $anyTeamMapped   = false;

        foreach ($response->response as $teamBlock) {
            $apiTeamId       = (string) ($teamBlock['team']['id'] ?? '');
            $canonicalTeamId = $teamExtIdMap[$apiTeamId] ?? null;

            if ($canonicalTeamId === null) {
                Log::warning("api-football-player-stats: fixture {$extId} team {$apiTeamId} not in team_external_ids — skipped");
                continue;
            }

            $anyTeamMapped = true;

            foreach ($teamBlock['players'] ?? [] as $playerItem) {
                $player = $this->resolveOrCreatePlayer($playerItem['player'] ?? [], $ds->id);
                if ($player === null) {
                    continue;
                }

                $stats  = $playerItem['statistics'][0] ?? [];
                $parsed = $this->parsePlayerStats($stats);

                MatchPlayerStatistic::updateOrCreate(
                    [
                        'match_id'       => $match->id,
                        'player_id'      => $player->id,
                        'data_source_id' => $ds->id,
                    ],
                    array_merge($parsed, ['team_id' => $canonicalTeamId]),
                );

                $totalPlayers++;
            }
        }

        if (!$anyTeamMapped) {
            // All team entries in the response are unmapped — do not set sentinel.
            Log::warning("api-football-player-stats: fixture {$extId} — no team mapped, sentinel not set");
            return ['outcome' => 'unparsable', 'api_calls' => 1, 'players_count' => 0];
        }

        if ($markComplete) {
            $match->update(['player_stats_fetched_at' => now()]);
        }

        return ['outcome' => 'synced', 'api_calls' => 1, 'players_count' => $totalPlayers];
    }

    // =========================================================================
    // Player resolution
    // =========================================================================

    /**
     * Resolve a canonical Player from the api-football player object.
     * If the player is not yet in the database (Block B sync not run yet),
     * create a minimal record so statistics are not lost.
     * No extra API calls are made.
     */
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

        // Minimal creation — master data (birth_date, height, etc.) left NULL.
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
    // Parser
    // =========================================================================

    private function parsePlayerStats(array $stats): array
    {
        $games    = $stats['games']    ?? [];
        $shots    = $stats['shots']    ?? [];
        $goals    = $stats['goals']    ?? [];
        $passes   = $stats['passes']   ?? [];
        $tackles  = $stats['tackles']  ?? [];
        $duels    = $stats['duels']    ?? [];
        $dribbles = $stats['dribbles'] ?? [];
        $fouls    = $stats['fouls']    ?? [];
        $cards    = $stats['cards']    ?? [];
        $penalty  = $stats['penalty']  ?? [];

        return [
            // utilization
            'games_minutes'         => $this->intStat($games['minutes']   ?? null),
            'games_number'          => $this->intStat($games['number']    ?? null),
            'games_position'        => ($games['position'] ?? null) ?: null,
            'games_rating'          => $this->parseRating($games['rating']     ?? null),
            'games_captain'         => (bool) ($games['captain']              ?? false),
            'games_substitute'      => (bool) ($games['substitute']           ?? false),

            // shots — API key is 'on' (not 'on_target')
            'shots_total'           => $this->intStat($shots['total'] ?? null),
            'shots_on_target'       => $this->intStat($shots['on']    ?? null),

            // goals — API key is 'total' (not 'scored')
            'goals_scored'          => $this->intStat($goals['total']    ?? null),
            'goals_conceded'        => $this->intStat($goals['conceded'] ?? null),
            'goals_assists'         => $this->intStat($goals['assists']  ?? null),
            'goals_saves'           => $this->intStat($goals['saves']    ?? null),

            // passes — accuracy returned as integer string (e.g. "87"), not percentage
            'passes_total'          => $this->intStat($passes['total'] ?? null),
            'passes_key'            => $this->intStat($passes['key']   ?? null),
            'passes_accuracy'       => $this->parseAccuracy($passes['accuracy'] ?? null),

            // tackles
            'tackles_total'         => $this->intStat($tackles['total']         ?? null),
            'tackles_blocks'        => $this->intStat($tackles['blocks']        ?? null),
            'tackles_interceptions' => $this->intStat($tackles['interceptions'] ?? null),

            // duels
            'duels_total'           => $this->intStat($duels['total'] ?? null),
            'duels_won'             => $this->intStat($duels['won']   ?? null),

            // dribbling
            'dribbles_attempts'     => $this->intStat($dribbles['attempts'] ?? null),
            'dribbles_success'      => $this->intStat($dribbles['success']  ?? null),
            'dribbles_past'         => $this->intStat($dribbles['past']     ?? null),

            // fouls
            'fouls_drawn'           => $this->intStat($fouls['drawn']     ?? null),
            'fouls_committed'       => $this->intStat($fouls['committed'] ?? null),

            // discipline
            'cards_yellow'          => $this->intStat($cards['yellow'] ?? null),
            'cards_red'             => $this->intStat($cards['red']    ?? null),

            // penalties — API uses 'commited' (one t) for the committed field
            'penalty_won'           => $this->intStat($penalty['won']      ?? null),
            'penalty_committed'     => $this->intStat($penalty['commited'] ?? null),
            'penalty_scored'        => $this->intStat($penalty['scored']   ?? null),
            'penalty_missed'        => $this->intStat($penalty['missed']   ?? null),
            'penalty_saved'         => $this->intStat($penalty['saved']    ?? null),

            // raw payload — full statistics block for this player, including unmapped fields
            'raw_stats'             => $stats ?: null,
        ];
    }

    /**
     * Parse games.rating: "7.5" → 7.50 decimal. null / non-numeric → null.
     */
    private function parseRating(mixed $raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw)) {
            if (!is_numeric($raw)) {
                return null;
            }
            return round((float) $raw, 2);
        }
        if (is_numeric($raw)) {
            return round((float) $raw, 2);
        }
        return null;
    }

    /**
     * Parse passes.accuracy: API returns integer string ("87") or bare int.
     * Also handles "87%" for forward compatibility. null / invalid → null.
     */
    private function parseAccuracy(mixed $raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw)) {
            $cleaned = rtrim(trim($raw), '%');
            if ($cleaned === '' || !is_numeric($cleaned)) {
                return null;
            }
            return (float) $cleaned;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }
        return null;
    }

    /**
     * Cast a nullable scalar to int. Returns null for null or non-numeric values.
     * Preserves 0 as 0 — never coerces null to 0.
     */
    private function intStat(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (int) $value;
    }
}
