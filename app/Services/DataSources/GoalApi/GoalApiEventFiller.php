<?php

namespace App\Services\DataSources\GoalApi;

use App\Models\Competition;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\MatchExternalId;
use App\Models\Season;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fetches and persists goal/card/substitution events from GOAL API for
 * the current season's finished matches.
 *
 * Completeness sentinel: a row with event_type='sync_complete' marks a match
 * as fully processed so re-runs skip it with zero API calls.
 */
class GoalApiEventFiller
{
    private const COMPETITION_SLUGS = ['serie-a', 'premier-league', 'la-liga', 'bundesliga', 'ligue-1'];
    private const YEAR_START        = 2026;
    private const QUOTA_STOP_BELOW  = 100; // halt if remaining drops to this

    private int $callsMade       = 0;
    private int $filled          = 0;
    private int $skippedComplete = 0;
    private int $skippedNoMap    = 0;
    private ?int $remainingQuota = null;

    public function __construct(
        private readonly GoalApiClient $client,
        private readonly GoalApiEventParser $parser,
    ) {}

    public function fill(
        DataSource $source,
        int $safetyLimit,
        ?int $limit,
        ?OutputStyle $output,
        array $slugs = [],
        ?int $matchId = null,
    ): array {
        $slugs = $slugs ?: self::COMPETITION_SLUGS;

        foreach ($slugs as $slug) {
            $competition = Competition::where('slug', $slug)->first();
            if (!$competition) {
                continue;
            }

            $season = Season::where('competition_id', $competition->id)
                ->where('year_start', self::YEAR_START)
                ->first();

            if (!$season) {
                $this->log($output, "WARN  no {$slug} season year_start=2026 in DB");
                continue;
            }

            $this->processCompetition($competition, $season, $source, $safetyLimit, $limit, $output, $matchId);

            if ($limit !== null && $this->callsMade >= $limit) {
                break;
            }
        }

        return $this->report();
    }

    public function estimate(DataSource $source, ?OutputStyle $output, array $slugs = [], ?int $matchId = null): array
    {
        $slugs = $slugs ?: self::COMPETITION_SLUGS;

        foreach ($slugs as $slug) {
            $competition = Competition::where('slug', $slug)->first();
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

            $synced = MatchEvent::where('data_source_id', $source->id)
                ->whereIn('match_id', $matchIds)
                ->where('event_type', 'sync_complete')
                ->pluck('match_id')
                ->flip();

            foreach ($matchIds as $id) {
                if (!isset($mapped[$id])) {
                    $this->skippedNoMap++;
                } elseif (isset($synced[$id])) {
                    $this->skippedComplete++;
                } else {
                    $this->filled++;
                }
            }

            $this->log($output, "DRY {$slug}: finished={$matchIds->count()} mapped={$mapped->count()} synced={$synced->count()}");
        }

        return $this->report();
    }

    private function processCompetition(
        Competition $competition,
        Season $season,
        DataSource $source,
        int $safetyLimit,
        ?int $limit,
        ?OutputStyle $output,
        ?int $matchId = null,
    ): void {
        $finishedMatches = FootballMatch::where('competition_id', $competition->id)
            ->where('season_id', $season->id)
            ->where('status', 'finished')
            ->when($matchId !== null, fn($q) => $q->where('id', $matchId))
            ->orderBy('kickoff_at', 'asc')
            ->get();

        $matchIds = $finishedMatches->pluck('id');

        $mappings = MatchExternalId::where('data_source_id', $source->id)
            ->whereIn('match_id', $matchIds)
            ->get()
            ->keyBy('match_id');

        $synced = MatchEvent::where('data_source_id', $source->id)
            ->whereIn('match_id', $matchIds)
            ->where('event_type', 'sync_complete')
            ->pluck('match_id')
            ->flip();

        foreach ($finishedMatches as $match) {
            $mapping = $mappings[$match->id] ?? null;

            if (!$mapping) {
                $this->skippedNoMap++;
                continue;
            }

            if (isset($synced[$match->id])) {
                $this->skippedComplete++;
                $this->log($output, "SKIP  {$competition->slug} match_id={$match->id} already complete");
                continue;
            }

            // Quota guards
            if ($this->remainingQuota !== null && $this->remainingQuota <= self::QUOTA_STOP_BELOW) {
                $this->log($output, "STOP  quota remaining={$this->remainingQuota} <= " . self::QUOTA_STOP_BELOW);
                return;
            }

            if ($this->callsMade >= $safetyLimit) {
                $this->log($output, "STOP  reached safety limit of {$safetyLimit} calls");
                return;
            }

            if ($limit !== null && $this->callsMade >= $limit) {
                return;
            }

            $this->fetchAndSave($match, $mapping->external_id, $source, $output, $competition->slug);
        }
    }

