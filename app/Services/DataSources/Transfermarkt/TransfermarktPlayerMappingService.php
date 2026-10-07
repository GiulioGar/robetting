<?php

namespace App\Services\DataSources\Transfermarkt;

use App\Models\DataSource;
use App\Models\PlayerExternalId;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballClient;
use App\Services\DataSources\ApiFootball\ApiFootballException;
use Illuminate\Support\Facades\Log;

/**
 * Maps Transfermarkt player IDs (from tools/models/structural_players_current.json,
 * produced by the P16D scraper pipeline) to canonical players.id, via the
 * API-Football CURRENT squad endpoint (/players/squads?team=), using the exact
 * deterministic name-matching logic validated in Availability V1 Step 4F.
 *
 * Fail-closed by design:
 *   - ambiguous name match (>1 candidate in the same team)  -> skipped
 *   - unmatched name                                        -> skipped
 *   - matched API-Football player has no players row yet    -> skipped (no_canonical_player)
 *   - conflicting pre-existing Transfermarkt mapping         -> skipped, reported, never overwritten
 *
 * No fuzzy matching anywhere. No new tables. Reuses the existing
 * player_external_ids schema (UNIQUE(player_id,data_source_id) and
 * UNIQUE(data_source_id,external_id) already enforce the "no conflicting
 * overwrite" rule at the DB level; this service checks proactively so it can
 * report a clear reason instead of hitting a constraint violation).
 */
class TransfermarktPlayerMappingService
{
    private const TM_SNAPSHOT_PATH = 'tools/models/structural_players_current.json';

    private ?DataSource $apiFootballDs = null;
    private ?DataSource $transfermarktDs = null;

    public function __construct(private readonly ApiFootballClient $client) {}

    private function apiFootballDataSource(): DataSource
    {
        return $this->apiFootballDs ??= DataSource::where('slug', 'api-football')->firstOrFail();
    }

    private function transfermarktDataSource(): DataSource
    {
        return $this->transfermarktDs ??= DataSource::firstOrCreate(
            ['slug' => 'transfermarkt'],
            ['name' => 'Transfermarkt', 'source_type' => 'scraper', 'is_active' => true],
        );
    }

    /**
     * @return array{
     *   teams_processed:int, api_calls:int, safe:int, ambiguous:int, unmatched:int,
     *   already_mapped:int, created:int, conflicts:int, no_canonical_player:int,
     *   warnings:list<string>, conflict_examples:list<array>,
     * }
     */
    public function run(bool $apply): array
    {
        $snapshotPath = base_path(self::TM_SNAPSHOT_PATH);
        if (! file_exists($snapshotPath)) {
            throw new \RuntimeException("Transfermarkt players snapshot not found: {$snapshotPath}");
        }

        $tmSnapshot = json_decode(file_get_contents($snapshotPath), true);
        $tmTeams    = $tmSnapshot['teams'] ?? [];

        $apiFootballDsId = $this->apiFootballDataSource()->id;
        $transfermarktDs = $apply ? $this->transfermarktDataSource() : null;

        $stats = [
            'teams_processed'     => 0,
            'api_calls'           => 0,
            'safe'                => 0,
            'ambiguous'           => 0,
            'unmatched'           => 0,
            'already_mapped'      => 0,
            'created'             => 0,
            'conflicts'           => 0,
            'no_canonical_player' => 0,
            'warnings'            => [],
            'conflict_examples'   => [],
        ];

        foreach ($tmTeams as $teamId => $tmTeam) {
            $team = Team::find((int) $teamId);
            if ($team === null) {
                $stats['warnings'][] = "team_id={$teamId} ({$tmTeam['team_name']}): not found in teams table — skipped";
                continue;
            }

            $extId = TeamExternalId::where('team_id', $team->id)
                ->where('data_source_id', $apiFootballDsId)
                ->value('external_id');

            if ($extId === null) {
                $stats['warnings'][] = "team {$team->name}: no api-football TeamExternalId — skipped";
                continue;
            }

            try {
                $response = $this->client->get('players/squads', ['team' => $extId]);
            } catch (ApiFootballException $e) {
                $stats['warnings'][] = "team {$team->name}: {$e->getMessage()}";
                continue;
            }

            $stats['api_calls']++;
            $stats['teams_processed']++;

            $apiPlayers = $response->response[0]['players'] ?? [];

            [$apiByNormName, $apiBySurname] = $this->buildApiIndices($apiPlayers);

            foreach ($tmTeam['players'] as $tmPlayer) {
                $outcome = $this->classify($tmPlayer['name'], $apiByNormName, $apiBySurname);

                if ($outcome['status'] === 'ambiguous') {
                    $stats['ambiguous']++;
                    continue;
                }
                if ($outcome['status'] === 'unmatched') {
                    $stats['unmatched']++;
                    continue;
                }

                // status === 'safe'
                $stats['safe']++;
                $apiPlayerId = (string) $outcome['api_player_id'];

                $canonicalPlayerId = PlayerExternalId::where('data_source_id', $apiFootballDsId)
                    ->where('external_id', $apiPlayerId)
                    ->value('player_id');

                if ($canonicalPlayerId === null) {
                    $stats['no_canonical_player']++;
                    continue;
                }

                $tmPlayerId = (string) $tmPlayer['tm_player_id'];

                $result = $this->resolveMapping(
                    $transfermarktDs?->id,
                    $canonicalPlayerId,
                    $tmPlayerId,
                    (string) $tmPlayer['name'],
                    $apply,
                );

                $stats[$result['outcome']]++;
                if ($result['outcome'] === 'conflicts' && count($stats['conflict_examples']) < 20) {
                    $stats['conflict_examples'][] = $result['detail'];
                }
            }
        }

        return $stats;
    }

