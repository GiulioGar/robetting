<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\Season;
use App\Services\DataSources\Highlightly\HighlightlyClient;
use App\Services\DataSources\Highlightly\HighlightlyFixtureLinker;
use App\Services\DataSources\Highlightly\HighlightlyStatsParser;
use App\Services\DataSources\Highlightly\HighlightlyStatsFiller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillHighlightlyStats extends Command
{
    protected $signature = 'robetting:backfill-highlightly-stats
                            {--dry-run : Estimate what would be processed — no API calls, no DB writes}
                            {--limit=  : Max Highlightly API calls to make in this run}
                            {--competition= : Restrict to a single canonical competition slug}
                            {--match= : Restrict to a single canonical match ID (linking + stats)}
                            {--skip-linking : Skip the match-linking phase and go straight to stats filling}
                            {--list-unresolved : Print team names that could not be resolved after linking}';

    protected $description = 'Link Highlightly match IDs and backfill match_statistics for finished matches (current season)';

    private const YEAR_START        = 2026;
    private const COMPETITION_SLUGS = ['serie-a', 'premier-league', 'la-liga', 'bundesliga', 'ligue-1'];

    public function handle(): int
    {
        $apiKey = config('services.highlightly.api_key');

        if (empty($apiKey)) {
            $this->error('HIGHLIGHTLY_API_KEY is not set. Add it to .env.');
            return self::FAILURE;
        }

        $dryRun        = (bool) $this->option('dry-run');
        $limit         = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $compFilter    = $this->option('competition');
        $matchFilter   = $this->option('match') !== null ? (int) $this->option('match') : null;
        $skipLinking   = (bool) $this->option('skip-linking');
        $listUnresolved = (bool) $this->option('list-unresolved');

        $safetyLimit   = (int) config('highlightly.daily_safety_limit', 75);
        $leagueIds     = (array) config('highlightly.league_ids', []);

        $slugs = $compFilter ? [$compFilter] : self::COMPETITION_SLUGS;

        // --match auto-restricts to that match's competition (avoids processing all 5 leagues)
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

        if ($compFilter && !in_array($compFilter, self::COMPETITION_SLUGS, true)) {
            $this->error("Unknown competition slug '{$compFilter}'. Valid: " . implode(', ', self::COMPETITION_SLUGS));
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] No API calls. No DB writes.');
        }

        $client  = new HighlightlyClient($apiKey, config('highlightly.base_url'));
        $linker  = new HighlightlyFixtureLinker();
        $parser  = new HighlightlyStatsParser();
        $filler  = new HighlightlyStatsFiller($client, $parser);
        $aliases = require config_path('team-aliases/highlightly.php');

        // ── 1. Ensure DataSource + competition_external_ids ──────────────────
        DB::beginTransaction();
        try {
            $source = $linker->ensureDataSource();
            $this->line("[INFO]  DataSource: {$source->slug} (id={$source->id})");

            foreach ($slugs as $slug) {
                $hlLeagueId = $leagueIds[$slug] ?? null;
                $competition = Competition::where('slug', $slug)->first();

                if (!$hlLeagueId || !$competition) {
                    $this->warn("[WARN]  {$slug}: missing league_id in config or not in DB — skip");
                    continue;
                }

                if (!$dryRun) {
                    $linker->ensureCompetitionExternalId($source, $hlLeagueId, $competition->id);
                }

                $this->line("[INFO]  {$slug}: competition_external_id hl_id={$hlLeagueId} → comp_id={$competition->id}");
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        // ── 2. Match linking (fetch HL matches by date and link to canonical) ─
        $this->newLine();
        $this->line('[LINK]  Scanning for unlinked matches...');

        $linkCallsMade  = 0;
        $linkLinked     = 0;
        $linkAlready    = 0;
        $linkSkipped    = 0;

        if ($skipLinking) {
            $this->line('[LINK]  --skip-linking: skipping link phase.');
        } elseif ($dryRun) {
            // In dry-run: count unlinked without API calls
            foreach ($slugs as $slug) {
                $competition = Competition::where('slug', $slug)->first();
                if (!$competition) continue;
                $season = Season::where('competition_id', $competition->id)
                    ->where('year_start', self::YEAR_START)->first();
                if (!$season) continue;

                $unmappedCount = FootballMatch::where('competition_id', $competition->id)
                    ->where('season_id', $season->id)
                    ->when($matchFilter !== null, fn($q) => $q->where('id', $matchFilter))
                    ->whereNotIn('id', function ($q) use ($source) {
                        $q->select('match_id')
                          ->from('match_external_ids')
                          ->where('data_source_id', $source->id);
                    })
                    ->count();

                $this->line("[DRY]   {$slug}: {$unmappedCount} matches without HL mapping");
            }
        } else {
            foreach ($slugs as $slug) {
                $hlLeagueId  = $leagueIds[$slug] ?? null;
                $competition = Competition::where('slug', $slug)->first();
                if (!$hlLeagueId || !$competition) continue;

                $season = Season::where('competition_id', $competition->id)
                    ->where('year_start', self::YEAR_START)->first();
                if (!$season) {
                    $this->warn("[WARN]  {$slug}: no 2026/27 season in DB");
                    continue;
                }

                $result = $this->linkCompetition(
                    $competition->id,
                    $season->id,
                    $hlLeagueId,
                    $source,
                    $linker,
                    $client,
                    $aliases,
                    $safetyLimit,
                    $limit,
                    $linkCallsMade,
                    $matchFilter,
                );

                $linkCallsMade += $result['calls'];
                $linkLinked    += $result['linked'];
                $linkAlready   += $result['already_mapped'];
                $linkSkipped   += $result['skipped'];

                $this->line("[LINK]  {$slug}: calls={$result['calls']} linked={$result['linked']} already={$result['already_mapped']} skipped={$result['skipped']}");

                if ($limit !== null && $linkCallsMade >= $limit) {
                    $this->warn('[LIMIT] Call limit reached during linking phase.');
                    break;
                }
            }
        }

        // ── 3. Stats fill ────────────────────────────────────────────────────
        $this->newLine();
        $this->line('[STATS] Filling match statistics...');

        $remainingLimit = $limit !== null ? max(0, $limit - $linkCallsMade) : null;

        DB::beginTransaction();
        try {
            if ($dryRun) {
                $report = $filler->estimate($source, $this->output, $slugs, $matchFilter);
                DB::rollBack();
            } else {
                $effectiveSafety = max(0, $safetyLimit - $linkCallsMade);
                $report = $filler->fill($source, $effectiveSafety, $remainingLimit, false, $this->output, $slugs, $matchFilter);
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        // ── 4. Summary ───────────────────────────────────────────────────────
        $this->newLine();
        $this->line('=== SUMMARY ===');

        $this->table(
            ['Phase', 'Action', 'Count'],
            [
                ['Linking',  'Calls made',             $linkCallsMade],
                ['Linking',  'Newly linked',            $linkLinked],
                ['Linking',  'Already mapped',          $linkAlready],
                ['Linking',  'Skipped (unresolved)',    $linkSkipped],
                ['Stats',    'Calls made',              $report['calls_made']],
                ['Stats',    'Filled',                  $report['filled']],
                ['Stats',    'Skipped (complete)',      $report['skipped_complete']],
                ['Stats',    'Skipped (no mapping)',    $report['skipped_no_mapping']],
            ],
        );

        $totalCalls = $linkCallsMade + $report['calls_made'];
        $this->line("Total API calls this run : {$totalCalls}");

        if ($report['remaining_quota'] !== null) {
            $this->line("Quota remaining (HL)     : {$report['remaining_quota']}");
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] Nothing was written or fetched.');
        }

        if ($listUnresolved) {
            $unresolved = $linker->getUnresolvedTeams();
            if ($unresolved) {
                $this->newLine();
                $this->warn('Unresolved team names (add to config/team-aliases/highlightly.php):');
                foreach ($unresolved as $name) {
                    $this->line("  '{$name}' => '',");
                }
            } else {
                $this->info('All team names resolved.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Links all unlinked canonical matches for one competition by fetching HL matches
     * grouped by kickoff date (one API call per unique date).
     */
    private function linkCompetition(
        int $competitionId,
        int $seasonId,
        int $hlLeagueId,
        $source,
        HighlightlyFixtureLinker $linker,
        HighlightlyClient $client,
        array $aliases,
        int $safetyLimit,
        ?int $limit,
        int $callsSoFar,
        ?int $matchFilter = null,
    ): array {
        $calls = 0;

        // Only link matches that have already been played (status finished or kickoff in past).
        // Future matches can be linked on a future run — no point querying HL for dates not yet played.
        $unmapped = FootballMatch::where('competition_id', $competitionId)
            ->where('season_id', $seasonId)
            ->when($matchFilter !== null, fn($q) => $q->where('id', $matchFilter))
            ->where(function ($q) {
                $q->where('status', 'finished')
                  ->orWhere('kickoff_at', '<=', now());
            })
            ->whereNotIn('id', function ($q) use ($source) {
                $q->select('match_id')
                  ->from('match_external_ids')
                  ->where('data_source_id', $source->id);
            })
            ->whereNotNull('kickoff_at')
            ->get();

        if ($unmapped->isEmpty()) {
            return ['calls' => 0, 'linked' => 0, 'already_mapped' => 0, 'skipped' => 0];
        }

        // Group by date (YYYY-MM-DD) to minimize API calls
        $byDate = $unmapped->groupBy(fn(FootballMatch $m) => $m->kickoff_at->toDateString());

        foreach ($byDate as $date => $matchesOnDate) {
            // Respect per-run safety cap and explicit limit
            if (($callsSoFar + $calls) >= $safetyLimit) {
                Log::warning('highlightly-linker: reached safety cap during linking', [
                    'calls_so_far' => $callsSoFar + $calls,
                    'safety_limit' => $safetyLimit,
                ]);
                break;
            }

            if ($limit !== null && ($callsSoFar + $calls) >= $limit) {
                break;
            }

            // Deduplication: HL often returns the full season in every response.
            // If all canonical matches for this date are already mapped (from a prior date's response),
            // skip the API call entirely.
            $targetIds = $matchesOnDate->pluck('id');
            $alreadyLinked = MatchExternalId::where('data_source_id', $source->id)
                ->whereIn('match_id', $targetIds)
                ->count();
            if ($alreadyLinked >= $targetIds->count()) {
                continue;
            }

            // Primary quota guard: stop before consuming beyond daily safety budget
            if ($client->getLastRemainingQuota() !== null && $client->getLastRemainingQuota() <= 25) {
                Log::warning('highlightly-linker: quota <= 25, halting to preserve daily budget', [
                    'remaining' => $client->getLastRemainingQuota(),
                ]);
                break;
            }

            $hlMatches = $client->getMatches($hlLeagueId, $date, self::YEAR_START);
            $calls++;

            foreach ($hlMatches as $hlMatch) {
                DB::beginTransaction();
                try {
                    $linker->linkMatch($hlMatch, $source, $competitionId, $seasonId, $aliases);
                    DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    Log::error('highlightly-linker: exception linking match', [
                        'exception' => $e->getMessage(),
                        'hl_match'  => $hlMatch['id'] ?? null,
                    ]);
                }
            }

            usleep(500_000); // 0.5s between date queries
        }

        $result = $linker->getResult();

        return [
            'calls'         => $calls,
            'linked'        => $result['linked'],
            'already_mapped' => $result['already_mapped'],
            'skipped'       => $result['skipped'],
        ];
    }
}
