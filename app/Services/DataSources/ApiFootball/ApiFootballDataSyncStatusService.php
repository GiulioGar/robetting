<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\Season;
use App\Models\SeasonExternalId;
use Carbon\Carbon;

/**
 * P24B — read-only comparison between API-Football's current fixture state
 * and what Robetting's DB already holds, for the 5 core leagues' current
 * season.
 *
 * This is STRICTLY read-only: it never calls FootballMatch::create/update,
 * never writes MatchExternalId, and never writes a DataSyncRun row. It only
 * answers "is the DB missing anything, or does it disagree with API-Football
 * on kickoff/status/FT score" — not "fix it".
 *
 * Reuses the same fixture-fetch/pagination shape and the same status mapping
 * as ApiFootballFixtureSyncService (via ApiFootballStatusMapper), so the
 * definition of "the same fixture" and "the same canonical status" can never
 * silently diverge between the writer and this read-only checker.
 */
class ApiFootballDataSyncStatusService
{
    public const MAX_API_CALLS_PER_RUN = 20;
    public const SAMPLE_LIMIT = 5;

    private ?DataSource $ds = null;

    public function __construct(private readonly ApiFootballClient $client) {}

    private function dataSource(): DataSource
    {
        return $this->ds ??= DataSource::where('slug', 'api-football')->firstOrFail();
    }

    /**
     * @return array{leagues: list<array>, api_calls_used: int, requests_remaining: int|null}
     */
    public function checkAll(): array
    {
        $ds = $this->dataSource();
        $coreSlugs = array_values(config('api-football.core_leagues', []));

        $ceis = CompetitionExternalId::where('data_source_id', $ds->id)
            ->whereHas('competition', fn ($q) => $q->whereIn('slug', $coreSlugs))
            ->get();

        $slugByCompetitionId = Competition::whereIn('id', $ceis->pluck('competition_id'))
            ->pluck('slug', 'id');

        $ceis = $ceis
            ->sortBy(fn ($cei) => array_search($slugByCompetitionId[$cei->competition_id] ?? null, $coreSlugs, true))
            ->values();

        $callsUsed = 0;
        $lastRemaining = null;
        $leagues = [];
        $budgetExhausted = false;

        foreach ($ceis as $cei) {
            if ($budgetExhausted) {
                $leagues[] = $this->budgetSkippedReport($cei);
                continue;
            }

            $report = $this->checkLeague($cei, $callsUsed);
            $callsUsed += $report['api_calls'];
            if ($report['requests_remaining'] !== null) {
                $lastRemaining = $report['requests_remaining'];
            }
            if (($report['budget_exhausted'] ?? false) === true) {
                $budgetExhausted = true;
            }
            unset($report['budget_exhausted']);
            $leagues[] = $report;
        }

        return [
            'leagues'            => $leagues,
            'api_calls_used'     => $callsUsed,
            'requests_remaining' => $lastRemaining,
        ];
    }

