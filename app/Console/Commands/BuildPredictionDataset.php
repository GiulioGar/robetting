<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Models\Season;
use App\Services\Prediction\HistoricalPredictionDatasetBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BuildPredictionDataset extends Command
{
    protected $signature = 'robetting:build-prediction-dataset
        {--mode=core_only : Feature set mode (core_only|core_plus_experimental)}
        {--seasons=       : Comma-separated year_start values (e.g. 2024,2025). Omit to include all seasons.}
        {--force          : Overwrite output file if it already exists}';

    protected $description = 'Build a historical prediction dataset CSV from definitively-scored matches.';

    // Mirrors MatchOutcomeLabelBuilder::DEFINITIVE_STATUSES — keep in sync.
    private const DEFINITIVE_STATUSES = ['finished', 'awarded', 'walkover'];

    private const VALID_MODES = ['core_only', 'core_plus_experimental'];

    private const OUTPUT_DIR = 'tools/datasets';

    public function handle(): int
    {
        $mode = (string) $this->option('mode');

        if (! in_array($mode, self::VALID_MODES, true)) {
            $this->error(
                "Invalid mode '{$mode}'. Accepted: " . implode(', ', self::VALID_MODES)
            );
            return Command::FAILURE;
        }

        $matches = $this->loadMatches();

        if ($matches->isEmpty()) {
            $this->warn('No matches found for the given criteria. Nothing to build.');
            return Command::SUCCESS;
        }

        $version = $this->resolveVersion($mode);
        $outPath = $this->resolveOutputPath($version);

        if (File::exists($outPath) && ! $this->option('force')) {
            $this->error("Output file already exists: {$outPath}");
            $this->error('Use --force to overwrite.');
            return Command::FAILURE;
        }

        $this->info(
            "Building [{$mode}] dataset — {$matches->count()} candidate matches …"
        );

        $start   = microtime(true);
        $dataset = (new HistoricalPredictionDatasetBuilder())->build($matches, $mode);
        $elapsed = round(microtime(true) - $start, 1);

        $dir = dirname($outPath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $this->writeCsv($outPath, $dataset['rows']);

        $meta = $dataset['metadata'];
        $this->table(
            ['Metric', 'Value'],
            [
                ['Requested matches',    $meta['requested_matches']],
                ['Built rows',           $meta['built_rows']],
                ['Skipped (invalid)',    $meta['skipped_invalid_label']],
                ['Feature count',        $meta['feature_count']],
                ['Feature set version',  $meta['feature_set_version']],
                ['Output file',          $outPath],
                ['Elapsed',              "{$elapsed}s"],
            ]
        );

        return Command::SUCCESS;
    }

    private function loadMatches(): \Illuminate\Support\Collection
    {
        $seasonsRaw = $this->option('seasons');
        $seasonIds  = null;

        if ($seasonsRaw !== null && $seasonsRaw !== '') {
            $years     = array_values(array_filter(
                array_map('intval', explode(',', $seasonsRaw))
            ));
            $seasonIds = Season::whereIn('year_start', $years)->pluck('id');

            if ($seasonIds->isEmpty()) {
                $this->warn(
                    'No seasons found for year_start: ' . implode(', ', $years)
                );
                return collect();
            }
        }

        $query = FootballMatch::whereIn('status', self::DEFINITIVE_STATUSES)
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft');

        if ($seasonIds !== null) {
            $query->whereIn('season_id', $seasonIds);
        }

        return $query->orderBy('kickoff_at')->orderBy('id')->get();
    }

    private function resolveVersion(string $mode): string
    {
        return match ($mode) {
            'core_only'              => HistoricalPredictionDatasetBuilder::VERSION_CORE_ONLY,
            'core_plus_experimental' => HistoricalPredictionDatasetBuilder::VERSION_CORE_PLUS_EXPERIMENTAL,
            default                  => $mode,
        };
    }

    private function resolveOutputPath(string $version): string
    {
        $seasonsRaw = $this->option('seasons');
        $suffix     = '';

        if ($seasonsRaw !== null && $seasonsRaw !== '') {
            $years = array_values(array_filter(
                array_map('intval', explode(',', $seasonsRaw))
            ));
            sort($years);
            $suffix = '_' . implode('_', $years);
        }

        return base_path(self::OUTPUT_DIR) . DIRECTORY_SEPARATOR . "dataset_{$version}{$suffix}.csv";
    }

    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');

        if (! empty($rows)) {
            fputcsv($handle, array_keys($rows[0]));

            foreach ($rows as $row) {
                fputcsv($handle, array_map(
                    static fn ($v) => $v === null ? '' : $v,
                    $row
                ));
            }
        }

        fclose($handle);
    }
}
