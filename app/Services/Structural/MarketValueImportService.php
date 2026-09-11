<?php

namespace App\Services\Structural;

use App\Models\DataSource;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Models\TeamMarketValueSnapshot;
use Illuminate\Database\QueryException;

/**
 * Validates, maps, and optionally persists a market-value JSON snapshot.
 *
 * ── Flow ─────────────────────────────────────────────────────────────────────
 *
 *   $preview = $service->preview($jsonString);   // validate + map, no DB write
 *   if ($preview['valid']) {
 *       $result = $service->confirm($preview);   // insert 'ok' rows
 *   }
 *
 * ── Team mapping ─────────────────────────────────────────────────────────────
 *
 * Each team name in the JSON is resolved in two steps:
 *
 *   1. Exact match on teams.name
 *   2. Exact match on team_external_ids.external_name for the given data_source
 *
 * If neither matches → status 'unmapped'. No fuzzy logic, no fallback.
 *
 * ── Row statuses ─────────────────────────────────────────────────────────────
 *
 *   ok                    — valid, mapped, not yet in DB → will be inserted
 *   already_exists        — snapshot already present for this team/source/date
 *   unmapped              — team name not found in DB
 *   invalid_market_value  — market_value is missing, non-integer, or ≤ 0
 *   missing_team_name     — 'team' field absent or empty
 *   missing_market_value  — 'market_value' key absent from the row
 *   duplicate_in_file     — same team name appears more than once in the file
 *
 * ── File-level validations ───────────────────────────────────────────────────
 *
 *   JSON syntax, snapshot_date (Y-m-d), source slug (must exist in data_sources),
 *   teams key (must be a non-empty array).
 *
 * ── Raw JSON storage (future) ────────────────────────────────────────────────
 *
 * This service is intentionally unaware of the filesystem. To persist the raw
 * JSON alongside each import, the caller (controller/command) should write the
 * file to storage/app/structural/market_values_{date}.json BEFORE calling
 * preview(). The service signature does not change.
 *
 * ── Queries ──────────────────────────────────────────────────────────────────
 *
 * preview():  4 queries — DataSource lookup, Team name map, TeamExternalId map,
 *             existing-snapshot set.
 * confirm():  1 INSERT per 'ok' row.
 */
class MarketValueImportService
{
    /**
     * Parse and validate a market-value JSON file. No DB writes.
     *
     * @return array{
     *     valid:          bool,
     *     error:          string|null,
     *     snapshot_date:  string|null,
     *     data_source_id: int|null,
     *     summary:        array|null,
     *     rows:           list<array>
     * }
     */
    public function preview(string $jsonContent): array
    {
        // ── 1. JSON syntax ─────────────────────────────────────────────────────

        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->fileError('JSON non valido: ' . json_last_error_msg() . '.');
        }

        if (!is_array($data)) {
            return $this->fileError('JSON non valido: il documento deve essere un oggetto.');
        }

        // ── 2. snapshot_date ───────────────────────────────────────────────────

        $snapshotDateRaw = $data['snapshot_date'] ?? null;
        if (!is_string($snapshotDateRaw) || trim($snapshotDateRaw) === '') {
            return $this->fileError("Campo 'snapshot_date' mancante o vuoto.");
        }
        if (!$this->isValidDate($snapshotDateRaw)) {
            return $this->fileError("Campo 'snapshot_date' non è una data Y-m-d valida: '{$snapshotDateRaw}'.");
        }
        $snapshotDate = $snapshotDateRaw;

        // ── 3. source ──────────────────────────────────────────────────────────

        $sourceSlug = $data['source'] ?? null;
        if (!is_string($sourceSlug) || trim($sourceSlug) === '') {
            return $this->fileError("Campo 'source' mancante o vuoto.");
        }

        $dataSource = DataSource::where('slug', $sourceSlug)->first();
        if ($dataSource === null) {
            return $this->fileError("Source '{$sourceSlug}' non trovata in data_sources.");
        }
        $dataSourceId = (int) $dataSource->id;

        // ── 4. teams array ─────────────────────────────────────────────────────

