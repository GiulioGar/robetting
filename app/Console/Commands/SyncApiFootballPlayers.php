<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballPlayerSyncService;
use Illuminate\Console\Command;

class SyncApiFootballPlayers extends Command
{
    protected $signature = 'robetting:sync-api-football-players
                            {--season=2026 : year_start of the target season (e.g. 2026)}
                            {--league=     : competition slug to limit sync to (e.g. serie-a). Optional.}';

    protected $description = 'Sync player master data and squad memberships from API-Football for all teams in the target season.';

    public function handle(ApiFootballPlayerSyncService $service): int
    {
        $seasonYear = (int) $this->option('season');
        $leagueSlug = $this->option('league') ?: null;

        set_time_limit(0);

        $scope = $leagueSlug ? " (league: {$leagueSlug})" : '';
        $this->info("Syncing api-football players for season {$seasonYear}{$scope} …");

        $result = $service->syncSeason($seasonYear, $leagueSlug);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Teams processed',       $result['teams_processed']],
                ['Players created',       $result['players_created']],
                ['Players updated',       $result['players_updated']],
                ['Memberships created',   $result['memberships_created']],
                ['Memberships unchanged', $result['memberships_unchanged']],
                ['API calls',             $result['api_calls']],
                ['Daily remaining',       $result['daily_remaining'] ?? '—'],
                ['Warnings',              count($result['warnings'])],
            ],
        );

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        return Command::SUCCESS;
    }
}
