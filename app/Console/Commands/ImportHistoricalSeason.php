<?php

namespace App\Console\Commands;

use App\Services\Imports\HistoricalSeasonImportService;
use Illuminate\Console\Command;

class ImportHistoricalSeason extends Command
{
    protected $signature = 'robetting:import-season
        {season : Season directory code, e.g. 2324 (= 2023/24)}
        {--dry-run : Simulate FDO + FDCUK for all 5 core leagues without persisting anything}';

    protected $description = 'Orchestrates FDO + FDCUK import for a season across the 5 core leagues (idempotent, rerunnable)';

    public function handle(HistoricalSeasonImportService $service): int
    {
        $seasonCode = (string) $this->argument('season');
        $dryRun     = (bool) $this->option('dry-run');

        try {
            $report = $service->import($seasonCode, $dryRun, $this->output);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Errore inatteso: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->renderReport($report);

        return $report['status'] === 'success' ? self::SUCCESS : self::FAILURE;
    }

    private function renderReport(array $report): void
    {
        $this->newLine();
        $this->line(
            ($report['dry_run'] ? '<fg=yellow>[DRY-RUN]</> ' : '') .
            "Stagione {$report['season']} ({$report['season_code']}) — stato batch: " . strtoupper($report['status'])
        );

        foreach ($report['leagues'] as $league) {
            $this->newLine();

            $color = match ($league['status']) {
                'success' => 'green',
                'failed'  => 'red',
                default   => 'gray',
            };

            $this->line("<fg={$color}>{$league['slug']}</> ({$league['div']} / FDO {$league['fdo_code']}) — " . strtoupper($league['status']));

            if ($league['status'] === 'skipped') {
                $this->line('  skipped — batch fermato per un errore in una lega precedente');
                continue;
            }

            if ($league['error'] !== null) {
                $this->error("  [{$league['failed_step']}] {$league['error']}");
            }

            foreach (['fdo', 'fdcuk'] as $source) {
                if (empty($league[$source])) {
                    continue;
                }

                foreach (['dry_run', 'real'] as $phase) {
                    $data = $league[$source][$phase] ?? null;
                    if ($data === null) {
                        continue;
                    }

                    $this->line("  {$source} {$phase}: " . json_encode($data));
                }
            }

            if (!empty($league['verification'])) {
                $this->line('  verification: ' . json_encode($league['verification']));
            }
        }

        $this->newLine();
    }
}
