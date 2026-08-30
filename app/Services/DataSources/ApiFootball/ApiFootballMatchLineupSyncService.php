<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\MatchLineup;
use App\Models\MatchLineupPlayer;
use App\Models\TeamExternalId;
use Illuminate\Support\Facades\Log;

class ApiFootballMatchLineupSyncService
{
    private ?DataSource $ds = null;

    public function __construct(private readonly ApiFootballClient $client) {}

    private function dataSource(): DataSource
    {
        return $this->ds ??= DataSource::where('slug', 'api-football')->firstOrFail();
    }

    /**
     * Sync lineups for all matches in the pre/post-kickoff window.
     *
     * Window: kickoff_at in [now-30m, now+75m], not a definitive status.
     * Throttle: skip matches attempted in the last 15 minutes.
     * One API call per fixture returns both teams.
     *
     * @return array{status:string,candidates:int,synced:int,failed:int,empty:int,api_calls:int}
     */
    public function syncPending(): array
    {
        $ds = $this->dataSource();

        $now         = now();
        $windowStart = $now->copy()->subMinutes(30);
        $windowEnd   = $now->copy()->addMinutes(75);
        $throttle    = $now->copy()->subMinutes(15);

        $candidateIds = FootballMatch::whereNotIn('status', ApiFootballFixtureSyncService::DEFINITIVE_STATUSES)
            ->whereNotNull('kickoff_at')
            ->where('kickoff_at', '<=', $windowEnd)
            ->where('kickoff_at', '>=', $windowStart)
            ->where(function ($q) use ($throttle) {
                $q->whereNull('lineups_last_attempt_at')
                  ->orWhere('lineups_last_attempt_at', '<=', $throttle);
            })
            ->pluck('id');

        if ($candidateIds->isEmpty()) {
            return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'empty' => 0, 'api_calls' => 0];
        }

        $extIdByMatchId = MatchExternalId::where('data_source_id', $ds->id)
            ->whereIn('match_id', $candidateIds)
            ->pluck('external_id', 'match_id')
            ->all();

        if (empty($extIdByMatchId)) {
            Log::warning('api-football-lineups: ' . $candidateIds->count() . ' candidate(s) but none have api-football external IDs');
            return ['status' => 'ok', 'candidates' => 0, 'synced' => 0, 'failed' => 0, 'empty' => 0, 'api_calls' => 0];
        }

        $matchModels = FootballMatch::whereIn('id', array_keys($extIdByMatchId))
            ->get()
            ->keyBy('id')
            ->all();

        $candidates = 0;
        $synced     = 0;
        $failed     = 0;
        $empty      = 0;
        $apiCalls   = 0;

        foreach ($extIdByMatchId as $matchId => $extId) {
            $candidates++;
            $match = $matchModels[$matchId] ?? null;
            if (!$match) {
                continue;
            }

            $result    = $this->syncSingle($match, $extId);
            $apiCalls += $result['api_calls'];

            match ($result['outcome']) {
                'synced'     => $synced++,
                'http_error' => $failed++,
                'empty'      => $empty++,
                default      => null,
            };
        }

