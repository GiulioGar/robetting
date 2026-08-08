<?php

namespace App\Console\Commands;

use App\Services\DataSources\FootballDataOrg\FootballDataOrgClient;
use App\Services\DataSources\FootballDataOrg\FootballDataOrgImporter;
use Illuminate\Console\Command;

class ImportFootballDataOrg extends Command
{
    protected $signature = 'import:football-data-org
                            {competition : Competition code (e.g. SA for Serie A)}
                            {season : Season start year (e.g. 2024)}
                            {--slug= : Canonical slug to assign if competition does not yet exist in DB}
                            {--dry-run : Run the import without persisting any changes}
                            {--limit= : Process only the first N matches}';

    protected $description = 'Import a competition season from football-data.org';

    public function handle(): int
    {
        $apiKey = config('services.football_data_org.api_key');

        if (empty($apiKey)) {
            $this->error('FOOTBALL_DATA_ORG_API_KEY is not configured in .env');
            return self::FAILURE;
        }

        $competitionCode = strtoupper((string) $this->argument('competition'));
        $season          = (int) $this->argument('season');
        $slug            = $this->option('slug') ?: null;
        $dryRun          = (bool) $this->option('dry-run');
        $limit           = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $client = new FootballDataOrgClient(
            config('services.football_data_org.base_url'),
            $apiKey,
        );

        $importer = new FootballDataOrgImporter($client, $this->output);

        try {
            $result = $importer->import($competitionCode, $season, [
                'slug'    => $slug,
                'dry_run' => $dryRun,
                'limit'   => $limit,
            ]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();

        if ($dryRun) {
            $this->warn('[DRY RUN] No changes were persisted to the database.');
        }

        $this->table(
            ['Entity', 'Created', 'Updated', 'Skipped'],
            [
                ['Countries', $result['countries']['created'], $result['countries']['updated'], '—'],
                ['Teams',     $result['teams']['created'],     $result['teams']['updated'],     '—'],
                ['Matches',   $result['matches']['created'],   $result['matches']['updated'],   $result['matches']['skipped']],
            ]
        );

        return self::SUCCESS;
    }
}
