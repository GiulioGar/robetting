<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Season;
use App\Services\Imports\HistoricalSeasonImportService;
use Carbon\Carbon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use ZipArchive;

/**
 * Raw file manager + upload-triggered import entry point for
 * football-data.co.uk CSV/ZIP files.
 *
 * File placement and DB import stay two distinct steps internally: this
 * class still never parses FDO/FDCUK data itself — after a file is safely
 * saved under storage/app/imports/football-data-co-uk/{season}/, it only
 * *delegates* to HistoricalSeasonImportService (the same orchestrator the
 * CLI command and the manual "Reimporta stagione" button use) once the
 * season directory has all 5 core CSVs. A single-file upload that leaves
 * the directory incomplete just saves the file and does not trigger an
 * import — see coreFilesStatus().
 */
class FootballDataCoUkUploadController extends Controller
{
    private const IMPORTS_SUBPATH = 'app/imports/football-data-co-uk';

    private const REQUIRED_CSV_COLUMNS = ['Div', 'HomeTeam', 'AwayTeam'];

    private const CSV_MIME_ALLOWLIST = [
        'text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel',
    ];

    private const ZIP_MIME_ALLOWLIST = [
        'application/zip', 'application/x-zip-compressed', 'application/octet-stream',
    ];

    public function __construct(private readonly Filesystem $files) {}

    public function index(Request $request): View
    {
        $seasonOptions = $this->seasonOptions();

        $selectedSeason = $request->query('season');
        if (!is_string($selectedSeason) || !array_key_exists($selectedSeason, $seasonOptions)) {
            $selectedSeason = $this->currentSeasonValue();
        }

        $coreFiles = $this->coreFilesStatus($selectedSeason);

        return view('admin.imports.football-data-co-uk.index', [
            'seasonOptions'       => $seasonOptions,
            'selectedSeason'      => $selectedSeason,
            'existingFiles'       => $this->listExistingFiles($selectedSeason),
            'report'              => session('upload_report'),
            'coreFiles'           => $coreFiles,
            'allCoreFilesPresent' => !in_array(false, array_column($coreFiles, 'present'), true),
            'dbAlreadyPresent'    => $this->seasonHasDbData($seasonOptions[$selectedSeason]),
            'importReport'        => session('import_report'),
            'importMissingCore'   => session('import_missing_core'),
            'importTriggerError'  => session('import_trigger_error'),
        ]);
    }

    public function store(Request $request, HistoricalSeasonImportService $importService): RedirectResponse
    {
        $seasonOptions = $this->seasonOptions();

        $validated = $request->validate([
            'season' => ['required', 'string', Rule::in(array_keys($seasonOptions))],
            'upload' => ['required', 'file', 'mimes:csv,zip', 'max:20480'],
        ]);

        $season      = $validated['season'];
        $seasonLabel = $seasonOptions[$season];
        $file        = $request->file('upload');

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime      = (string) $file->getMimeType();

        if ($extension === 'csv') {
            if (!in_array($mime, self::CSV_MIME_ALLOWLIST, true)) {
                return back()->withErrors(['upload' => "Il file .csv ha un tipo di contenuto inatteso ({$mime})."])->withInput();
            }

            $result = $this->handleCsvUpload($file, $season);
        } elseif ($extension === 'zip') {
            if (!in_array($mime, self::ZIP_MIME_ALLOWLIST, true)) {
                return back()->withErrors(['upload' => "Il file .zip ha un tipo di contenuto inatteso ({$mime})."])->withInput();
            }

            $result = $this->handleZipUpload($file, $season);
        } else {
            return back()->withErrors(['upload' => 'Estensione non supportata. Sono accettati solo .csv e .zip.'])->withInput();
        }

        // Upload failed structurally (invalid CSV, unsafe ZIP entry, ...): stop
        // here, nothing was saved, so no import must start.
        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return $this->finalizeUploadAndMaybeImport($season, $seasonLabel, $result, $importService);
    }

