<?php

namespace App\Console\Commands;

use App\Services\Prediction\RecentXgFeatureExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Offline xG experiment: exports the 4 recent-xG features (+ diagnostics) for
 * the exact targets of an existing dataset CSV, keyed by match_id, to be merged
 * in the Python benchmark. Read-only: no API call, no DB write, does not touch
 * HistoricalPredictionDatasetBuilder or any production model.
 */
class ExportRecentXgFeatures extends Command
{
    protected $signature = 'robetting:export-recent-xg-features
        {--dataset=tools/datasets/dataset_core_v1_team_history_2024_2025.csv : Target dataset CSV (match_id column, order preserved)}
        {--output=tools/datasets/recent_xg_features_2024_2025.csv : Output CSV path}
        {--force : Overwrite the output file if it exists}';

    protected $description = 'Export recent-xG experiment features (Team History window, 120h cutoff) keyed by match_id for the targets of a dataset CSV.';

    public function handle(RecentXgFeatureExporter $exporter): int
    {
        $dataset = $this->resolve((string) $this->option('dataset'));
        $output  = $this->resolve((string) $this->option('output'));

        if (! is_file($dataset)) {
            $this->error("Dataset not found: {$dataset}");
            return self::FAILURE;
        }
        if (is_file($output) && ! $this->option('force')) {
            $this->error("Output exists: {$output} (use --force to overwrite).");
            return self::FAILURE;
        }

        $matchIds = $this->readMatchIds($dataset);
        if ($matchIds === null) {
            $this->error('Dataset CSV has no match_id column.');
            return self::FAILURE;
        }

        $this->info('Exporting recent xG features for ' . count($matchIds) . ' target matches …');

        $rows = $exporter->export($matchIds);

        File::ensureDirectoryExists(dirname($output));
        $fh = fopen($output, 'wb');
        fputcsv($fh, RecentXgFeatureExporter::COLUMNS);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(
                fn (string $col) => $row[$col] === null ? '' : $row[$col],
                RecentXgFeatureExporter::COLUMNS
            ));
        }
        fclose($fh);

        $withXg = count(array_filter($rows, fn ($r) => $r['home_recent_avg_xg_for'] !== null && $r['away_recent_avg_xg_for'] !== null));
        $this->info('Written: ' . $output);
        $this->line('Rows: ' . count($rows) . '  | both sides with xG: ' . $withXg);

        return self::SUCCESS;
    }

    private function resolve(string $path): string
    {
        return preg_match('#^([A-Za-z]:)?[\\\\/]#', $path) ? $path : base_path($path);
    }

    /** @return array<int, int>|null */
    private function readMatchIds(string $csv): ?array
    {
        $fh     = fopen($csv, 'rb');
        $header = fgetcsv($fh);
        $idx    = is_array($header) ? array_search('match_id', $header, true) : false;
        if ($idx === false) {
            fclose($fh);
            return null;
        }

        $ids = [];
        while (($row = fgetcsv($fh)) !== false) {
            if (isset($row[$idx]) && $row[$idx] !== '') {
                $ids[] = (int) $row[$idx];
            }
        }
        fclose($fh);

        return $ids;
    }
}
