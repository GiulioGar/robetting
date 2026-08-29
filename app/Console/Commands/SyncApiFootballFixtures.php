<?php

namespace App\Console\Commands;

use App\Models\CompetitionExternalId;
use App\Models\DataSource;
use App\Services\DataSources\ApiFootball\ApiFootballFixtureSyncService;
use Illuminate\Console\Command;

class SyncApiFootballFixtures extends Command
{
    protected $signature = 'robetting:sync-api-football-fixtures
                            {--season=2026       : Season year to sync}
                            {--mode=full         : Sync mode: full or refresh}
                            {--league=           : Specific league external ID (skips the other 4)}';

    protected $description = 'Sync fixture calendar for core competitions from API-Football';

    public function handle(ApiFootballFixtureSyncService $service): int
    {
        $season = (int)    $this->option('season');
        $mode   = (string) $this->option('mode');

        if (!in_array($mode, [ApiFootballFixtureSyncService::MODE_FULL, ApiFootballFixtureSyncService::MODE_REFRESH], true)) {
            $this->error("Invalid mode '{$mode}'. Use 'full' or 'refresh'.");
            return self::FAILURE;
        }

        if ($leagueExtId = $this->option('league')) {
            $ds  = DataSource::where('slug', 'api-football')->firstOrFail();
            $cei = CompetitionExternalId::where('data_source_id', $ds->id)
                ->where('external_id', (string) $leagueExtId)
                ->first();

            if (!$cei) {
                $this->error("No competition_external_id found for league {$leagueExtId}");
                return self::FAILURE;
            }

            $this->info("Syncing fixtures for league {$leagueExtId} ({$mode}), season {$season}…");
            $report = $service->syncCompetition($cei, $season, $mode);
            $this->printReport($report);
            return self::SUCCESS;
        }

        $this->info("Syncing fixtures for all core competitions ({$mode}), season {$season}…");
        $result = $service->syncAllCompetitions($season, $mode);

        foreach ($result['results'] as $report) {
            $this->printReport($report);
        }

        $this->newLine();
        $this->line("Total created: {$result['fixtures_created']}  updated: {$result['fixtures_updated']}");

        $failed = collect($result['results'])->where('status', 'failed')->count();
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function printReport(array $r): void
    {
        $icon = match ($r['status']) {
            'ok'      => '✓',
            'skipped' => '~',
            default   => '✗',
        };

        $slug    = $r['competition_slug'] ?? '?';
        $created = $r['created']   ?? 0;
        $updated = $r['updated']   ?? 0;
        $unch    = $r['unchanged'] ?? 0;
        $skip    = $r['skipped']   ?? 0;

        $line = "{$icon}  [{$r['league_id']}] {$slug}  +{$created} ~{$updated} ={$unch} >{$skip}";

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