    /**
     * Upload has already been saved to disk at this point. Filesystem and DB
     * import remain two distinct steps: this only checks whether the season
     * directory is now complete (all 5 core CSVs), and if so delegates the
     * real import to HistoricalSeasonImportService — same call the manual
     * "Reimporta stagione" button and the CLI command make, nothing
     * duplicated here.
     *
     * @param  list<array{filename: string, div: string, rows: int, status: string}>  $uploadedFiles
     */
    private function finalizeUploadAndMaybeImport(
        string $season,
        string $seasonLabel,
        array $uploadedFiles,
        HistoricalSeasonImportService $importService,
    ): RedirectResponse {
        $this->flashReport($season, $seasonLabel, $uploadedFiles);

        $coreFiles   = $this->coreFilesStatus($season);
        $missingCore = array_values(array_filter($coreFiles, fn (array $f) => !$f['present']));

        if (!empty($missingCore)) {
            session()->flash('import_missing_core', $missingCore);

            return redirect()->route('admin.imports.football-data-co-uk.index', ['season' => $season]);
        }

        // Synchronous local import: 5 leagues x FDO dry-run+real can exceed the
        // default 30s max_execution_time, especially if FDO rate-limits (429 ->
        // sleep(Retry-After)). Raised only for this one request, not php.ini —
        // temporary until this moves to a queued job.
        set_time_limit(900);

        try {
            $importReport = $importService->import($season, false);
            session()->flash('import_report', $importReport);
        } catch (\Throwable $e) {
            // Unresolved teams / per-league failures are already reported inside
            // $importReport itself (status 'failed') — this catch is only for
            // truly unexpected errors. Upload has already succeeded and is not
            // rolled back: filesystem and DB are two distinct steps.
            session()->flash('import_trigger_error', "Errore inatteso durante l'import automatico: " . $e->getMessage());
        }

        return redirect()->route('admin.imports.football-data-co-uk.index', ['season' => $season]);
    }

    // -------------------------------------------------------------------------
    // CSV upload
    // -------------------------------------------------------------------------

    /**
     * @return RedirectResponse|list<array{filename: string, div: string, rows: int, status: string}>
     */
    private function handleCsvUpload(UploadedFile $file, string $season): RedirectResponse|array
    {
        $filename = basename($file->getClientOriginalName());

        if (!preg_match('/^[A-Za-z0-9._-]+\.csv$/i', $filename)) {
            return back()->withErrors(['upload' => "Nome file non valido: {$filename}"])->withInput();
        }

        try {
            $inspection = $this->inspectCsv($file->getRealPath());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['upload' => "File CSV non valido ({$filename}): {$e->getMessage()}"])->withInput();
        }

        $targetDir = $this->seasonDir($season);
        $this->files->ensureDirectoryExists($targetDir);

        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        $status     = file_exists($targetPath) ? 'sostituito' : 'nuovo';

        $file->move($targetDir, $filename);

