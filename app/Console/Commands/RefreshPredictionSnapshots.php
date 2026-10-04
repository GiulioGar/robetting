<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Single operational entry point to refresh the two CURRENT snapshots consumed
 * by Candidate V2 LOG: Structural TOP25 and Latent Attack/Defence.
 *
 * Reuses the two existing, independently-tested generators as-is (no logic
 * duplicated here):
 *   - tools/scripts/p16d_generate_structural_snapshot.py   (live Transfermarkt
 *     scrape, ~5 min for 96 teams, own 3-consecutive-failure abort)
 *   - tools/scripts/generate_latent_strength_snapshot.py   (DB export via
 *     `php artisan robetting:export-latent-fit-matches` + walk-forward fit)
 *
 * Both generators already write their own snapshot atomically (temp file +
 * os.replace) and refuse to write on failure, so a failed step here never
 * corrupts or removes the previous valid snapshot — this command only runs
 * them in sequence and reports SUCCESS/FAILED per component.
 */
class RefreshPredictionSnapshots extends Command
{
    private const VALID_ONLY = ['all', 'latent', 'structural'];

    protected $signature = 'robetting:refresh-prediction-snapshots
        {--python= : Python binary to invoke. Falls back to ROBETTING_PYTHON env, then "python".}
        {--only=all : Which snapshot(s) to refresh: all, latent, structural.}';

    protected $description = 'Refresh the Structural TOP25 and/or Latent Attack/Defence CURRENT snapshots used by Candidate V2 LOG.';

    public function handle(): int
    {
        $only = (string) $this->option('only');
        if (! in_array($only, self::VALID_ONLY, true)) {
            $this->error("Invalid --only value: \"{$only}\". Expected one of: " . implode(', ', self::VALID_ONLY) . '.');
            return Command::INVALID;
        }

        $runStructural = $only === 'all' || $only === 'structural';
        $runLatent     = $only === 'all' || $only === 'latent';

        $python = (string) ($this->option('python') ?: env('ROBETTING_PYTHON', 'python'));
        $base = base_path();

        $this->info("Using python binary: {$python}");
        $this->newLine();

        $structuralOk = null;
        $latentOk = null;

        // ─── A) Structural ───────────────────────────────────────────────
        if ($runStructural) {
            $this->info('=== Structural TOP25 refresh ===');
            $structuralResult = Process::path($base)
                ->timeout(600)
                ->run([$python, 'tools/scripts/p16d_generate_structural_snapshot.py']);

            $structuralOk = $structuralResult->successful();
            $this->line($structuralResult->output());
            if (! $structuralOk) {
                $this->error($structuralResult->errorOutput());
            }
            $this->line($structuralOk ? '<info>STRUCTURAL: SUCCESS</info>' : '<error>STRUCTURAL: FAILED</error>');
            $this->newLine();
        }

        // ─── B) Latent ───────────────────────────────────────────────────
        // Pass through the exact PHP binary running THIS artisan process, so
        // the nested `php artisan robetting:export-latent-fit-matches` call
        // the latent script makes internally never falls back to a wrong
        // PHP version resolved from PATH.
        if ($runLatent) {
            $this->info('=== Latent Attack/Defence refresh ===');
            $latentResult = Process::path($base)
                ->timeout(300)
                ->run([$python, 'tools/scripts/generate_latent_strength_snapshot.py', '--php=' . PHP_BINARY]);

            $latentOk = $latentResult->successful();
            $this->line($latentResult->output());
            if (! $latentOk) {
                $this->error($latentResult->errorOutput());
            }
            $this->line($latentOk ? '<info>LATENT: SUCCESS</info>' : '<error>LATENT: FAILED</error>');
            $this->newLine();
        }

        // ─── Final state report — only for the snapshot(s) actually
        // targeted by this run (reads whatever is on disk NOW, whether it
        // was just refreshed or left untouched by a failed step) ──────────
        $this->info('=== Current snapshot metadata ===');
        if ($runStructural) {
            $this->reportStructural($base);
        }
        if ($runLatent) {
            $this->reportLatent($base);
        }

        $this->newLine();
        if ($structuralOk !== false && $latentOk !== false) {
            $this->info('RESULT: SUCCESS (' . $this->refreshedLabel($runStructural, $runLatent) . ')');
            return Command::SUCCESS;
        }

        $this->error('RESULT: FAILED — ' . implode(', ', array_filter([
            $structuralOk === false ? 'Structural failed' : null,
            $latentOk === false ? 'Latent failed' : null,
        ])));

        return Command::FAILURE;
    }

    private function refreshedLabel(bool $runStructural, bool $runLatent): string
    {
        if ($runStructural && $runLatent) {
            return 'both components refreshed';
        }

        return $runStructural ? 'Structural refreshed' : 'Latent refreshed';
    }

    private function reportStructural(string $base): void
    {
        $path = $base . '/tools/models/structural_strength_current.json';
        if (! file_exists($path)) {
            $this->warn('STRUCTURAL: no snapshot file found.');
            return;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            $this->warn('STRUCTURAL: snapshot file is not valid JSON.');
            return;
        }

        $this->line('STRUCTURAL');
        $this->line('  generated_at: ' . ($data['generated_at'] ?? 'n/a'));
        $this->line('  teams:        ' . count($data['teams'] ?? []));
        $this->line('  definition:   ' . ($data['definition'] ?? 'n/a'));
    }

    private function reportLatent(string $base): void
    {
        $path = $base . '/tools/models/latent_strength_current.json';
        if (! file_exists($path)) {
            $this->warn('LATENT: no snapshot file found.');
            return;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            $this->warn('LATENT: snapshot file is not valid JSON.');
            return;
        }

        $this->line('LATENT');
        $this->line('  generated_at:           ' . ($data['generated_at'] ?? 'n/a'));
        $this->line('  last_match_included_at: ' . ($data['last_match_included_at'] ?? 'n/a'));
        $this->line('  match_count:            ' . ($data['match_count'] ?? 'n/a'));
        $this->line('  teams:                  ' . count($data['teams'] ?? []));
    }
}