        return [
            'status'     => 'ok',
            'candidates' => $candidates,
            'synced'     => $synced,
            'failed'     => $failed,
            'empty'      => $empty,
            'api_calls'  => $apiCalls,
        ];
    }

    /**
     * Fetch and upsert lineups for a single fixture.
     *
     * Always updates lineups_last_attempt_at (throttle key), even on HTTP failure.
     * Updates lineups_fetched_at only when a valid, non-empty, parseable response is received.
     * Never throws — HTTP failures are caught and returned as outcome='http_error'.
     *
     * @return array{outcome:string,api_calls:int}
     */
    public function syncSingle(FootballMatch $match, string $extId): array
    {
        $ds = $this->dataSource();

        $match->update(['lineups_last_attempt_at' => now()]);

        $teamExtIdMap = TeamExternalId::where('data_source_id', $ds->id)
            ->whereIn('team_id', [$match->home_team_id, $match->away_team_id])
            ->pluck('team_id', 'external_id')
            ->all();

        try {
            $response = $this->client->get('fixtures/lineups', ['fixture' => $extId]);
        } catch (ApiFootballException $e) {
            Log::warning("api-football-lineups: fixture {$extId} — {$e->getMessage()}");
            return ['outcome' => 'http_error', 'api_calls' => 0];
        }

        if (empty($response->response)) {
            return ['outcome' => 'empty', 'api_calls' => 1];
        }

        $parsed = $this->parseResponse($response->response, $teamExtIdMap);

        if (empty($parsed)) {
            Log::warning("api-football-lineups: fixture {$extId} — response present but no parseable team lineup");
            return ['outcome' => 'unparsable', 'api_calls' => 1];
        }

        foreach ($parsed as $lineupData) {
            $this->upsertTeamLineup($match->id, $ds->id, $lineupData);
        }

        $match->update(['lineups_fetched_at' => now()]);

        return ['outcome' => 'synced', 'api_calls' => 1];
    }

    /**
     * Convert raw API response items into structured lineup arrays, keyed by canonical team.
     * Items with an unknown team external ID are skipped silently.
     */
    private function parseResponse(array $items, array $teamExtIdMap): array
    {
        $lineups = [];

        foreach ($items as $item) {
            $apiTeamId = (string) ($item['team']['id'] ?? '');
            if ($apiTeamId === '') {
                continue;
            }

            $canonicalTeamId = $teamExtIdMap[$apiTeamId] ?? null;
            if ($canonicalTeamId === null) {
                continue;
            }

            $formation = $item['formation'] ?? null;
            $coachId   = isset($item['coach']['id']) ? (string) $item['coach']['id'] : null;
            $coachName = ($item['coach']['name'] ?? null) ?: null;

            $players = [];

            foreach (($item['startXI'] ?? []) as $entry) {
                $p = $entry['player'] ?? [];
                if (empty($p['id'])) {
                    continue;
                }
                $players[] = $this->buildPlayerRow($p, isStarter: true);
            }

            foreach (($item['substitutes'] ?? []) as $entry) {
                $p = $entry['player'] ?? [];
                if (empty($p['id'])) {
                    continue;
                }
                $players[] = $this->buildPlayerRow($p, isStarter: false);
            }

            $lineups[] = [
                'team_id'           => $canonicalTeamId,
                'formation'         => ($formation !== '' && $formation !== null) ? $formation : null,
                'coach_external_id' => $coachId,
                'coach_name'        => $coachName,
                'players'           => $players,
            ];
        }

        return $lineups;
    }

    private function buildPlayerRow(array $p, bool $isStarter): array
    {
        return [
            'player_external_id' => (string) $p['id'],
            'player_name'        => (string) ($p['name'] ?? ''),
            'shirt_number'       => (isset($p['number']) && is_numeric($p['number'])) ? (int) $p['number'] : null,
            'position'           => ($p['pos'] ?? null) ?: null,
            'grid'               => ($p['grid'] ?? null) ?: null,
            'is_starter'         => $isStarter,
        ];
    }

    /**
     * Upsert the match_lineups row and synchronise its players.
     * Stale players (present in DB but absent from the new response) are deleted.
     */
    private function upsertTeamLineup(int $matchId, int $dsId, array $lineupData): void
    {
        $lineup = MatchLineup::updateOrCreate(
            [
                'match_id'       => $matchId,
                'data_source_id' => $dsId,
                'team_id'        => $lineupData['team_id'],
            ],
            [
                'formation'         => $lineupData['formation'],
                'coach_external_id' => $lineupData['coach_external_id'],
                'coach_name'        => $lineupData['coach_name'],
            ],
        );

        $players   = $lineupData['players'];
        $newExtIds = array_column($players, 'player_external_id');

        if (!empty($newExtIds)) {
            MatchLineupPlayer::where('match_lineup_id', $lineup->id)
                ->whereNotIn('player_external_id', $newExtIds)
                ->delete();
        } else {
            MatchLineupPlayer::where('match_lineup_id', $lineup->id)->delete();
        }

        foreach ($players as $player) {
            MatchLineupPlayer::updateOrCreate(
                [
                    'match_lineup_id'    => $lineup->id,
                    'player_external_id' => $player['player_external_id'],
                ],
                $player,
            );
        }
    }
}
