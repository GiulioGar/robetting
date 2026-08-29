<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballResultRefreshService;
use Illuminate\Console\Command;

class SyncApiFootballResults extends Command
{
    protected $signature = 'robetting:sync-api-football-results';
    protected $description = 'Refresh scores for in-progress and soon-starting matches (5-min cadence)';

    public function handle(ApiFootballResultRefreshService $service): int
    {
        $result = $service->refresh();

        if ($result['candidates'] === 0) {
            $this->line('No candidates — zero API calls.');
            return self::SUCCESS;
        }

        $line = "Candidates: {$result['candidates']}  Updated: {$result['updated']}  API calls: {$result['api_calls']}";
        if ($result['daily_remaining'] !== null) {
            $line .= "  [rem:{$result['daily_remaining']}]";
        }
        $this->line($line);

        return self::SUCCESS;
    }
}
