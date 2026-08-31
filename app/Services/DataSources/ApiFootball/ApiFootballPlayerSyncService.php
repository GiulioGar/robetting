<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\SeasonPlayer;
use App\Models\Team;
use App\Models\TeamExternalId;
use Illuminate\Support\Facades\Log;

class ApiFootballPlayerSyncService
{
    private ?DataSource $ds = null;

    public function __construct(private readonly ApiFootballClient $client) {}

    private function dataSource(): DataSource
    {
        return $this->ds ??= DataSource::where('slug', 'api-football')->firstOrFail();
    }

    /**
     * Sync players for all teams in every season matching $seasonYear that has an
     * api-football SeasonExternalId. Optionally scoped to a single competition by slug.
     *
     * @return array{status:string, teams_processed:int, players_created:int, players_updated:int,
     *               memberships_created:int, memberships_unchanged:int, api_calls:int,
     *               daily_remaining:int|null, warnings:list<string>}
     */
    public function syncSeason(int $seasonYear, ?string $leagueSlug = null): array
    {
        $startedAt = now();
        $ds        = $this->dataSource();

        // Resolve canonical season IDs that have an api-football SeasonExternalId
        $validSeasonIds = SeasonExternalId::where('data_source_id', $ds->id)->pluck('season_id')->all();

        $seasons = Season::where('year_start', $seasonYear)
            ->whereIn('id', $validSeasonIds)
            ->when($leagueSlug, fn($q) => $q->whereHas('competition', fn($q2) => $q2->where('slug', $leagueSlug)))
            ->get();

        $totalTeams           = 0;
        $totalCreated         = 0;
        $totalUpdated         = 0;
        $totalMembCreated     = 0;
        $totalMembUnchanged   = 0;
        $totalApiCalls        = 0;
        $lastRemaining        = null;
        $allWarnings          = [];

        if ($seasons->isEmpty()) {
            $suffix = $leagueSlug ? " slug={$leagueSlug}" : '';
            $allWarnings[] = "no seasons found for year_start={$seasonYear}{$suffix}";
        }

        foreach ($seasons as $season) {
            $teams = $season->teams; // BelongsToMany via season_team

            if ($teams->isEmpty()) {
                $allWarnings[] = "season {$season->name}: no teams in season_team — skipped";
                continue;
            }

            $teamIds   = $teams->pluck('id')->all();
            $extIdMap  = TeamExternalId::where('data_source_id', $ds->id)
                ->whereIn('team_id', $teamIds)
                ->pluck('external_id', 'team_id')
                ->all();

            foreach ($teams as $team) {
                $extId = $extIdMap[$team->id] ?? null;

                if ($extId === null) {
                    $allWarnings[] = "team {$team->name}: no api-football external ID — skipped";
                    continue;
                }

                $result = $this->syncTeam($team, $extId, $season, $seasonYear);

                $totalTeams++;
                $totalCreated       += $result['created'];
                $totalUpdated       += $result['updated'];
                $totalMembCreated   += $result['memberships_created'];
                $totalMembUnchanged += $result['memberships_unchanged'];
                $totalApiCalls      += $result['api_calls'];

                if ($result['daily_remaining'] !== null) {
                    $lastRemaining = $result['daily_remaining'];
                }

                $allWarnings = array_merge($allWarnings, $result['warnings']);
            }
        }

        DataSyncRun::create([
            'data_source_id'  => $ds->id,
            'sync_type'       => 'player_sync',
            'competition_id'  => null,
            'season_id'       => null,
            'mode'            => null,
            'started_at'      => $startedAt,
            'finished_at'     => now(),
            'status'          => 'ok',
            'created_count'   => $totalCreated,
            'updated_count'   => $totalUpdated,
            'unchanged_count' => $totalMembUnchanged,
            'skipped_count'   => 0,
            'warnings_count'  => count($allWarnings),
            'api_calls'       => $totalApiCalls,
            'daily_remaining' => $lastRemaining,
            'details'         => empty($allWarnings) ? null : ['warnings' => $allWarnings],
        ]);

        return [
            'status'                => 'ok',
            'teams_processed'       => $totalTeams,
            'players_created'       => $totalCreated,
            'players_updated'       => $totalUpdated,
            'memberships_created'   => $totalMembCreated,
            'memberships_unchanged' => $totalMembUnchanged,
            'api_calls'             => $totalApiCalls,
            'daily_remaining'       => $lastRemaining,
            'warnings'              => $allWarnings,
        ];
    }

