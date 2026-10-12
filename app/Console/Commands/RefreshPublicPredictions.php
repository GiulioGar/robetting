<?php

namespace App\Console\Commands;

use App\Services\Prediction\PublicPredictionRefreshService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Step 1A.4 — refresh PUBLIC_LATEST (portal-facing) candidate47_structural_log
 * predictions for eligible future fixtures. DB-only computation (no API
 * calls). Idempotent PER MATCH PER DAY (Europe/Rome): a match already
 * refreshed today is skipped without re-running the pipeline; an interrupted
 * run can always be safely re-launched, it will only complete whatever is
 * still missing/stale for today.
 *
 * Never touches purpose=official rows, never falls back to FULL59, never
 * writes a placeholder prediction when Candidate47 is unavailable.
 */
class RefreshPublicPredictions extends Command
{
    protected $signature = 'robetting:refresh-public-predictions
        {--days=30 : Rolling horizon in days for eligible future fixtures (5 core leagues, current season, status scheduled).}';

    protected $description = 'Refresh PUBLIC_LATEST candidate47_structural_log predictions for eligible future fixtures (idempotent per match per day).';

    public function handle(PublicPredictionRefreshService $service): int
    {
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $this->error('--days must be a positive integer.');
            return Command::INVALID;
        }

        $now = CarbonImmutable::now('UTC');

        $this->info("Refreshing PUBLIC_LATEST predictions — horizon {$days} days from {$now->toIso8601String()}.");

        $counts = $service->refresh($now, $days);

        $this->line("eligible:       {$counts['eligible']}");
        $this->line("created:        {$counts['created']}");
        $this->line("updated:        {$counts['updated']}");
        $this->line("skipped_today:  {$counts['skipped_today']}");
        $this->line("unavailable:    {$counts['unavailable']}");
        $this->line("errors:         {$counts['errors']}");

        return Command::SUCCESS;
    }
}
