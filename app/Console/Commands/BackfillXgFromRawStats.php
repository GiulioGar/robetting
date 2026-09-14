<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\XgRawStatsBackfillService;
use Illuminate\Console\Command;

class BackfillXgFromRawStats extends Command
{
    protected $signature   = 'robetting:backfill-xg-from-raw-stats {--force : Overwrite existing non-null xG values}';
    protected $description = 'Populate home/away_expected_goals and home/away_goals_prevented from existing raw_stats JSON — no API calls.';

    public function handle(XgRawStatsBackfillService $service): int
    {
        $force = (bool) $this->option('force');

        if ($force) {
            $this->warn('--force: existing xG values will be overwritten.');
        }

        $this->info('Backfilling xG columns from raw_stats (no API calls) …');

        $result = $service->backfill(force: $force);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Scanned',                $result['scanned']],
                ['Updated',                $result['updated']],
                ['Unchanged',              $result['unchanged']],
                ['Missing xG',             $result['missing_xg']],
                ['Missing goals_prevented',$result['missing_goals_prevented']],
            ],
        );

        return Command::SUCCESS;
    }
}