    /**
     * Fetch all pages of /players?team={extId}&season={seasonYear} and upsert each player.
     * Paging errors abort remaining pages for this team only.
     *
     * @return array{team:string, created:int, updated:int, memberships_created:int,
     *               memberships_unchanged:int, api_calls:int, daily_remaining:int|null, warnings:list<string>}
     */
    public function syncTeam(Team $team, string $extId, Season $season, int $seasonYear): array
    {
        $ds = $this->dataSource();

        $created         = 0;
        $updated         = 0;
        $membCreated     = 0;
        $membUnchanged   = 0;
        $apiCalls        = 0;
        $lastRemaining   = null;
        $warnings        = [];

        $page       = 1;
        $totalPages = 1;

        do {
            try {
                $response = $this->client->get('players', [
                    'team'   => $extId,
                    'season' => $seasonYear,
                    'page'   => $page,
                ]);
            } catch (ApiFootballException $e) {
                $msg = "team {$team->name} page {$page}: {$e->getMessage()}";
                Log::warning("api-football-player-sync: {$msg}");
                $warnings[] = $msg;
                break;
            }

            $apiCalls++;
            $lastRemaining = $response->requestsRemaining;
            $totalPages    = (int) ($response->paging['total'] ?? 1);

            foreach ($response->response as $item) {
                $result = $this->upsertPlayer($item, $team, $season, $ds->id);

                if ($result['outcome'] === 'created') {
                    $created++;
                } elseif ($result['outcome'] === 'updated') {
                    $updated++;
                }

                if ($result['membership_created']) {
                    $membCreated++;
                } else {
                    $membUnchanged++;
                }
            }

            $page++;
        } while ($page <= $totalPages);

        return [
            'team'                 => $team->name,
            'created'              => $created,
            'updated'              => $updated,
            'memberships_created'  => $membCreated,
            'memberships_unchanged'=> $membUnchanged,
            'api_calls'            => $apiCalls,
            'daily_remaining'      => $lastRemaining,
            'warnings'             => $warnings,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return array{outcome:'created'|'updated'|'skipped', membership_created:bool}
     */
    private function upsertPlayer(array $item, Team $team, Season $season, int $dsId): array
    {
        $apiId = (string) ($item['player']['id'] ?? '');
        if ($apiId === '') {
            return ['outcome' => 'skipped', 'membership_created' => false];
        }

        $position = $this->extractPosition($item);

        $extRecord = PlayerExternalId::where('data_source_id', $dsId)
            ->where('external_id', $apiId)
            ->with('player')
            ->first();

        $attrs = $this->parsePlayerAttributes($item['player'] ?? [], $position);

        if ($extRecord !== null) {
            $extRecord->player->update($attrs);
            $player = $extRecord->player;
            $isNew  = false;
        } else {
            $player = Player::create($attrs);
            PlayerExternalId::create([
                'player_id'      => $player->id,
                'data_source_id' => $dsId,
                'external_id'    => $apiId,
                'external_name'  => ($item['player']['name'] ?? null) ?: null,
            ]);
            $isNew = true;
        }

        $membership = SeasonPlayer::updateOrCreate(
            ['season_id' => $season->id, 'team_id' => $team->id, 'player_id' => $player->id],
            ['position'  => $position],
        );

        return [
            'outcome'            => $isNew ? 'created' : 'updated',
            'membership_created' => $membership->wasRecentlyCreated,
        ];
    }

    private function parsePlayerAttributes(array $player, ?string $position): array
    {
        $firstname = trim((string) ($player['firstname'] ?? ''));
        $lastname  = trim((string) ($player['lastname'] ?? ''));
        $apiName   = (string) ($player['name'] ?? '');

        if ($firstname !== '' && $lastname !== '') {
            $name = "$firstname $lastname";
        } elseif ($lastname !== '') {
            $name = $lastname;
        } elseif ($firstname !== '') {
            $name = $firstname;
        } else {
            $name = $apiName;
        }

        return [
            'name'        => $name,
            'firstname'   => $firstname !== '' ? $firstname : null,
            'lastname'    => $lastname  !== '' ? $lastname  : null,
            'birth_date'  => ($player['birth']['date'] ?? null) ?: null,
            'nationality' => ($player['nationality'] ?? null) ?: null,
            'height_cm'   => $this->parseMeasurement($player['height'] ?? null),
            'weight_kg'   => $this->parseMeasurement($player['weight'] ?? null),
            'position'    => $position,
            'photo_url'   => ($player['photo'] ?? null) ?: null,
        ];
    }

    private function extractPosition(array $item): ?string
    {
        $raw = $item['statistics'][0]['games']['position'] ?? null;
        return $this->normalizePosition($raw);
    }

    private function normalizePosition(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        return match (strtolower(trim($raw))) {
            'g', 'goalkeeper'          => 'goalkeeper',
            'd', 'defender'            => 'defender',
            'm', 'midfielder'          => 'midfielder',
            'f', 'forward', 'attacker' => 'attacker',
            default                    => null,
        };
    }

    private function parseMeasurement(?string $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (preg_match('/(\d+)/', $raw, $m)) {
            $value = (int) $m[1];
            return $value > 0 ? $value : null;
        }
        return null;
    }
}