    /**
     * @return array{0: array<string,list<int>>, 1: array<string,list<int>>}
     */
    private function buildApiIndices(array $apiPlayers): array
    {
        $byNormName = [];
        $bySurname  = [];

        foreach ($apiPlayers as $p) {
            $norm = self::normalize((string) ($p['name'] ?? ''));
            if ($norm === '') {
                continue;
            }
            $byNormName[$norm][] = $p['id'];

            $tokens  = explode(' ', $norm);
            $surname = end($tokens);
            if ($surname !== '') {
                $bySurname[$surname][] = $p['id'];
            }
        }

        return [$byNormName, $bySurname];
    }

    /**
     * @return array{status:'safe', api_player_id:int}|array{status:'ambiguous'|'unmatched'}
     */
    private function classify(string $tmName, array $apiByNormName, array $apiBySurname): array
    {
        $tmNorm   = self::normalize($tmName);
        $tmTokens = explode(' ', $tmNorm);
        $tmSurname = end($tmTokens);

        $candidates = $apiByNormName[$tmNorm] ?? [];

        if (empty($candidates) && $tmSurname !== '') {
            $candidates = $apiBySurname[$tmSurname] ?? [];
        }

        if (count($candidates) === 1) {
            return ['status' => 'safe', 'api_player_id' => $candidates[0]];
        }
        if (count($candidates) > 1) {
            return ['status' => 'ambiguous'];
        }

        return ['status' => 'unmatched'];
    }

    /**
     * @return array{outcome:'already_mapped'|'created'|'conflicts', detail?:array}
     */
    private function resolveMapping(
        ?int $transfermarktDsId,
        int $canonicalPlayerId,
        string $tmPlayerId,
        string $tmPlayerName,
        bool $apply,
    ): array {
        // In dry-run we don't have a real Transfermarkt DataSource id resolved
        // (not created yet to avoid side effects) — resolve it read-only here
        // via slug lookup, without creating anything.
        $dsId = $transfermarktDsId ?? DataSource::where('slug', 'transfermarkt')->value('id');

        if ($dsId !== null) {
            $byExternalId = PlayerExternalId::where('data_source_id', $dsId)
                ->where('external_id', $tmPlayerId)
                ->first();

            if ($byExternalId !== null) {
                if ((int) $byExternalId->player_id === $canonicalPlayerId) {
                    return ['outcome' => 'already_mapped'];
                }

                return [
                    'outcome' => 'conflicts',
                    'detail'  => [
                        'reason'               => 'tm_id_already_mapped_to_different_player',
                        'tm_player_id'         => $tmPlayerId,
                        'tm_player_name'       => $tmPlayerName,
                        'existing_player_id'   => $byExternalId->player_id,
                        'resolved_player_id'   => $canonicalPlayerId,
                    ],
                ];
            }

            $byPlayer = PlayerExternalId::where('data_source_id', $dsId)
                ->where('player_id', $canonicalPlayerId)
                ->first();

            if ($byPlayer !== null) {
                return [
                    'outcome' => 'conflicts',
                    'detail'  => [
                        'reason'             => 'player_already_mapped_to_different_tm_id',
                        'tm_player_id'       => $tmPlayerId,
                        'tm_player_name'     => $tmPlayerName,
                        'resolved_player_id' => $canonicalPlayerId,
                        'existing_tm_id'     => $byPlayer->external_id,
                    ],
                ];
            }
        }

        if (! $apply) {
            // Dry-run: no existing mapping and no conflict — this WOULD create one.
            return ['outcome' => 'created'];
        }

        PlayerExternalId::create([
            'player_id'      => $canonicalPlayerId,
            'data_source_id' => $dsId,
            'external_id'    => $tmPlayerId,
            'external_name'  => $tmPlayerName,
        ]);

        return ['outcome' => 'created'];
    }

    /**
     * Same conservative normalization validated in Step 4F: lowercase, strip
     * diacritics (with the Windows-iconv "'c" artifact corrected), collapse
     * to alphanumeric tokens. No fuzzy/edit-distance logic.
     */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));

        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($translit !== false && $translit !== '') {
            $s = str_replace("'", '', $translit);
        }

        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
