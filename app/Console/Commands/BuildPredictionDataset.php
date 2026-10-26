<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Services\Analytics\PreMatchFeatureAggregator;
use App\Services\Prediction\HistoricalPredictionDatasetBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BuildPredictionDataset extends Command
{
    protected $signature = 'robetting:build-prediction-dataset
        {--mode=core_only : Feature set mode (core_only|core_plus_experimental)}
        {--seasons=       : Comma-separated year_start values (e.g. 2024,2025). Omit to include all seasons.}
        {--recent-window-mode=legacy : Recent-window source for core_recent_* only (legacy|team_history)}
        {--elo-mode=legacy : Elo replay scope for E6 Elo features only (legacy|network|extended)}
        {--output=        : Override output CSV path. Omit to use the default filename convention.}
        {--force          : Overwrite output file if it already exists}';

    protected $description = 'Build a historical prediction dataset CSV from definitively-scored matches.';

    // V1 training policy: only matches fully completed on the pitch.
    // awarded/walkover are valid DB statuses but excluded from ML labels V1.
    private const TRAINING_STATUS = 'finished';

    private const VALID_MODES = ['core_only', 'core_plus_experimental'];

    private const VALID_RECENT_WINDOW_MODES = [
        PreMatchFeatureAggregator::RECENT_WINDOW_LEGACY,
        PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY,
    ];

    private const VALID_ELO_MODES = [
        PreMatchFeatureAggregator::ELO_MODE_LEGACY,
        PreMatchFeatureAggregator::ELO_MODE_NETWORK,
        PreMatchFeatureAggregator::ELO_MODE_EXTENDED,
    ];

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

        $recentWindowMode = (string) $this->option('recent-window-mode');

        if (! in_array($recentWindowMode, self::VALID_RECENT_WINDOW_MODES, true)) {
            $this->error(
                "Invalid recent-window-mode '{$recentWindowMode}'. Accepted: " . implode(', ', self::VALID_RECENT_WINDOW_MODES)
            );
            return Command::FAILURE;
        }

        $eloMode = (string) $this->option('elo-mode');

        if (! in_array($eloMode, self::VALID_ELO_MODES, true)) {
            $this->error(
                "Invalid elo-mode '{$eloMode}'. Accepted: " . implode(', ', self::VALID_ELO_MODES)
            );
            return Command::FAILURE;
        }

        $matches = $this->loadMatches();

        if ($matches->isEmpty()) {
            $this->warn('No matches found for the given criteria. Nothing to build.');
            return Command::SUCCESS;
        }

        $version        = $this->resolveVersion($mode);
        $outputOverride = $this->option('output');
        $outPath        = $outputOverride !== null && $outputOverride !== ''
            ? $outputOverride
            : $this->resolveOutputPath($version, $recentWindowMode);

        if (File::exists($outPath) && ! $this->option('force')) {
            $this->error("Output file already exists: {$outPath}");
            $this->error('Use --force to overwrite.');
            return Command::FAILURE;
        }

        $this->info(
            "Building [{$mode}] dataset (recent-window-mode={$recentWindowMode}, elo-mode={$eloMode}) — {$matches->count()} candidate matches …"
        );

        $start   = microtime(true);
        $dataset = (new HistoricalPredictionDatasetBuilder())->build($matches, $mode, $recentWindowMode, $eloMode);
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

        // core_only targets are ALWAYS restricted to the 5 core leagues (config('api-football.core_leagues'),
        // matched via Competition.slug) — never UEFA/national-cup competitions, regardless of --seasons.
        // This keeps the target match set identical for --recent-window-mode=legacy and =team_history;
        // only the HISTORY feeding core_recent_* (via TeamHistoryMatchEligibility inside the aggregator)
        // is allowed to reach into cup/UEFA data.
        $coreCompetitionIds = Competition::whereIn('slug', array_values(config('api-football.core_leagues')))
            ->pluck('id');

        $query = FootballMatch::where('status', self::TRAINING_STATUS)
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->whereIn('competition_id', $coreCompetitionIds);

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

    private function resolveOutputPath(string $version, string $recentWindowMode): string
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

        // team_history is opt-in and must never collide with the legacy filename
        // (the current Candidate47 dataset) — see Step C2.2.
        $modeSuffix = $recentWindowMode === PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY
            ? '_team_history'
            : '';

        return base_path(self::OUTPUT_DIR) . DIRECTORY_SEPARATOR . "dataset_{$version}{$modeSuffix}{$suffix}.csv";
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
