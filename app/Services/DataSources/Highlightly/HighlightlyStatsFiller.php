<?php

namespace App\Services\DataSources\Highlightly;

use App\Models\Competition;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\MatchStatistic;
use App\Models\Season;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fills match_statistics from Highlightly for the current season's finished matches.
 *
 * Invariants:
 *   - Only processes the 5 core competitions, season year_start=2026.
 *   - Only processes FINISHED matches with an existing Highlightly match_external_id.
 *   - Skips rows where a Highlightly MatchStatistic already exists with home_shots != null.
 *   - Never overwrites FDCUK statistics.
 *   - Stops the moment remaining quota would fall below the safety margin.
 */
class HighlightlyStatsFiller
{
    private const COMPETITION_SLUGS = ['serie-a', 'premier-league', 'la-liga', 'bundesliga', 'ligue-1'];
    private const YEAR_START        = 2026;

    private int $callsMade        = 0;
    private int $filled           = 0;
    private int $skippedComplete  = 0;
    private int $skippedNoMapping = 0;
    private ?int $remainingQuota  = null;

    public function __construct(
        private readonly HighlightlyClient $client,
        private readonly HighlightlyStatsParser $parser,
    ) {}

    /**
     * Runs the stats fill pass.
     *
     * @param  DataSource  $source  Highlightly DataSource row.
     * @param  int  $safetyLimit  Max API calls per run (inclusive).
     * @param  int|null  $limit  Hard cap on API calls for this run (null = safetyLimit).
     * @param  bool  $dryRun  If true: no writes, no API calls — only count eligibles.
     * @param  OutputStyle|null  $output
     * @param  string[]  $slugs  Competition slugs to process. Defaults to all 5.
     * @param  int|null  $matchId  Restrict to a single canonical match ID for targeted testing.
     * @return array  Summary report.
     */
    public function fill(
        DataSource $source,
        int $safetyLimit,
        ?int $limit,
        bool $dryRun,
        ?OutputStyle $output,
        array $slugs = [],
        ?int $matchId = null,
    ): array {
        $slugs = $slugs ?: self::COMPETITION_SLUGS;

        // Persistent quota guard: if today's remaining was already <= 25 from a prior run, abort immediately.
        $today = now()->toDateString();
        $cached = Cache::get('highlightly_quota');
        if (!$dryRun && $cached && $cached['date'] === $today) {
            $this->remainingQuota = $cached['remaining']; // seed so per-call guard is aware from the start
            if ($cached['remaining'] <= 25) {
                $this->log($output, "STOP  cached quota remaining={$cached['remaining']} <= 25 for {$today} — aborting run");
                return $this->report();
            }
        }

        $competitions = Competition::whereIn('slug', $slugs)->get()->keyBy('slug');

        if ($competitions->isEmpty()) {
            return $this->report();
        }

        foreach ($slugs as $slug) {
            $competition = $competitions[$slug] ?? null;

            if (!$competition) {
                $this->log($output, "WARN  no competition found for slug={$slug}");
                continue;
            }

            $season = Season::where('competition_id', $competition->id)
                ->where('year_start', self::YEAR_START)
                ->first();

            if (!$season) {
                $this->log($output, "WARN  no {$slug} season with year_start=2026 in DB");
                continue;
            }

            $this->processCompetition($competition, $season, $source, $safetyLimit, $limit, $dryRun, $output, $matchId);

            if ($limit !== null && $this->callsMade >= $limit) {
                break;
            }
        }

        return $this->report();
    }

    private function processCompetition(
        Competition $competition,
        Season $season,
        DataSource $source,
        int $safetyLimit,
        ?int $limit,
        bool $dryRun,
        ?OutputStyle $output,
        ?int $matchId = null,
    ): void {
        // All FINISHED canonical matches for this competition/season, oldest first
        $finishedMatches = FootballMatch::where('competition_id', $competition->id)
            ->where('season_id', $season->id)
            ->where('status', 'finished')
            ->when($matchId !== null, fn($q) => $q->where('id', $matchId))
            ->orderBy('kickoff_at', 'asc')
            ->get();

        $matchIds = $finishedMatches->pluck('id');

        // Which ones have a Highlightly mapping?
        $hlMappings = MatchExternalId::where('data_source_id', $source->id)
            ->whereIn('match_id', $matchIds)
            ->get()
            ->keyBy('match_id');

        // Which ones already have complete Highlightly stats (all 12 core fields non-null)?
        $existingStats = MatchStatistic::where('data_source_id', $source->id)
            ->whereIn('match_id', $matchIds)
            ->whereNotNull('home_shots')
            ->whereNotNull('away_shots')
            ->whereNotNull('home_shots_on_target')
            ->whereNotNull('away_shots_on_target')
            ->whereNotNull('home_fouls')
            ->whereNotNull('away_fouls')
            ->whereNotNull('home_corners')
            ->whereNotNull('away_corners')
            ->whereNotNull('home_yellow_cards')
            ->whereNotNull('away_yellow_cards')
            ->whereNotNull('home_red_cards')
            ->whereNotNull('away_red_cards')
            ->pluck('match_id')
            ->flip(); // use as a set

        foreach ($finishedMatches as $match) {
            $hlMapping = $hlMappings[$match->id] ?? null;

            if (!$hlMapping) {
                $this->skippedNoMapping++;
                continue;
            }

            if (isset($existingStats[$match->id])) {
                $this->skippedComplete++;
                $this->log($output, "SKIP  {$competition->slug} match_id={$match->id} already complete");
                continue;
            }

            if ($dryRun) {
                $this->log($output, "DRY   {$competition->slug} match_id={$match->id} hl={$hlMapping->external_id} → would fetch");
                $this->filled++;
                continue;
            }

            // Primary quota guard: stop before consuming beyond daily safety budget
            if ($this->remainingQuota !== null && $this->remainingQuota <= 25) {
                $this->log($output, "STOP  quota remaining={$this->remainingQuota} <= 25 — daily budget exhausted");
                return;
            }

            // Stop if we have reached the per-run safety cap
            if ($this->callsMade >= $safetyLimit) {
                $this->log($output, "STOP  reached safety limit of {$safetyLimit} calls");
                return;
            }

            if ($limit !== null && $this->callsMade >= $limit) {
                return;
            }

            $this->fetchAndSave($match->id, $hlMapping->external_id, $source, $output, $competition->slug);
        }
    }