        return [
            ['filename' => $filename, 'div' => $inspection['div'], 'rows' => $inspection['rows'], 'status' => $status],
        ];
    }

    // -------------------------------------------------------------------------
    // ZIP upload
    // -------------------------------------------------------------------------

    /**
     * @return RedirectResponse|list<array{filename: string, div: string, rows: int, status: string}>
     */
    private function handleZipUpload(UploadedFile $file, string $season): RedirectResponse|array
    {
        $tempDir = storage_path('app/tmp/fdcuk-uploads/' . (string) Str::uuid());
        $this->files->ensureDirectoryExists($tempDir);

        try {
            $zip = new ZipArchive();
            if ($zip->open($file->getRealPath()) !== true) {
                return back()->withErrors(['upload' => 'Il file ZIP non è leggibile o è corrotto.'])->withInput();
            }

            try {
                [$entries, $rejected, $conflicts] = $this->planZipExtraction($zip);

                if (!empty($rejected)) {
                    return back()->withErrors([
                        'upload' => 'ZIP rifiutato: percorsi non sicuri rilevati nelle voci: ' . implode(', ', $rejected),
                    ])->withInput();
                }

                if (!empty($conflicts)) {
                    $lines = [];
                    foreach ($conflicts as $basename => $sources) {
                        $lines[] = $basename . ' <- ' . implode(' vs ', array_unique($sources));
                    }

                    return back()->withErrors([
                        'upload' => 'ZIP rifiutato: più voci producono lo stesso file finale: ' . implode('; ', $lines),
                    ])->withInput();
                }

                if (empty($entries)) {
                    return back()->withErrors(['upload' => 'Lo ZIP non contiene alcun file .csv utilizzabile.'])->withInput();
                }

                // Extract all candidate CSVs flat into temp, then validate every one
                // before touching the final season directory (all-or-nothing).
                $inspected = [];
                foreach ($entries as $basename => $entryName) {
                    $tempFilePath = $tempDir . DIRECTORY_SEPARATOR . $basename;

                    if (!$this->extractZipEntryTo($zip, $entryName, $tempFilePath)) {
                        return back()->withErrors(['upload' => "Impossibile estrarre la voce ZIP: {$entryName}"])->withInput();
                    }

                    try {
                        $inspected[$basename] = $this->inspectCsv($tempFilePath);
                    } catch (\RuntimeException $e) {
                        return back()->withErrors([
                            'upload' => "File CSV non valido nello ZIP ({$basename}): {$e->getMessage()}",
                        ])->withInput();
                    }
                }

                // All valid — commit to the final season directory.
                $targetDir = $this->seasonDir($season);
                $this->files->ensureDirectoryExists($targetDir);

                $reportFiles = [];
                foreach ($inspected as $basename => $info) {
                    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $basename;
                    $status     = file_exists($targetPath) ? 'sostituito' : 'nuovo';

                    $this->files->copy($tempDir . DIRECTORY_SEPARATOR . $basename, $targetPath);

                    $reportFiles[] = [
                        'filename' => $basename,
                        'div'      => $info['div'],
                        'rows'     => $info['rows'],
                        'status'   => $status,
                    ];
                }

                usort($reportFiles, fn (array $a, array $b) => strcmp($a['filename'], $b['filename']));

                return $reportFiles;
            } finally {
                $zip->close();
            }
        } finally {
            $this->files->deleteDirectory($tempDir);
        }
    }

    /**
     * Walks ZIP entries and decides, for each, whether it is a safe CSV to extract.
     *
     * Never trusts an entry's own path for extraction (Zip Slip mitigation): the
     * destination filename is always the caller-controlled basename, and any entry
     * whose path looks unsafe (traversal, absolute, drive letter) is rejected
     * outright rather than silently normalised.
     *
     * @return array{0: array<string,string>, 1: string[], 2: array<string,string[]>}
     *   [basename => entryName, rejectedEntryNames, basename => [conflicting entry names]]
     */
    private function planZipExtraction(ZipArchive $zip): array
    {
        $entries   = [];
        $rejected  = [];
        $conflicts = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) {
                continue;
            }

            // Directory entries end with '/'.
            if (str_ends_with($entryName, '/')) {
                continue;
            }

            $normalized = str_replace('\\', '/', $entryName);

            if (
                str_contains($normalized, '..')
                || str_starts_with($normalized, '/')
                || preg_match('/^[A-Za-z]:/', $normalized) === 1
            ) {
                $rejected[] = $entryName;
                continue;
            }

            $basename = basename($normalized);

            if (strtolower(pathinfo($basename, PATHINFO_EXTENSION)) !== 'csv') {
                continue; // ignore non-CSV entries silently (README, images, ...)
            }

            if (!preg_match('/^[A-Za-z0-9._-]+\.csv$/i', $basename)) {
                $rejected[] = $entryName;
                continue;
            }

            if (isset($entries[$basename]) && $entries[$basename] !== $normalized) {
                $conflicts[$basename] ??= [$entries[$basename]];
                $conflicts[$basename][] = $normalized;
                continue;
            }

            $entries[$basename] = $normalized;
        }

        return [$entries, $rejected, $conflicts];
    }

    private function extractZipEntryTo(ZipArchive $zip, string $entryName, string $destinationPath): bool
    {
        $stream = $zip->getStream($entryName);
        if ($stream === false) {
            return false;
        }

        $dest = fopen($destinationPath, 'w');
        if ($dest === false) {
            fclose($stream);
            return false;
        }

        stream_copy_to_stream($stream, $dest);
        fclose($dest);
        fclose($stream);

        return true;
    }

    // -------------------------------------------------------------------------
    // CSV structural validation (raw file-manager level, not full importer parity)
    // -------------------------------------------------------------------------

    /**
     * @return array{div: string, rows: int}
     */
    private function inspectCsv(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Impossibile aprire il file.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false || trim($firstLine) === '') {
                throw new \RuntimeException('Il file è vuoto o privo di header.');
            }

            $firstLine = str_replace("\xEF\xBB\xBF", '', trim($firstLine));
            $headers   = str_getcsv($firstLine);

            $missing = array_diff(self::REQUIRED_CSV_COLUMNS, $headers);
            if (!empty($missing)) {
                throw new \RuntimeException('colonne mancanti: ' . implode(', ', $missing));
            }

            $divIndex = array_search('Div', $headers, true);
            $rows     = 0;
            $div      = null;

            while (($values = fgetcsv($handle)) !== false) {
                if ($values === [null]) {
                    continue; // blank trailing line
                }

                $rows++;
                if ($div === null && isset($values[$divIndex])) {
                    $div = trim((string) $values[$divIndex]);
                }
            }

            if ($rows === 0) {
                throw new \RuntimeException('nessuna riga dati dopo l\'header.');
            }

            return ['div' => $div !== null && $div !== '' ? $div : '?', 'rows' => $rows];
        } finally {
            fclose($handle);
        }
    }

    // -------------------------------------------------------------------------
    // Season handling
    // -------------------------------------------------------------------------

    /**
     * Generates a whitelist of selectable seasons — the last 10 plus the
     * current/next one — independent of what exists in the DB, so a season
     * can be uploaded here before it is ever imported.
     *
     * @return array<string,string> value ("2526") => label ("2025/26")
     */
    private function seasonOptions(): array
    {
        $currentStart = $this->currentSeasonStartYear();

        $options = [];
        for ($start = $currentStart + 1; $start >= $currentStart - 9; $start--) {
            $end = $start + 1;
            $value = sprintf('%02d%02d', $start % 100, $end % 100);
            $label = sprintf('%d/%02d', $start, $end % 100);
            $options[$value] = $label;
        }

        return $options;
    }

    private function currentSeasonStartYear(): int
    {
        $now = Carbon::now();

        return $now->month >= 7 ? $now->year : $now->year - 1;
    }

    private function currentSeasonValue(): string
    {
        $start = $this->currentSeasonStartYear();
        $end   = $start + 1;

        return sprintf('%02d%02d', $start % 100, $end % 100);
    }

    private function seasonDir(string $season): string
    {
        return storage_path(self::IMPORTS_SUBPATH . '/' . $season);
    }

    // -------------------------------------------------------------------------
    // Import-readiness display (read-only — no import logic here, see
    // HistoricalSeasonImportService for the actual orchestration)
    // -------------------------------------------------------------------------

    /**
     * @return list<array{div: string, name: string, filename: string, present: bool}>
     */
    private function coreFilesStatus(string $season): array
    {
        $leagues   = require config_path('imports/football-data-co-uk-leagues.php');
        $slugs     = array_column($leagues, 'slug');
        $names     = Competition::whereIn('slug', $slugs)->pluck('name', 'slug');
        $seasonDir = $this->seasonDir($season);

        $files = [];
        foreach ($leagues as $div => $cfg) {
            $files[] = [
                'div'      => $div,
                'name'     => $names[$cfg['slug']] ?? $cfg['slug'],
                'filename' => $cfg['fdcuk_file'],
                'present'  => is_file($seasonDir . DIRECTORY_SEPARATOR . $cfg['fdcuk_file']),
            ];
        }

        return $files;
    }

    /**
     * Diagnostic only — never used to gate whether a reimport is allowed.
     */
    private function seasonHasDbData(string $seasonLabel): bool
    {
        $leagues = require config_path('imports/football-data-co-uk-leagues.php');
        $slugs   = array_column($leagues, 'slug');

        return Season::where('name', $seasonLabel)
            ->whereHas('competition', fn ($q) => $q->whereIn('slug', $slugs))
            ->exists();
    }

    /**
     * @return list<array{filename: string, size: int, size_human: string, modified_at: Carbon}>
     */
    private function listExistingFiles(string $season): array
    {
        $dir = $this->seasonDir($season);
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (glob($dir . '/*.csv') ?: [] as $path) {
            $size = filesize($path) ?: 0;

            $files[] = [
                'filename'    => basename($path),
                'size'        => $size,
                'size_human'  => $this->humanFileSize($size),
                'modified_at' => Carbon::createFromTimestamp(filemtime($path) ?: time()),
            ];
        }

        usort($files, fn (array $a, array $b) => strcmp($a['filename'], $b['filename']));

        return $files;
    }

    private function humanFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $kb = $bytes / 1024;
        if ($kb < 1024) {
            return number_format($kb, 1) . ' KB';
        }

        return number_format($kb / 1024, 1) . ' MB';
    }

    /**
     * @param  list<array{filename: string, div: string, rows: int, status: string}>  $files
     */
    private function flashReport(string $season, string $seasonLabel, array $files): void
    {
        session()->flash('upload_report', [
            'season_value' => $season,
            'season_label' => $seasonLabel,
            'files'        => $files,
        ]);
    }
}
