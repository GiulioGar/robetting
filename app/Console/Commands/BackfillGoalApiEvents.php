<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\FootballMatch;
use App\Services\DataSources\GoalApi\GoalApiClient;
use App\Services\DataSources\GoalApi\GoalApiEventFiller;
use App\Services\DataSources\GoalApi\GoalApiEventParser;
use App\Services\DataSources\GoalApi\GoalApiFixtureLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillGoalApiEvents extends Command
{
    protected $signature = 'robetting:backfill-goal-api-events
                            {--dry-run : Count eligibles — zero API calls, zero DB writes}
                            {--limit=  : Max getFixture API calls for this run}
                            {--competition= : Restrict to a single competition slug}
                            {--match= : Restrict to a single canonical match ID}';

    protected $description = 'Fetch and persist goal/card/substitution events from GOAL API for finished current-season matches';

    private const COMPETITION_SLUGS = ['serie-a', 'premier-league', 'la-liga', 'bundesliga', 'ligue-1'];

    public function handle(): int
    {
        $apiKey = config('services.goal_api.api_key');

        if (empty($apiKey)) {
            $this->error('GOAL_API_KEY is not configured in .env.');
            return self::FAILURE;
        }

        $dryRun      = (bool) $this->option('dry-run');
        $limit       = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $compFilter  = $this->option('competition');
        $matchFilter = $this->option('match') !== null ? (int) $this->option('match') : null;

        $safetyLimit = (int) config('goal-api.daily_safety_limit', 50);

        $slugs = $compFilter ? [$compFilter] : self::COMPETITION_SLUGS;

        if ($compFilter && !in_array($compFilter, self::COMPETITION_SLUGS, true)) {
            $this->error("Unknown competition slug '{$compFilter}'. Valid: " . implode(', ', self::COMPETITION_SLUGS));
            return self::FAILURE;
        }

        // --match auto-restricts to that match's competition
        if ($matchFilter !== null && $compFilter === null) {
            $matchRow = FootballMatch::find($matchFilter);
            if (!$matchRow) {
                $this->error("Match ID {$matchFilter} not found in DB.");
                return self::FAILURE;
            }
            $matchComp = Competition::find($matchRow->competition_id);
            if ($matchComp && in_array($matchComp->slug, self::COMPETITION_SLUGS, true)) {
                $slugs = [$matchComp->slug];
            }
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] No API calls. No DB writes.');
        }

        $client = new GoalApiClient($apiKey, config('services.goal_api.base_url'));
        $parser = new GoalApiEventParser();
        $filler = new GoalApiEventFiller($client, $parser);

        $linker = new GoalApiFixtureLinker();

        DB::beginTransaction();
        try {
            $source = $linker->ensureDataSource();
            $this->line("[INFO]  DataSource: {$source->slug} (id={$source->id})");

            if ($dryRun) {
                $report = $filler->estimate($source, $this->output, $slugs, $matchFilter);
                DB::rollBack();
            } else {
                $report = $filler->fill($source, $safetyLimit, $limit, $this->output, $slugs, $matchFilter);
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('=== SUMMARY ===');
        $this->table(
            ['Action', 'Count'],
            [
                ['API calls made',          $report['calls_made']],
                ['Matches filled',           $report['filled']],
                ['Skipped (already synced)', $report['skipped_complete']],
                ['Skipped (no GOAL mapping)',$report['skipped_no_map']],
            ],
        );

        if ($report['remaining_quota'] !== null) {
            $this->line("Quota remaining (GOAL API): {$report['remaining_quota']}");
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] Nothing was written or fetched.');
        }

        return self::SUCCESS;
    }
}
