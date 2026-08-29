<?php

namespace App\Console\Commands;

use App\Services\DataSources\ApiFootball\ApiFootballLeagueImporter;
use Illuminate\Console\Command;

class ImportApiFootballLeagues extends Command
{
    protected $signature = 'robetting:import-api-football-leagues
                            {--season=2026 : Season year to import}
                            {--league=     : Specific league ID (skips the other 4)}';

    protected $description = 'Import core league metadata from API-Football into the canonical DB';

    public function handle(ApiFootballLeagueImporter $importer): int
    {
        $season = (int) $this->option('season');

        if ($specificId = $this->option('league')) {
            $leagueId = (int) $specificId;
            $this->info("Importing league {$leagueId} for season {$season}…");
            try {
                $report = $importer->importLeague($leagueId, $season);
                $this->printLeagueReport($report);
            } catch (\Throwable $e) {
                $this->error("Failed: {$e->getMessage()}");
                return self::FAILURE;
            }
            return self::SUCCESS;
        }

        $this->info("Importing 5 core leagues for season {$season}…");
        $result = $importer->importCoreLeagues($season);

        foreach ($result['results'] as $report) {
            $this->printLeagueReport($report);
        }

        $this->newLine();
        $this->line("Requests remaining after run: " . ($result['requests_remaining'] ?? 'n/a'));

        $failed = collect($result['results'])->where('status', 'failed')->count();
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function printLeagueReport(array $r): void
    {
        $icon = match($r['status']) {
            'ok'      => '✓',
            'skipped' => '~',
            default   => '✗',
        };

        $line = "{$icon}  [{$r['league_id']}]";

        if (isset($r['slug'])) {
            $comp   = $r['competition'] ?? '';
            $season = $r['season']      ?? '?';
            $line .= " {$r['slug']} ({$comp}) — season {$season}";
        } elseif (isset($r['message'])) {
            $line .= " {$r['message']}";
        }

        if (isset($r['requests_remaining'])) {
            $line .= "  [rem: {$r['requests_remaining']}]";
        }

        $this->line($line);
    }
}