    private function fetchAndSave(
        int $matchId,
        string $hlMatchId,
        DataSource $source,
        ?OutputStyle $output,
        string $slug,
    ): void {
        $raw = $this->client->getStatistics($hlMatchId);
        $this->callsMade++;

        if ($this->client->getLastRemainingQuota() !== null) {
            $this->remainingQuota = $this->client->getLastRemainingQuota();
            Cache::put('highlightly_quota', [
                'remaining' => $this->remainingQuota,
                'date'      => now()->toDateString(),
            ], now()->addDays(2));
        }

        if (empty($raw)) {
            Log::warning('highlightly-filler: empty statistics response', [
                'match_id'   => $matchId,
                'hl_match_id' => $hlMatchId,
            ]);
            $this->log($output, "WARN  {$slug} match_id={$matchId} hl={$hlMatchId} → empty response");
            // Save a null row to prevent repeated API calls for matches with no HL data.
            MatchStatistic::updateOrCreate(
                ['match_id' => $matchId, 'data_source_id' => $source->id],
                [],
            );
            return;
        }

        $parsed = $this->parser->parse($raw);

        if ($parsed === null) {
            Log::warning('highlightly-filler: could not parse statistics', [
                'match_id'    => $matchId,
                'hl_match_id' => $hlMatchId,
            ]);
            $this->log($output, "WARN  {$slug} match_id={$matchId} hl={$hlMatchId} → parse failed");
            return;
        }

        MatchStatistic::updateOrCreate(
            ['match_id' => $matchId, 'data_source_id' => $source->id],
            $parsed,
        );

        $this->filled++;
        $this->log($output, "OK    {$slug} match_id={$matchId} hl={$hlMatchId} shots={$parsed['home_shots']}/{$parsed['away_shots']} yc={$parsed['home_yellow_cards']}/{$parsed['away_yellow_cards']} quota={$this->remainingQuota}");
    }

    /**
     * Dry-run estimation only: counts eligibles from DB without calling the API.
     *
     * @param  string[]  $slugs  Competition slugs to check. Defaults to all 5.
     * @param  int|null  $matchId  Restrict to a single canonical match ID for targeted testing.
     */
    public function estimate(DataSource $source, ?OutputStyle $output, array $slugs = [], ?int $matchId = null): array
    {
        $slugs = $slugs ?: self::COMPETITION_SLUGS;
        $competitions = Competition::whereIn('slug', $slugs)->get()->keyBy('slug');

        foreach ($slugs as $slug) {
            $competition = $competitions[$slug] ?? null;

            if (!$competition) {
                continue;
            }

            $season = Season::where('competition_id', $competition->id)
                ->where('year_start', self::YEAR_START)
                ->first();

            if (!$season) {
                continue;
            }

            $matchIds = FootballMatch::where('competition_id', $competition->id)
                ->where('season_id', $season->id)
                ->where('status', 'finished')
                ->when($matchId !== null, fn($q) => $q->where('id', $matchId))
                ->orderBy('kickoff_at', 'asc')
                ->pluck('id');

            $mapped = MatchExternalId::where('data_source_id', $source->id)
                ->whereIn('match_id', $matchIds)
                ->pluck('match_id')
                ->flip();

            $complete = MatchStatistic::where('data_source_id', $source->id)
                ->whereIn('match_id', $matchIds)
                ->whereNotNull('home_shots')
                ->whereNotNull('away_shots')
                ->whereNotNull('home_shots_on_target')
                ->whereNotNull('away_shots_on_target')
                ->whereNotNull('home_fouls')
                ->whereNotNull('away_fouls')
                ->whereNotNull('home_corners')
                ->whereNotNull('away_corners')
                ->whereNotNull('home_yellow_cards')
                ->whereNotNull('away_yellow_cards')
                ->whereNotNull('home_red_cards')
                ->whereNotNull('away_red_cards')
                ->pluck('match_id')
                ->flip();

            foreach ($matchIds as $matchId) {
                if (!isset($mapped[$matchId])) {
                    $this->skippedNoMapping++;
                } elseif (isset($complete[$matchId])) {
                    $this->skippedComplete++;
                } else {
                    $this->filled++; // "would fill"
                }
            }

            $this->log($output, "DRY {$slug}: finished={$matchIds->count()} mapped={$mapped->count()} complete={$complete->count()}");
        }

        return $this->report();
    }

    private function report(): array
    {
        return [
            'calls_made'          => $this->callsMade,
            'filled'              => $this->filled,
            'skipped_complete'    => $this->skippedComplete,
            'skipped_no_mapping'  => $this->skippedNoMapping,
            'remaining_quota'     => $this->remainingQuota,
        ];
    }

    private function log(?OutputStyle $output, string $message): void
    {
        if ($output) {
            $output->writeln("[{$message}]");
        }
    }
}
