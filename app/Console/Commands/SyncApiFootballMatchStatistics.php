<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use Illuminate\Console\Command;

class SyncApiFootballMatchStatistics extends Command
{
    protected $signature   = 'robetting:sync-api-football-statistics';
    protected $description = 'Fetch match statistics (shots, fouls, corners, cards) for all definitive API-Football fixtures';

    public function handle(ApiFootballMatchStatisticsSyncService $service): int
    {
        $this->info('Syncing match statistics…');

        $result = $service->syncAll();

        $this->line("candidates={$result['candidates']}  created={$result['created']}  updated={$result['updated']}  unchanged={$result['unchanged']}  skipped={$result['skipped']}  api_calls={$result['api_calls']}");

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
