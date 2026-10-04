<?php

namespace App\Console\Commands;

use App\Services\Prediction\LatentFitMatchEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Read-only operational status for the two CURRENT snapshots consumed by
 * Candidate V2 LOG (Structural TOP25 and Latent Attack/Defence).
 *
 * Does NOT regenerate anything, does NOT touch the DB beyond a read query,
 * does NOT compute any match prediction. Pure reporting, so an operator can
 * decide whether `robetting:refresh-prediction-snapshots` is needed — this
 * command never runs it itself.
 */
class PredictionSnapshotsStatus extends Command
{
    protected $signature = 'robetting:prediction-snapshots-status';

    protected $description = 'Report the current freshness/validity of the Structural and Latent CURRENT snapshots used by Candidate V2 LOG.';

    private const LATENT_FROM_SEASON = 2024;

    private static ?string $snapshotsDir = null;

    /** Override snapshots directory (for unit tests, mirrors CandidateModelService::setArtifactDir). */
    public static function setSnapshotsDir(string $dir): void
    {
        self::$snapshotsDir = rtrim($dir, '/\\');
    }

    /** Reset override (call in tearDown). */
    public static function resetSnapshotsDir(): void
    {
        self::$snapshotsDir = null;
    }

    private function snapshotsDir(): string
    {
        return self::$snapshotsDir ?? base_path('tools/models');
    }

    public function handle(): int
    {
        $latentOk = $this->latentStatus();
        $this->newLine();
        $structuralOk = $this->structuralStatus();
        $this->newLine();
        $this->candidateV2LogStatus($structuralOk, $latentOk);

        return Command::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. LATENT STATUS
    // ─────────────────────────────────────────────────────────────────────

    private function latentStatus(): bool
    {
        $this->info('=== LATENT ===');

        $path = $this->snapshotsDir() . '/latent_strength_current.json';
        $snapshot = $this->readJson($path, 'LATENT');
        if ($snapshot === null) {
            return false;
        }

        $this->line('generated_at:           ' . ($snapshot['generated_at'] ?? 'n/a'));
        $this->line('last_match_included_at: ' . ($snapshot['last_match_included_at'] ?? 'n/a'));
        $this->line('match_count:            ' . ($snapshot['match_count'] ?? 'n/a'));
        $this->line('teams:                  ' . count($snapshot['teams'] ?? []));
        $this->line('source:                 ' . ($snapshot['source'] ?? 'n/a'));
        if (isset($snapshot['model_version'])) {
            $this->line('model_version:          ' . $snapshot['model_version']);
        }

        if (! isset($snapshot['last_match_included_at'], $snapshot['match_count'])) {
            $this->warn('STATUS: INVALID — snapshot missing last_match_included_at/match_count.');
            return false;
        }

        $now = CarbonImmutable::now('UTC');
        $eligibility = LatentFitMatchEligibility::countAndLatestKickoff($now, self::LATENT_FROM_SEASON);

        $dbCount = $eligibility['count'];
        $dbLatest = $eligibility['latest_kickoff_at'];

        $this->newLine();
        $this->line('DB eligible matches now: ' . $dbCount);
        $this->line('DB latest eligible kickoff: ' . ($dbLatest ?? 'n/a'));

        $snapshotCount = (int) $snapshot['match_count'];
        $snapshotLatest = CarbonImmutable::parse($snapshot['last_match_included_at']);
        $latestOk = $dbLatest === null || CarbonImmutable::parse($dbLatest)->lte($snapshotLatest);
        $countOk = $dbCount === $snapshotCount;

        if ($countOk && $latestOk) {
            $this->info('STATUS: CURRENT');
            return true;
        }

        $this->error('STATUS: STALE');
        $this->line('  snapshot match_count=' . $snapshotCount . '  DB eligible=' . $dbCount
            . '  (missing=' . max(0, $dbCount - $snapshotCount) . ')');
        $this->line('  snapshot last_match_included_at=' . $snapshotLatest->toIso8601String()
            . '  DB latest eligible kickoff=' . ($dbLatest ?? 'n/a'));
        $this->line('  Suggested: php artisan robetting:refresh-prediction-snapshots --only=latent');

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. STRUCTURAL STATUS
    // ─────────────────────────────────────────────────────────────────────

    private function structuralStatus(): bool
    {
        $this->info('=== STRUCTURAL ===');

        $path = $this->snapshotsDir() . '/structural_strength_current.json';
        $snapshot = $this->readJson($path, 'STRUCTURAL');
        if ($snapshot === null) {
            return false;
        }

        $this->line('generated_at: ' . ($snapshot['generated_at'] ?? 'n/a'));
        $this->line('teams:        ' . count($snapshot['teams'] ?? []));
        $this->line('definition:   ' . ($snapshot['definition'] ?? 'n/a'));

        if (! isset($snapshot['generated_at'], $snapshot['teams']) || ! is_array($snapshot['teams']) || $snapshot['teams'] === []) {
            $this->warn('STATUS: INVALID — snapshot missing generated_at/teams.');
            return false;
        }

        $generatedAt = CarbonImmutable::parse($snapshot['generated_at']);
        $age = $generatedAt->diffForHumans(CarbonImmutable::now('UTC'), true);
        $this->line('age: ' . $age);

        $this->info('STATUS: AVAILABLE');
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. CANDIDATE V2 LOG
    // ─────────────────────────────────────────────────────────────────────

    private function candidateV2LogStatus(bool $structuralOk, bool $latentOk): void
    {
        $this->info('=== CANDIDATE V2 LOG ===');

        if (! $structuralOk) {
            $this->error('CANDIDATE V2 LOG: NOT READY — Structural snapshot not loadable.');
            return;
        }

        if (! $latentOk) {
            $this->error('CANDIDATE V2 LOG: NOT READY — Latent snapshot not loadable.');
            return;
        }

        // Both snapshot files already confirmed loadable (valid JSON, expected
        // shape) by structuralStatus()/latentStatus() above — no prediction
        // is computed here, only file/shape availability is confirmed.
        $this->info('CANDIDATE V2 LOG: READY');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Shared helpers
    // ─────────────────────────────────────────────────────────────────────

    private function readJson(string $path, string $label): ?array
    {
        if (! file_exists($path)) {
            $this->error("STATUS: MISSING — {$label} snapshot file not found ({$path}).");
            return null;
        }

        $json = file_get_contents($path);
        $data = $json === false ? null : json_decode($json, true);

        if (! is_array($data)) {
            $this->error("STATUS: INVALID — {$label} snapshot is not valid JSON ({$path}).");
            return null;
        }

        return $data;
    }
}
