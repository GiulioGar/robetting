<?php

namespace App\Console\Commands;

use App\Services\Prediction\OfficialLearningDatasetExporter;
use Illuminate\Console\Command;

/**
 * P27E1 — read-only (on the DB) export of the official learning dataset to
 * CSV, for future offline retraining. All DB access happens inside
 * OfficialLearningDatasetExporter (read-only); this command only resolves
 * CLI options and handles the CSV file write, atomically (temp file + final
 * rename only on full success — no partial CSV is ever left behind).
 *
 * Fail-closed: if the exporter cannot resolve the canonical feature schema
 * (artifact missing/invalid), export() throws and this command exits with
 * an error — never falls back to writing a CSV with an unverified schema.
 */
class ExportLearningDataset extends Command
{
    protected $signature = 'robetting:export-learning-dataset
        {--model-key=candidate47_structural_log}
        {--model-version=}
        {--output=}';

    protected $description = 'Export resolved official predictions (frozen features_json + real outcome) to a CSV learning dataset.';

    public function handle(OfficialLearningDatasetExporter $exporter): int
    {
        $modelKey = (string) $this->option('model-key');
        $modelVersionOption = $this->option('model-version');
        $modelVersionOption = $modelVersionOption !== null && $modelVersionOption !== '' ? (string) $modelVersionOption : null;

        $modelVersion = $modelVersionOption;

        if ($modelVersion === null) {
            $versions = $exporter->availableModelVersions($modelKey);

            if (count($versions) === 0) {
                $this->line("No predictions found for model_key={$modelKey}.");
                $this->line('NO EVALUABLE OFFICIAL LEARNING ROWS YET');

                return Command::SUCCESS;
            }

            if (count($versions) > 1) {
                $this->error("Multiple model_version values found for model_key={$modelKey}: "
                    . implode(', ', $versions) . '. Please specify --model-version to avoid mixing versions in one file.');

                return Command::FAILURE;
            }

            $modelVersion = $versions[0];
        }

        try {
            $result = $exporter->export($modelKey, $modelVersion);
        } catch (\Throwable $e) {
            $this->error('Export failed, no CSV written: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $outputPath = null;
        if ($result['summary']['exported'] > 0) {
            try {
                $outputPath = $this->writeCsv($result['columns'], $result['rows'], $modelKey, $modelVersion);
            } catch (\Throwable $e) {
                $this->error('Export failed, no CSV written: ' . $e->getMessage());

                return Command::FAILURE;
            }
        }

        $this->renderSummary($modelKey, $modelVersion, $result['summary'], $outputPath);

        return Command::SUCCESS;
    }

    /**
     * Writes to a temp file in the SAME target directory, then renames into
     * place only once the CSV is fully written — so a crash mid-write never
     * leaves a partial final file. Never overwrites an existing file.
     *
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $rows
     * @throws \RuntimeException
     */
    private function writeCsv(array $columns, array $rows, string $modelKey, string $modelVersion): string
    {
        $outputOption = $this->option('output');

        $targetPath = $outputOption !== null && $outputOption !== ''
            ? (string) $outputOption
            : base_path('tools/datasets') . DIRECTORY_SEPARATOR
                . sprintf('official_learning_%s_%s_%s.csv', $modelKey, $modelVersion, now()->format('Ymd_His'));

        $targetDir = dirname($targetPath);
        if (! is_dir($targetDir) && ! mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
            throw new \RuntimeException("Could not create output directory: {$targetDir}");
        }

        if (file_exists($targetPath)) {
            throw new \RuntimeException("Output file already exists, refusing to overwrite: {$targetPath}");
        }

        $tempPath = tempnam($targetDir, 'official_learning_tmp_');
        if ($tempPath === false) {
            throw new \RuntimeException("Could not create temp file in: {$targetDir}");
        }

        try {
            $handle = fopen($tempPath, 'w');
            if ($handle === false) {
                throw new \RuntimeException("Could not open temp file for writing: {$tempPath}");
            }

            fputcsv($handle, $columns);
            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $column) {
                    $line[] = $row[$column] ?? null;
                }
                fputcsv($handle, $line);
            }

            fclose($handle);

            if (file_exists($targetPath)) {
                throw new \RuntimeException("Output file already exists, refusing to overwrite: {$targetPath}");
            }

            if (! rename($tempPath, $targetPath)) {
                throw new \RuntimeException("Could not finalize output file: {$targetPath}");
            }
        } catch (\Throwable $e) {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            throw $e;
        }

        return $targetPath;
    }

    private function renderSummary(string $modelKey, string $modelVersion, array $summary, ?string $outputPath): void
    {
        $this->info("model_key={$modelKey}  model_version={$modelVersion}");
        $this->newLine();
        $this->info('=== SUMMARY ===');
        $this->line('total predictions:              ' . $summary['total']);
        $this->line('resolved:                        ' . $summary['resolved']);
        $this->line('eligible:                        ' . $summary['eligible']);
        $this->line('exported:                        ' . $summary['exported']);
        $this->line('excluded awarded/walkover:       ' . $summary['excluded_awarded_walkover']);
        $this->line('excluded older duplicates:       ' . $summary['excluded_older_duplicates']);
        $this->line('invalid feature schema:          ' . $summary['invalid_feature_schema']);
        $this->line('invalid/incomplete:              ' . $summary['invalid_incomplete']);

        if ($summary['exported'] === 0) {
            $this->newLine();
            $this->line('NO EVALUABLE OFFICIAL LEARNING ROWS YET');

            return;
        }

        $this->line('output path:                     ' . $outputPath);
    }
}