    private function fetchAndSave(
        FootballMatch $match,
        string $goalApiFixtureId,
        DataSource $source,
        ?OutputStyle $output,
        string $slug,
    ): void {
        $response = $this->client->getFixture($goalApiFixtureId);
        $this->callsMade++;

        if ($this->client->getLastRemainingQuota() !== null) {
            $this->remainingQuota = $this->client->getLastRemainingQuota();
        }

        $data = $response['data'] ?? null;

        if (empty($data)) {
            Log::warning('goal-api-events: empty fixture response', [
                'match_id'   => $match->id,
                'fixture_id' => $goalApiFixtureId,
            ]);
            $this->log($output, "WARN  {$slug} match_id={$match->id} → empty response");
            return;
        }

        $parsed = $this->parser->parse(
            goals:         $data['events'] ?? [],
            cards:         $data['cards'] ?? [],
            substitutions: $data['substitutions'] ?? [],
            homeTeamId:    $match->home_team_id,
            awayTeamId:    $match->away_team_id,
        );

        DB::transaction(function () use ($match, $source, $parsed, $slug, $output, $goalApiFixtureId) {
            foreach ($parsed as $event) {
                MatchEvent::updateOrCreate(
                    [
                        'match_id'         => $match->id,
                        'data_source_id'   => $source->id,
                        'source_event_key' => $event['source_event_key'],
                    ],
                    array_merge($event, [
                        'match_id'       => $match->id,
                        'data_source_id' => $source->id,
                    ]),
                );
            }

            // Sentinel: mark match as fully processed so re-runs skip it
            MatchEvent::updateOrCreate(
                [
                    'match_id'         => $match->id,
                    'data_source_id'   => $source->id,
                    'source_event_key' => '__sync__',
                ],
                [
                    'match_id'         => $match->id,
                    'data_source_id'   => $source->id,
                    'event_type'       => 'sync_complete',
                    'source_event_key' => '__sync__',
                ],
            );

            $this->filled++;

            $goals = count(array_filter($parsed, fn($e) => $e['event_type'] === 'goal'));
            $cards = count(array_filter($parsed, fn($e) => in_array($e['event_type'], ['yellow_card', 'red_card', 'yellow_red_card'], true)));
            $subs  = count(array_filter($parsed, fn($e) => $e['event_type'] === 'substitution'));

            $this->log($output, "OK    {$slug} match_id={$match->id} fixture={$goalApiFixtureId} goals={$goals} cards={$cards} subs={$subs} quota={$this->remainingQuota}");
        });
    }

    private function report(): array
    {
        return [
            'calls_made'      => $this->callsMade,
            'filled'          => $this->filled,
            'skipped_complete' => $this->skippedComplete,
            'skipped_no_map'  => $this->skippedNoMap,
            'remaining_quota' => $this->remainingQuota,
        ];
    }

    private function log(?OutputStyle $output, string $message): void
    {
        if ($output) {
            $output->writeln("[{$message}]");
        }
    }
}