    /**
     * @return array{competition_slug:string,league_id:string,season:string|null,api_fixtures_received:int,db_fixtures_compared:int,missing:list<string>,kickoff_diff:list<string>,status_diff:list<string>,score_diff:list<string>,status:string,api_calls:int,requests_remaining:int|null,message?:string,budget_exhausted?:bool}
     */
    private function checkLeague(CompetitionExternalId $cei, int $callsUsedSoFar): array
    {
        $ds          = $this->dataSource();
        $competition = Competition::find($cei->competition_id);
        $slug        = $competition?->slug ?? "competition-{$cei->competition_id}";

        $base = [
            'competition_slug'       => $slug,
            'league_id'              => $cei->external_id,
            'season'                 => null,
            'api_fixtures_received'  => 0,
            'db_fixtures_compared'   => 0,
            'missing'                => [],
            'kickoff_diff'           => [],
            'status_diff'            => [],
            'score_diff'             => [],
            'api_calls'              => 0,
            'requests_remaining'     => null,
        ];

        $season = Season::where('competition_id', $cei->competition_id)
            ->orderByDesc('year_start')
            ->first();

        if (!$season) {
            return array_merge($base, ['status' => 'ERROR', 'message' => 'no current season found in DB for this competition']);
        }

        $sei = SeasonExternalId::where('data_source_id', $ds->id)
            ->where('competition_id', $cei->competition_id)
            ->where('season_id', $season->id)
            ->first();

        if (!$sei) {
            return array_merge($base, ['status' => 'ERROR', 'message' => "no season_external_id for {$slug} season {$season->year_start}"]);
        }

        $base['season'] = $sei->external_id;

        // ─── Fetch all fixture pages for this league/season, capped by the
        // GLOBAL (whole-run) call budget — never just a per-league budget. ───
        $allFixtures   = [];
        $apiCalls      = 0;
        $lastRemaining = null;
        $page          = 1;

        do {
            if ($callsUsedSoFar + $apiCalls + 1 > self::MAX_API_CALLS_PER_RUN) {
                return array_merge($base, [
                    'status'            => 'ERROR',
                    'message'           => 'API call budget (' . self::MAX_API_CALLS_PER_RUN . ') would be exceeded — aborted before making the call',
                    'api_calls'         => $apiCalls,
                    'requests_remaining'=> $lastRemaining,
                    'budget_exhausted'  => true,
                ]);
            }

            $params = ['league' => $cei->external_id, 'season' => $sei->external_id];
            if ($page > 1) {
                $params['page'] = $page;
            }

            try {
                $response = $this->client->get('fixtures', $params);
            } catch (ApiFootballException $e) {
                return array_merge($base, [
                    'status'    => 'ERROR',
                    'message'   => "API-Football error: {$e->getMessage()}",
                    'api_calls' => $apiCalls,
                ]);
            }

            $apiCalls++;
            $lastRemaining = $response->requestsRemaining;
            $allFixtures   = array_merge($allFixtures, $response->response);

            $currentPage = (int) ($response->paging['current'] ?? 1);
            $totalPages  = (int) ($response->paging['total']  ?? 1);
            $page++;
        } while ($currentPage < $totalPages && $page <= 50);

        $base['api_calls']          = $apiCalls;
        $base['requests_remaining'] = $lastRemaining;
        $base['api_fixtures_received'] = count($allFixtures);

        // ─── Build API-side map keyed by external fixture id ────────────────
        $apiByExtId = [];
        foreach ($allFixtures as $item) {
            $fixtureData = $item['fixture'] ?? [];
            $extId       = (string) ($fixtureData['id'] ?? '');
            if ($extId === '') {
                continue;
            }

            $kickoffAt = null;
            if (!empty($fixtureData['date'])) {
                try {
                    $kickoffAt = Carbon::parse($fixtureData['date'])->utc();
                } catch (\Throwable) {
                    // leave null
                }
            }

            $scoreFt = $item['score']['fulltime'] ?? [];

            $apiByExtId[$extId] = [
                'kickoff_at'     => $kickoffAt,
                'status'         => ApiFootballStatusMapper::map($fixtureData['status']['short'] ?? 'NS'),
                'home_score_ft'  => $scoreFt['home'] ?? null,
                'away_score_ft'  => $scoreFt['away'] ?? null,
            ];
        }

        $base['db_fixtures_compared'] = count($apiByExtId);

        // ─── READ ONLY: compare against the DB, never write ─────────────────
        $existingMatchMap = $apiByExtId === []
            ? []
            : MatchExternalId::where('data_source_id', $ds->id)
                ->whereIn('external_id', array_keys($apiByExtId))
                ->pluck('match_id', 'external_id')
                ->all();

        $matchIds    = array_values($existingMatchMap);
        $matchesById = $matchIds
            ? FootballMatch::whereIn('id', $matchIds)
                ->get(['id', 'kickoff_at', 'status', 'home_score_ft', 'away_score_ft'])
                ->keyBy('id')
                ->all()
            : [];

        $missing      = [];
        $kickoffDiff  = [];
        $statusDiff   = [];
        $scoreDiff    = [];

        foreach ($apiByExtId as $extId => $api) {
            if (!isset($existingMatchMap[$extId]) || !isset($matchesById[$existingMatchMap[$extId]])) {
                $missing[] = $extId;
                continue;
            }

            $match = $matchesById[$existingMatchMap[$extId]];

            $dbKickoff  = $match->kickoff_at?->utc()->format('Y-m-d H:i:s');
            $apiKickoff = $api['kickoff_at']?->format('Y-m-d H:i:s');
            if ($dbKickoff !== $apiKickoff) {
                $kickoffDiff[] = $extId;
            }

            if ($match->status !== $api['status']) {
                $statusDiff[] = $extId;
            }

            // FT score comparison is only pertinent once API-Football itself
            // considers the match definitively concluded.
            if (in_array($api['status'], ApiFootballFixtureSyncService::DEFINITIVE_STATUSES, true)) {
                $dbHome  = (string) $match->home_score_ft;
                $dbAway  = (string) $match->away_score_ft;
                $apiHome = (string) $api['home_score_ft'];
                $apiAway = (string) $api['away_score_ft'];
                if ($dbHome !== $apiHome || $dbAway !== $apiAway) {
                    $scoreDiff[] = $extId;
                }
            }
        }

        $status = ($missing === [] && $kickoffDiff === [] && $statusDiff === [] && $scoreDiff === [])
            ? 'CURRENT'
            : 'STALE';

        return array_merge($base, [
            'missing'      => $missing,
            'kickoff_diff' => $kickoffDiff,
            'status_diff'  => $statusDiff,
            'score_diff'   => $scoreDiff,
            'status'       => $status,
        ]);
    }

    private function budgetSkippedReport(CompetitionExternalId $cei): array
    {
        $competition = Competition::find($cei->competition_id);
        $slug        = $competition?->slug ?? "competition-{$cei->competition_id}";

        return [
            'competition_slug'      => $slug,
            'league_id'             => $cei->external_id,
            'season'                => null,
            'api_fixtures_received' => 0,
            'db_fixtures_compared'  => 0,
            'missing'               => [],
            'kickoff_diff'          => [],
            'status_diff'           => [],
            'score_diff'            => [],
            'status'                => 'ERROR',
            'message'               => 'skipped — API call budget already exhausted by a previous league',
            'api_calls'             => 0,
            'requests_remaining'    => null,
        ];
    }
}
