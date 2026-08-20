<?php

namespace App\Services\Imports;

use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Services\DataSources\FootballDataCoUk\FootballDataCoUkImporter;
use App\Services\DataSources\FootballDataCoUk\TeamResolver;
use App\Services\DataSources\FootballDataOrg\FootballDataOrgClient;
use App\Services\DataSources\FootballDataOrg\FootballDataOrgImporter;
use App\Services\Matches\CanonicalMatchResolver;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Orchestrates FDO + FDCUK import for one season across the 5 core leagues.
 *
 * This is a coordinator, not a re-implementation: it constructs and calls the
 * existing FootballDataOrgImporter / FootballDataCoUkImporter / TeamResolver /
 * CanonicalMatchResolver services exactly as the standalone artisan commands
 * do (service → service), and relies entirely on their existing idempotency
 * (external-id lookups, firstOrCreate, fill-only field policies) — it never
 * writes to the database directly and never duplicates their parsing logic.
 *
 * Callable from CLI (see `robetting:import-season`) and, later, from the
 * Upload Manager UI — hence the OutputInterface is optional and structured
 * array report is the primary output, not console text.
 */
class HistoricalSeasonImportService
{
    private const REQUIRED_CSV_COLUMNS = ['Div', 'HomeTeam', 'AwayTeam'];

    /**
     * @return array{
     *     season_code: string, season: string, year_start: int, dry_run: bool,
     *     status: string, leagues: list<array>
     * }
     */
    public function import(string $seasonCode, bool $dryRun = false, ?OutputInterface $output = null): array
    {
        $output ??= new NullOutput();
        $season = $this->parseSeasonCode($seasonCode);

        $leagues = $this->leaguesConfig();

        $report = [
            'season_code' => $season['code'],
            'season'      => $season['label'],
            'year_start'  => $season['year_start'],
            'dry_run'     => $dryRun,
            'status'      => 'pending',
            'leagues'     => [],
        ];

        // Phase A — filesystem preflight for all 5 leagues, all-or-nothing:
        // no FDO/FDCUK call and no DB write happens unless every core CSV is
        // present, readable, and structurally sane.
        $preflight     = [];
        $preflightFail = false;

        foreach ($leagues as $div => $cfg) {
            $result             = $this->preflightLeague($season['code'], $div, $cfg);
            $preflight[$div]    = $result;
            $preflightFail      = $preflightFail || !$result['ok'];
        }

        if ($preflightFail) {
            foreach ($leagues as $div => $cfg) {
                $ok = $preflight[$div]['ok'];
                $report['leagues'][] = $this->emptyLeagueReport(
                    $div, $cfg,
                    $ok ? 'skipped' : 'failed',
                    $ok ? null : 'preflight',
                    $ok ? null : $preflight[$div]['message'],
                );
            }

            $report['status'] = 'failed';

            return $report;
        }

        // Phase B — per-league pipeline, in config order. Stops at the first
        // failing league; remaining leagues are marked 'skipped'. No global
        // rollback of already-completed leagues: each importer's own DB
        // transaction is already committed and idempotent, so the batch can
        // simply be rerun after the underlying issue is fixed.
        $stopped = false;

        foreach ($leagues as $div => $cfg) {
            if ($stopped) {
                $report['leagues'][] = $this->emptyLeagueReport($div, $cfg, 'skipped', null, null);
                continue;
            }

            $leagueReport = $this->runLeaguePipeline($season, $div, $cfg, $dryRun, $output);
            $report['leagues'][] = $leagueReport;

            if ($leagueReport['status'] === 'failed') {
                $stopped = true;
            }
        }

        $report['status'] = in_array('failed', array_column($report['leagues'], 'status'), true)
            ? 'failed'
            : 'success';

        return $report;
    }

    // -------------------------------------------------------------------------
    // Season code
    // -------------------------------------------------------------------------

