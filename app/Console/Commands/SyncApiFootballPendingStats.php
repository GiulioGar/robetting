<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use Illuminate\Console\Command;

class SyncApiFootballPendingStats extends Command
{
    protected $signature = 'robetting:sync-api-football-pending-stats
        {--grace=10 : Minutes to wait after typical match end before fetching stats}';

    protected $description = 'Fetch statistics for definitive matches past the grace period without fetched_at';

    public function handle(ApiFootballMatchStatisticsSyncService $service): int
    {
        $grace  = max(0, (int) $this->option('grace'));
        $result = $service->syncPending(gracePeriodMinutes: $grace);

        if ($result['candidates'] === 0) {
            $this->line('No pending statistics candidates.');
            return self::SUCCESS;
        }

        $this->line(
            "Candidates: {$result['candidates']}  Synced: {$result['synced']}"
            . "  Skipped: {$result['skipped']}  Failed: {$result['failed']}"
            . "  API calls: {$result['api_calls']}"
        );

        return self::SUCCESS;
    }
}
