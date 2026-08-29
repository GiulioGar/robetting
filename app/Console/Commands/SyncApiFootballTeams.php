<?php

namespace App\Console\Commands;

use App\Models\CompetitionExternalId;
use App\Models\DataSource;
use App\Services\DataSources\ApiFootball\ApiFootballTeamSyncService;
use Illuminate\Console\Command;

class SyncApiFootballTeams extends Command
{
    protected $signature = 'robetting:sync-api-football-teams
                            {--season=2026 : Season year to sync}
                            {--league=     : Specific league external ID (skips the other 4)}';

    protected $description = 'Sync teams for the 5 core competitions from API-Football';

    public function handle(ApiFootballTeamSyncService $service): int
    {
        $season = (int) $this->option('season');

        if ($leagueExtId = $this->option('league')) {
            $ds  = DataSource::where('slug', 'api-football')->firstOrFail();
            $cei = CompetitionExternalId::where('data_source_id', $ds->id)
                ->where('external_id', (string) $leagueExtId)
                ->first();

            if (!$cei) {
                $this->error("No competition_external_id found for league {$leagueExtId}");
                return self::FAILURE;
            }

            $this->info("Syncing teams for league {$leagueExtId}, season {$season}…");
            $report = $service->syncCompetition($cei, $season);
            $this->printCompetitionReport($report);
            return self::SUCCESS;
        }

        $this->info("Syncing teams for all core competitions, season {$season}…");
        $result = $service->syncAllCompetitions($season);

        foreach ($result['results'] as $report) {
            $this->printCompetitionReport($report);
        }

        $this->newLine();
        $this->line("Total created: {$result['teams_created']}  updated: {$result['teams_updated']}");

        $failed = collect($result['results'])->where('status', 'failed')->count();
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function printCompetitionReport(array $r): void
    {
        $icon = match($r['status']) {
            'ok'      => '✓',
            'skipped' => '~',
            default   => '✗',
        };

        $slug    = $r['competition_slug'] ?? '?';
        $created = $r['created']   ?? 0;
        $updated = $r['updated']   ?? 0;
        $unch    = $r['unchanged'] ?? 0;

        $line = "{$icon}  [{$r['league_id']}] {$slug}  +{$created} ~{$updated} ={$unch}";

        if (!empty($r['message'])) {
            $line .= "  ({$r['message']})";
        }

        if (!empty($r['warnings'])) {
            $line .= "  WARN:" . count($r['warnings']);
        }

        if (isset($r['requests_remaining'])) {
            $line .= "  [rem:{$r['requests_remaining']}]";
        }

        $this->line($line);

        foreach ($r['warnings'] ?? [] as $w) {
            $this->warn("    {$w}");
        }
    }
}