    /**
     * @return array{code: string, label: string, year_start: int, year_end: int, fdcuk_key: string}
     */
    public function parseSeasonCode(string $code): array
    {
        if (!preg_match('/^\d{4}$/', $code)) {
            throw new \InvalidArgumentException(
                "Season code non valido: '{$code}'. Atteso un formato a 4 cifre (es. 2324)."
            );
        }

        $first    = (int) substr($code, 0, 2);
        $second   = (int) substr($code, 2, 2);
        $expected = ($first + 1) % 100;

        if ($second !== $expected) {
            throw new \InvalidArgumentException(sprintf(
                "Season code non valido: '%s'. La seconda coppia (%02d) deve essere la prima (%02d) + 1 mod 100 (attesa: %02d).",
                $code, $second, $first, $expected,
            ));
        }

        // Assumes the 21st century — sufficient for this project's current
        // and historical seasons. Revisit only if 20th-century data is ever
        // imported.
        $yearStart = 2000 + $first;
        $yearEnd   = $yearStart + 1;

        return [
            'code'       => $code,
            'label'      => sprintf('%d/%02d', $yearStart, $yearEnd % 100),
            'year_start' => $yearStart,
            'year_end'   => $yearEnd,
            'fdcuk_key'  => "{$yearStart}-{$yearEnd}",
        ];
    }

    // -------------------------------------------------------------------------
    // League config
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{slug: string, fdo_code: string, fdcuk_file: string}>
     */
    private function leaguesConfig(): array
    {
        return require config_path('imports/football-data-co-uk-leagues.php');
    }

    // -------------------------------------------------------------------------
    // Phase A — filesystem preflight (no DB, no network)
    // -------------------------------------------------------------------------

    /**
     * @param  array{slug: string, fdo_code: string, fdcuk_file: string}  $cfg
     * @return array{ok: bool, message: ?string}
     */
    private function preflightLeague(string $seasonCode, string $div, array $cfg): array
    {
        $path = $this->seasonDir($seasonCode) . DIRECTORY_SEPARATOR . $cfg['fdcuk_file'];

        if (!is_file($path)) {
            return ['ok' => false, 'message' => "File mancante: {$cfg['fdcuk_file']} in {$seasonCode}/"];
        }

        if (!is_readable($path)) {
            return ['ok' => false, 'message' => "File non leggibile: {$cfg['fdcuk_file']}"];
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return ['ok' => false, 'message' => "Impossibile aprire {$cfg['fdcuk_file']}"];
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false || trim($firstLine) === '') {
                return ['ok' => false, 'message' => "{$cfg['fdcuk_file']}: file vuoto o privo di header"];
            }

            $firstLine = str_replace("\xEF\xBB\xBF", '', trim($firstLine));
            $headers   = str_getcsv($firstLine);

            $missing = array_diff(self::REQUIRED_CSV_COLUMNS, $headers);
            if (!empty($missing)) {
                return ['ok' => false, 'message' => "{$cfg['fdcuk_file']}: colonne mancanti: " . implode(', ', $missing)];
            }

            $divIndex      = array_search('Div', $headers, true);
            $firstDataLine = fgetcsv($handle);
            $actualDiv     = is_array($firstDataLine) ? trim((string) ($firstDataLine[$divIndex] ?? '')) : '';

            if ($actualDiv === '') {
                return ['ok' => false, 'message' => "{$cfg['fdcuk_file']}: nessuna riga dati dopo l'header"];
            }

            if ($actualDiv !== $div) {
                return ['ok' => false, 'message' => "{$cfg['fdcuk_file']}: Div CSV='{$actualDiv}' diverso dall'atteso '{$div}'"];
            }
        } finally {
            fclose($handle);
        }