        $teamsInput = $data['teams'] ?? null;
        if (!is_array($teamsInput)) {
            return $this->fileError("Campo 'teams' mancante o non è un array.");
        }
        if (empty($teamsInput)) {
            return $this->fileError("Il campo 'teams' è un array vuoto.");
        }

        // ── 5. Build lookup maps (2 queries) ───────────────────────────────────

        [$byName, $byExternalName] = $this->loadTeamMaps($dataSourceId);

        // ── 6. Find duplicate team names in the file ───────────────────────────

        $nameCounts = [];
        foreach ($teamsInput as $item) {
            if (is_array($item) && is_string($item['team'] ?? null) && trim($item['team']) !== '') {
                $n = trim($item['team']);
                $nameCounts[$n] = ($nameCounts[$n] ?? 0) + 1;
            }
        }
        $duplicateNames = array_filter($nameCounts, fn($c) => $c > 1);

        // ── 7. Existing snapshots for this source+date (1 query) ───────────────

        $existingTeamIdSet = array_flip(
            TeamMarketValueSnapshot::where('data_source_id', $dataSourceId)
                ->where('snapshot_date', $snapshotDate)
                ->pluck('team_id')
                ->map(fn($id) => (int) $id)
                ->all()
        );

        // ── 8. Process each row ────────────────────────────────────────────────

        $rows = [];
        foreach ($teamsInput as $item) {
            $rows[] = $this->processRow(
                $item,
                $snapshotDate,
                $dataSourceId,
                $byName,
                $byExternalName,
                $duplicateNames,
                $existingTeamIdSet
            );
        }

