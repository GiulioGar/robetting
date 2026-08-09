<?php

namespace App\Console\Commands;

use App\Services\DataSources\FootballDataCoUk\FootballDataCoUkImporter;
use App\Services\DataSources\FootballDataCoUk\TeamResolver;
use Illuminate\Console\Command;

class ImportFootballDataCoUk extends Command
{
    protected $signature = 'import:football-data-co-uk
        {file : Absolute or relative path to the CSV file}
        {--season= : Season in YYYY-YYYY format (e.g. 2025-2026) — required}
        {--competition-slug= : Canonical slug if competition not yet linked to this source (e.g. serie-a)}
        {--dry-run : Run without persisting changes}
        {--limit= : Process only the first N data rows}';

    protected $description = 'Import match data from a football-data.co.uk CSV file';

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $season   = $this->option('season');

        // --- Validate inputs ---

        if (empty($season)) {
            $this->error('--season is required. Example: --season=2025-2026');
            return self::FAILURE;
        }

        if (!preg_match('/^\d{4}-\d{4}$/', $season)) {
            $this->error("--season must be in YYYY-YYYY format (e.g. 2025-2026). Got: '{$season}'");
            return self::FAILURE;
        }

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return self::FAILURE;
        }

        if (!is_readable($filePath)) {
            $this->error("File not readable: {$filePath}");
            return self::FAILURE;
        }

        $limit = $this->option('limit');
        if ($limit !== null && (!ctype_digit((string) $limit) || (int) $limit < 1)) {
            $this->error("--limit must be a positive integer. Got: '{$limit}'");
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->line('<fg=yellow>[DRY-RUN]</> No changes will be persisted.');
        }

        // --- Build services ---

        $aliases  = require config_path('team-aliases/football-data-co-uk.php');
        $resolver = new TeamResolver($aliases, FootballDataCoUkImporter::SOURCE_SLUG);
        $importer = new FootballDataCoUkImporter($resolver, $this->output);

        // --- Run import ---

        try {
            $result = $importer->import($filePath, $season, [
                'competition_slug' => $this->option('competition-slug'),
                'dry_run'          => $dryRun,
                'limit'            => $limit !== null ? (int) $limit : null,
            ]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        // --- Summary table ---

        $this->newLine();
        $this->line($dryRun ? '<fg=yellow>--- DRY-RUN summary (nothing persisted) ---</>' : '--- Import summary ---');

        $this->table(
            ['Entity', 'Created', 'Updated', 'Skipped'],
            [
                [
                    'Teams (mappings)',
                    $result['teams']['created'],
                    $result['teams']['updated'],
                    '—',
                ],
                [
                    'Matches',
                    $result['matches']['created'],
                    $result['matches']['updated'],
                    $result['matches']['skipped'],
                ],
            ],
        );

        if ($dryRun) {
            $this->line('<fg=yellow>Dry-run complete. Re-run without --dry-run to persist.</>');
        } else {
            $this->info('Import complete.');
        }

        return self::SUCCESS;
    }
}
