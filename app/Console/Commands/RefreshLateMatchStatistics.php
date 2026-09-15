<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use Illuminate\Console\Command;

class RefreshLateMatchStatistics extends Command
{
    protected $signature   = 'robetting:refresh-late-match-statistics {--season= : year_start of the target season (default: all is_current seasons)}';
    protected $description = 'Re-fetch /fixtures/statistics for v2 rows that still have null xG, respecting the configured retry interval and max-age cutoff.';

    public function handle(ApiFootballMatchStatisticsSyncService $service): int
    {
        $seasonOption = $this->option('season');
        $seasonYear   = $seasonOption !== null ? (int) $seasonOption : null;

        set_time_limit(0);

        if ($seasonYear !== null) {
            $this->info("Late enrichment refresh for season year_start={$seasonYear} …");
        } else {
            $this->info('Late enrichment refresh for all current seasons …');
        }

        $initialDelayDays = (int) config('api-football.late_stats_initial_delay_days', 2);
        $retryDays        = (int) config('api-football.late_stats_retry_days', 7);
        $maxAgeDays       = (int) config('api-football.late_stats_max_age_days', 90);
        $this->line("  Initial delay  : {$initialDelayDays} days after first fetch");
        $this->line("  Retry interval : {$retryDays} days");
        $this->line("  Max match age  : {$maxAgeDays} days");

        $result = $service->refreshLateStats($seasonYear);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Status',                    $result['status']],
                ['Candidates',                $result['candidates']],
                ['xG recovered',              $result['xg_recovered']],
                ['Goals prevented recovered', $result['goals_prevented_recovered']],
                ['Still missing advanced',    $result['still_missing_advanced']],
                ['Updated',                   $result['updated']],
                ['Failed (retry)',            $result['failed']],
                ['Skipped',                   $result['skipped']],
                ['API calls',                 $result['api_calls']],
            ],
        );

        return Command::SUCCESS;
    }
}
