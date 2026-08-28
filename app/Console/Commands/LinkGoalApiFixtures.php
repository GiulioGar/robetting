<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\Season;
use App\Services\DataSources\GoalApi\GoalApiClient;
use App\Services\DataSources\GoalApi\GoalApiFixtureLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkGoalApiFixtures extends Command
{
    protected $signature = 'link:goal-api-fixtures
                            {competition : Canonical competition slug (e.g. serie-a)}
                            {--season= : Season year_start to link (e.g. 2026). Defaults to highest year_start in DB.}
                            {--dry-run : Show what would be linked without writing to DB}
                            {--limit= : Process only the first N fixtures fetched from GOAL API (before season filter)}
                            {--list-unresolved : Print team names that could not be resolved}';

    protected $description = 'Link GOAL API fixture IDs to canonical Robetting matches via competition_external_ids and match_external_ids';

    public function handle(): int
    {
        $apiKey = config('services.goal_api.api_key');

        if (empty($apiKey)) {
            $this->error('GOAL_API_KEY is not configured. Add it to .env or set $env:GOAL_API_KEY.');
            return self::FAILURE;
        }

        $competitionSlug = (string) $this->argument('competition');
        $dryRun          = (bool) $this->option('dry-run');
        $limit           = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $listUnresolved  = (bool) $this->option('list-unresolved');
        $seasonOption    = $this->option('season') !== null ? (int) $this->option('season') : null;

        // Validate competition
        $competition = Competition::where('slug', $competitionSlug)->first();

        if (!$competition) {
            $this->error("Competition '{$competitionSlug}' not found in DB.");
            return self::FAILURE;
        }

        // Resolve the target season.
        // With --season=YYYY: exact lookup by year_start (fails hard if missing).
        // Without --season: use the season with the highest year_start (most recent).
        // The MAX fallback is only for the command's "which season are we working on today"
        // decision — the linker itself uses leagueYear for exact per-fixture season resolution.
        if ($seasonOption !== null) {
            $targetSeason = Season::where('competition_id', $competition->id)
                ->where('year_start', $seasonOption)
                ->first();

            if (!$targetSeason) {
                $this->error("No season with year_start={$seasonOption} found for '{$competitionSlug}'.");
                return self::FAILURE;
            }
        } else {
            $targetSeason = Season::where('competition_id', $competition->id)
                ->orderByDesc('year_start')
                ->first();

            if (!$targetSeason) {
                $this->error("No seasons found in DB for '{$competitionSlug}'.");
                return self::FAILURE;
            }
        }

        $targetLeagueYear = $targetSeason->year_start . '/' . $targetSeason->year_end;

        // Get GOAL API league ID from config
        $leagueId = config('goal-api.league_ids', [])[$competitionSlug] ?? null;

        if (!$leagueId) {
            $this->error("No GOAL API league_id configured for '{$competitionSlug}' in config/goal-api.php.");
            return self::FAILURE;
        }

        $aliases = require config_path('team-aliases/goal-api.php');
        $client  = new GoalApiClient($apiKey, config('services.goal_api.base_url'));
        $linker  = new GoalApiFixtureLinker();

        $this->line("[INFO]  Competition  : {$competition->name} ({$competition->slug})");
        $this->line("[INFO]  Target season: {$targetSeason->name} (leagueYear={$targetLeagueYear})");
        $this->line("[INFO]  GOAL API ID  : {$leagueId}");
        $this->line("[INFO]  Dry run      : " . ($dryRun ? 'YES' : 'NO'));

        DB::beginTransaction();

        try {
            $source = $linker->ensureDataSource();
            $this->line("[INFO]  DataSource: {$source->slug} (id={$source->id})");

            $linker->ensureCompetitionExternalId($source, $leagueId, $competition->id);
            $this->line("[INFO]  competition_external_id ensured: {$leagueId} → {$competition->slug}");

            $this->line('[INFO]  Fetching fixtures from GOAL API...');
            $fixtures = $client->getAllLeagueFixtures($leagueId, $limit ?? 2000);

            if ($limit !== null) {
                $fixtures = array_slice($fixtures, 0, $limit);
            }

            $this->line(sprintf('[INFO]  Fetched %d total', count($fixtures)));

            // Filter to the target season before handing to the linker.
            // This prevents the linker from touching other seasons and keeps the
            // scope explicit — matching the intent of the run, not all seasons in DB.
            $fixtures = array_values(array_filter($fixtures, function (array $f) use ($targetLeagueYear): bool {
                return ($f['leagueYear'] ?? null) === $targetLeagueYear;
            }));

            $this->line(sprintf('[INFO]  After season filter (%s): %d fixtures', $targetLeagueYear, count($fixtures)));

            foreach ($fixtures as $fixture) {
                $linker->linkFixture($fixture, $source, $aliases);
            }

            $result = $linker->getResult();

            if ($dryRun) {
                DB::rollBack();
                $this->warn('[DRY RUN] No changes persisted to DB.');
            } else {
                DB::commit();
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Action', 'Count'],
            [
                ['already_mapped', $result['already_mapped']],
                ['linked',         $result['linked']],
                ['skipped',        $result['skipped']],
            ],
        );

        if ($listUnresolved) {
            $unresolved = $linker->getUnresolvedTeams();

            if ($unresolved) {
                $this->newLine();
                $this->warn('Unresolved team names (add to config/team-aliases/goal-api.php):');
                foreach ($unresolved as $name) {
                    $this->line("  '{$name}' => '',");
                }
            } else {
                $this->info('All team names resolved successfully.');
            }
        }

        return self::SUCCESS;
    }
}