        return [
            'valid'          => true,
            'error'          => null,
            'snapshot_date'  => $snapshotDate,
            'data_source_id' => $dataSourceId,
            'summary'        => $this->buildSummary($rows),
            'rows'           => $rows,
        ];
    }

    /**
     * Insert all 'ok' rows from a valid preview result.
     * Rows with a concurrent duplicate (race condition) are silently skipped.
     *
     * @param  array  $preview  Output of preview(); must have valid === true.
     * @return array{inserted: int, skipped_existing: int, total_attempted: int}
     *
     * @throws \LogicException   if preview['valid'] is false.
     * @throws QueryException    on unexpected DB errors.
     */
    public function confirm(array $preview): array
    {
        if (!($preview['valid'] ?? false)) {
            throw new \LogicException('confirm() called on an invalid preview result.');
        }

        $okRows  = array_values(array_filter($preview['rows'], fn($r) => $r['status'] === 'ok'));
        $inserted = 0;
        $skipped  = 0;

        foreach ($okRows as $row) {
            try {
                TeamMarketValueSnapshot::create([
                    'team_id'        => $row['team_id'],
                    'data_source_id' => $row['data_source_id'],
                    'snapshot_date'  => $row['snapshot_date'],
                    'market_value'   => $row['market_value'],
                ]);
                $inserted++;
            } catch (QueryException $e) {
                // MySQL 1062 = duplicate key: concurrent insert, skip gracefully.
                if (($e->errorInfo[1] ?? null) === 1062) {
                    $skipped++;
                } else {
                    throw $e;
                }
            }
        }

        return [
            'inserted'         => $inserted,
            'skipped_existing' => $skipped,
            'total_attempted'  => count($okRows),
        ];
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function fileError(string $message): array
    {
        return [
            'valid'          => false,
            'error'          => $message,
            'snapshot_date'  => null,
            'data_source_id' => null,
            'summary'        => null,
            'rows'           => [],
        ];
    }

    private function isValidDate(string $value): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }

    /**
     * @return array{array<string,int>, array<string,int>}  [byName, byExternalName]
     */
    private function loadTeamMaps(int $dataSourceId): array
    {
        $byName = Team::pluck('id', 'name')
            ->map(fn($id) => (int) $id)
            ->all();

        $byExternalName = TeamExternalId::where('data_source_id', $dataSourceId)
            ->whereNotNull('external_name')
            ->pluck('team_id', 'external_name')
            ->map(fn($id) => (int) $id)
            ->all();

        return [$byName, $byExternalName];
    }

    /**
     * Validate and map one row from the JSON teams array.
     *
     * Status resolution order (first match wins):
     *   missing_team_name → missing_market_value → invalid_market_value
     *   → duplicate_in_file → unmapped → already_exists → ok
     */
    private function processRow(
        mixed  $item,
        string $snapshotDate,
        int    $dataSourceId,
        array  $byName,
        array  $byExternalName,
        array  $duplicateNames,
        array  $existingTeamIdSet
    ): array {
        $row = [
            'team_name'      => null,
            'market_value'   => null,
            'team_id'        => null,
            'data_source_id' => $dataSourceId,
            'snapshot_date'  => $snapshotDate,
            'status'         => null,
            'detail'         => null,
        ];

        if (!is_array($item)) {
            return array_merge($row, ['status' => 'missing_team_name', 'detail' => 'Elemento non è un oggetto.']);
        }

        // team name
        $rawName = $item['team'] ?? null;
        if (!is_string($rawName) || trim($rawName) === '') {
            return array_merge($row, ['status' => 'missing_team_name', 'detail' => "Campo 'team' mancante o vuoto."]);
        }
        $teamName        = trim($rawName);
        $row['team_name'] = $teamName;

        // market_value — presence
        if (!array_key_exists('market_value', $item)) {
            return array_merge($row, ['status' => 'missing_market_value', 'detail' => "Campo 'market_value' assente."]);
        }

        $mv = $item['market_value'];

        // market_value — must be integer type (JSON int, not float or string)
        if (!is_int($mv)) {
            return array_merge($row, [
                'status' => 'invalid_market_value',
                'detail' => "market_value deve essere un intero JSON, ricevuto: " . gettype($mv) . ".",
            ]);
        }

        // market_value — must be positive
        if ($mv <= 0) {
            return array_merge($row, [
                'market_value' => $mv,
                'status'       => 'invalid_market_value',
                'detail'       => "market_value deve essere > 0, ricevuto: {$mv}.",
            ]);
        }
        $row['market_value'] = $mv;

        // duplicate in file
        if (isset($duplicateNames[$teamName])) {
            return array_merge($row, [
                'status' => 'duplicate_in_file',
                'detail' => "'{$teamName}' appare più di una volta nel file.",
            ]);
        }

        // team mapping: teams.name first, then team_external_ids.external_name
        $teamId = $byName[$teamName] ?? $byExternalName[$teamName] ?? null;
        if ($teamId === null) {
            return array_merge($row, [
                'status' => 'unmapped',
                'detail' => "Nessun team trovato per '{$teamName}'.",
            ]);
        }
        $row['team_id'] = $teamId;

        // existing snapshot
        if (array_key_exists($teamId, $existingTeamIdSet)) {
            return array_merge($row, [
                'status' => 'already_exists',
                'detail' => "Snapshot già presente per team_id={$teamId} al {$snapshotDate}.",
            ]);
        }

        return array_merge($row, ['status' => 'ok']);
    }

    /**
     * Summary counts derived from the processed rows.
     *
     * Partition: mapped_teams + unmapped_teams + duplicate_teams + invalid_values = total_teams
     * valid_values = ok + already_exists + unmapped + duplicate_in_file (rows with a valid market_value)
     */
    private function buildSummary(array $rows): array
    {
        $s = [
            'total_teams'        => count($rows),
            'mapped_teams'       => 0,
            'unmapped_teams'     => 0,
            'valid_values'       => 0,
            'invalid_values'     => 0,
            'duplicate_teams'    => 0,
            'existing_snapshots' => 0,
            'new_snapshots'      => 0,
        ];

        foreach ($rows as $row) {
            switch ($row['status']) {
                case 'ok':
                    $s['mapped_teams']++;
                    $s['valid_values']++;
                    $s['new_snapshots']++;
                    break;
                case 'already_exists':
                    $s['mapped_teams']++;
                    $s['valid_values']++;
                    $s['existing_snapshots']++;
                    break;
                case 'unmapped':
                    $s['unmapped_teams']++;
                    $s['valid_values']++;
                    break;
                case 'duplicate_in_file':
                    $s['duplicate_teams']++;
                    $s['valid_values']++;
                    break;
                default:
                    // invalid_market_value, missing_market_value, missing_team_name
                    $s['invalid_values']++;
                    break;
            }
        }

        return $s;
    }
}