        return ['ok' => true, 'message' => null];
    }

    private function seasonDir(string $seasonCode): string
    {
        return storage_path('app/imports/football-data-co-uk/' . $seasonCode);
    }

    // -------------------------------------------------------------------------
    // Phase B — per-league pipeline
    // -------------------------------------------------------------------------

    /**
     * @param  array{code: string, label: string, year_start: int, year_end: int, fdcuk_key: string}  $season
     * @param  array{slug: string, fdo_code: string, fdcuk_file: string}  $cfg
     */
    private function runLeaguePipeline(array $season, string $div, array $cfg, bool $dryRun, OutputInterface $output): array
    {
        $league = $this->emptyLeagueReport($div, $cfg, 'pending', null, null);
        $league['fdo']   = ['dry_run' => null, 'real' => null];
        $league['fdcuk'] = ['dry_run' => null, 'real' => null];

        // 1. FDO dry-run/check (always — this is the "validation before any
        //    real import" required even when the global mode is real).
        try {
            $league['fdo']['dry_run'] = $this->runFdo($cfg['fdo_code'], $season['year_start'], $cfg['slug'], true, $output);
        } catch (\Throwable $e) {
            return $this->fail($league, 'fdo_dry_run', $e->getMessage());
        }

        // 2. FDO real import.
        if (!$dryRun) {
            try {
                $league['fdo']['real'] = $this->runFdo($cfg['fdo_code'], $season['year_start'], $cfg['slug'], false, $output);
            } catch (\Throwable $e) {
                return $this->fail($league, 'fdo_import', $e->getMessage());
            }
        }

        // 3. canonical/mapping check — competition + season must now exist.
        if (!$dryRun && !Competition::where('slug', $cfg['slug'])->exists()) {
            return $this->fail($league, 'fdo_import', "Competition '{$cfg['slug']}' non trovata dopo l'import FDO.");
        }

        // 4. FDCUK dry-run/check.
        $csvPath = $this->seasonDir($season['code']) . DIRECTORY_SEPARATOR . $cfg['fdcuk_file'];

        try {
            $league['fdcuk']['dry_run'] = $this->runFdcuk($csvPath, $season['fdcuk_key'], $cfg['slug'], true, $output);
        } catch (\Throwable $e) {
            return $this->fail($league, 'fdcuk_dry_run', $e->getMessage());
        }

        // 5. FDCUK real import.
        if (!$dryRun) {
            try {
                $league['fdcuk']['real'] = $this->runFdcuk($csvPath, $season['fdcuk_key'], $cfg['slug'], false, $output);
            } catch (\Throwable $e) {
                return $this->fail($league, 'fdcuk_import', $e->getMessage());
            }
        }

        // 6. sanity checks finali — only meaningful once something has
        //    actually been persisted.
        if ($dryRun) {
            $league['verification'] = ['skipped' => true, 'reason' => 'dry-run: nessuna modifica persistita in DB'];
            $league['status']       = 'success';

            return $league;
        }

        try {
            $verification = $this->verifyLeague($cfg['slug'], $season['label']);
        } catch (\Throwable $e) {
            return $this->fail($league, 'verification', $e->getMessage());
        }

        $league['verification'] = $verification;

        if (!$verification['ok']) {
            return $this->fail($league, 'verification', implode('; ', $verification['issues']));
        }

        $league['status'] = 'success';

        return $league;
    }

    private function runFdo(string $fdoCode, int $yearStart, string $slug, bool $dryRun, OutputInterface $output): array
    {
        $apiKey = config('services.football_data_org.api_key');

        if (empty($apiKey)) {
            throw new \RuntimeException('FOOTBALL_DATA_ORG_API_KEY non configurata in .env');
        }

        $client        = new FootballDataOrgClient(config('services.football_data_org.base_url'), $apiKey);
        $matchResolver = new CanonicalMatchResolver();
        $importer      = new FootballDataOrgImporter($client, $matchResolver, $output);

        return $importer->import($fdoCode, $yearStart, [
            'slug'    => $slug,
            'dry_run' => $dryRun,
        ]);
    }

    private function runFdcuk(string $filePath, string $fdcukSeasonKey, string $slug, bool $dryRun, OutputInterface $output): array
    {
        $aliases       = require config_path('team-aliases/football-data-co-uk.php');
        $teamResolver  = new TeamResolver($aliases, FootballDataCoUkImporter::SOURCE_SLUG);
        $matchResolver = new CanonicalMatchResolver();
        $importer      = new FootballDataCoUkImporter($teamResolver, $matchResolver, $output);

        return $importer->import($filePath, $fdcukSeasonKey, [
            'competition_slug' => $slug,
            'dry_run'          => $dryRun,
        ]);
    }

    // -------------------------------------------------------------------------
    // Verification (read-only)
    // -------------------------------------------------------------------------

    /**
     * @return array{
     *     ok: bool, season_id?: int, season_count?: int, team_count?: int,
     *     match_count?: int, match_statistics_count?: int,
     *     fdo_mapped_matches?: int, fdcuk_mapped_matches?: int,
     *     duplicate_pairs?: int, duplicate_statistics?: int,
     *     missing_kickoff?: int, issues: list<string>
     * }
     */
    private function verifyLeague(string $slug, string $seasonLabel): array
    {
        $competition = Competition::where('slug', $slug)->first();

        if (!$competition) {
            return ['ok' => false, 'issues' => ["competition '{$slug}' non trovata"]];
        }

        $seasons = Season::where('competition_id', $competition->id)->where('name', $seasonLabel)->get();

        if ($seasons->count() !== 1) {
            return [
                'ok'           => false,
                'season_count' => $seasons->count(),
                'issues'       => ["season '{$seasonLabel}' non unica: trovate {$seasons->count()} righe"],
            ];
        }

        $season = $seasons->first();

        $teamCountRow = DB::select(
            'SELECT COUNT(*) AS cnt FROM (
                SELECT home_team_id AS team_id FROM matches WHERE season_id = ?
                UNION
                SELECT away_team_id FROM matches WHERE season_id = ?
             ) t',
            [$season->id, $season->id],
        );

        $matchCount = FootballMatch::where('season_id', $season->id)->count();

        $statsCount = DB::table('match_statistics')
            ->join('matches', 'matches.id', '=', 'match_statistics.match_id')
            ->where('matches.season_id', $season->id)
            ->count();

        $fdoMapped = DB::table('match_external_ids')
            ->join('matches', 'matches.id', '=', 'match_external_ids.match_id')
            ->join('data_sources', 'data_sources.id', '=', 'match_external_ids.data_source_id')
            ->where('matches.season_id', $season->id)
            ->where('data_sources.slug', 'football_data_org')
            ->count();

        $fdcukMapped = DB::table('match_external_ids')
            ->join('matches', 'matches.id', '=', 'match_external_ids.match_id')
            ->join('data_sources', 'data_sources.id', '=', 'match_external_ids.data_source_id')
            ->where('matches.season_id', $season->id)
            ->where('data_sources.slug', 'football_data_co_uk')
            ->count();

        // A given ordered (home, away) pair should appear at most once per
        // season in a normal round-robin league — the reverse fixture is a
        // different ordered pair, so this correctly flags true duplicates.
        $duplicatePairs = DB::select(
            'SELECT home_team_id, away_team_id, COUNT(*) AS cnt
             FROM matches
             WHERE season_id = ?
             GROUP BY home_team_id, away_team_id
             HAVING COUNT(*) > 1',
            [$season->id],
        );

        $duplicateStats = DB::select(
            'SELECT match_statistics.match_id, match_statistics.data_source_id, COUNT(*) AS cnt
             FROM match_statistics
             JOIN matches ON matches.id = match_statistics.match_id
             WHERE matches.season_id = ?
             GROUP BY match_statistics.match_id, match_statistics.data_source_id
             HAVING COUNT(*) > 1',
            [$season->id],
        );

        $missingKickoff = FootballMatch::where('season_id', $season->id)->whereNull('kickoff_at')->count();

        $issues = [];
        if (!empty($duplicatePairs)) {
            $issues[] = count($duplicatePairs) . ' coppie home/away con più di una partita nella stessa stagione';
        }
        if (!empty($duplicateStats)) {
            $issues[] = count($duplicateStats) . ' righe match_statistics duplicate (match_id + data_source_id)';
        }
        if ($missingKickoff > 0) {
            $issues[] = "{$missingKickoff} match senza kickoff_at";
        }

        return [
            'ok'                      => empty($issues),
            'season_id'               => $season->id,
            'season_count'            => 1,
            'team_count'              => (int) ($teamCountRow[0]->cnt ?? 0),
            'match_count'             => $matchCount,
            'match_statistics_count'  => $statsCount,
            'fdo_mapped_matches'      => $fdoMapped,
            'fdcuk_mapped_matches'    => $fdcukMapped,
            'duplicate_pairs'         => count($duplicatePairs),
            'duplicate_statistics'    => count($duplicateStats),
            'missing_kickoff'         => $missingKickoff,
            'issues'                  => $issues,
        ];
    }

    // -------------------------------------------------------------------------
    // Report helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array{slug: string, fdo_code: string, fdcuk_file: string}  $cfg
     */
    private function emptyLeagueReport(string $div, array $cfg, string $status, ?string $failedStep, ?string $error): array
    {
        return [
            'div'          => $div,
            'slug'         => $cfg['slug'],
            'fdo_code'     => $cfg['fdo_code'],
            'status'       => $status,
            'failed_step'  => $failedStep,
            'error'        => $error,
            'fdo'          => null,
            'fdcuk'        => null,
            'verification' => null,
        ];
    }

    private function fail(array $league, string $step, string $message): array
    {
        $league['status']      = 'failed';
        $league['failed_step'] = $step;
        $league['error']       = $message;

        return $league;
    }
}
