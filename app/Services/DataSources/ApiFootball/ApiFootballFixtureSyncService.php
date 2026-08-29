<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\SeasonExternalId;
use App\Models\TeamExternalId;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ApiFootballFixtureSyncService
{
    public const MODE_FULL    = 'full';
    public const MODE_REFRESH = 'refresh';

    // Statuses that represent a conclusive outcome — no further updates expected.
    // REFRESH mode skips matches already carrying one of these canonical statuses.
    // Note: 'cancelled' and 'abandoned' are intentionally excluded because those
    // matches can be rescheduled or replayed and need continued tracking.
    private const DEFINITIVE_STATUSES = ['finished', 'awarded', 'walkover'];

    private const STATUS_MAP = [
        'TBD'  => 'tbd',
        'NS'   => 'scheduled',
        '1H'   => 'live',
        'HT'   => 'live',
        '2H'   => 'live',
        'ET'   => 'live',
        'BT'   => 'live',
        'P'    => 'live',
        'LIVE' => 'live',
        'FT'   => 'finished',
        'AET'  => 'finished',
        'PEN'  => 'finished',
        'SUSP' => 'suspended',
        'INT'  => 'interrupted',
        'PST'  => 'postponed',
        'CANC' => 'cancelled',
        'ABD'  => 'abandoned',
        'AWD'  => 'awarded',
        'WO'   => 'walkover',
    ];

    private ?DataSource $ds = null;

    public function __construct(private readonly ApiFootballClient $client) {}

    private function dataSource(): DataSource
    {
        return $this->ds ??= DataSource::where('slug', 'api-football')->firstOrFail();
    }

    /**
     * @return array{season:int,mode:string,results:list<array>,fixtures_created:int,fixtures_updated:int}
     */
    public function syncAllCompetitions(int $season, string $mode = self::MODE_FULL): array
    {
        $ds   = $this->dataSource();
        $ceis = CompetitionExternalId::where('data_source_id', $ds->id)->get();

        $results         = [];
        $totalCreated    = 0;
        $totalUpdated    = 0;

        foreach ($ceis as $cei) {
            $startedAt = now();

            try {
                $report = $this->syncCompetition($cei, $season, $mode);
            } catch (ApiFootballException $e) {
                $comp   = Competition::find($cei->competition_id);
                $report = [
                    'competition_slug'   => $comp?->slug ?? "competition-{$cei->competition_id}",
                    'league_id'          => $cei->external_id,
                    'status'             => 'failed',
                    'message'            => $e->getMessage(),
                    'created'            => 0,
                    'updated'            => 0,
                    'unchanged'          => 0,
                    'skipped'            => 0,
                    'warnings'           => [],
                    'api_calls'          => 0,
                    'requests_remaining' => null,
                    'minute_remaining'   => null,
                ];
                Log::error("api-football-fixture-sync: league {$cei->external_id} failed — {$e->getMessage()}");
            }

            DataSyncRun::create([
                'data_source_id'  => $ds->id,
                'sync_type'       => 'fixture_sync',
                'competition_id'  => $cei->competition_id,
                'season_id'       => null,
                'mode'            => $mode,
                'started_at'      => $startedAt,
                'finished_at'     => now(),
                'status'          => in_array($report['status'] ?? '', ['ok', 'skipped'], true)
                                        ? ($report['status'] ?? 'ok')
                                        : 'failed',
                'created_count'   => $report['created']   ?? 0,
                'updated_count'   => $report['updated']   ?? 0,
                'unchanged_count' => $report['unchanged'] ?? 0,
                'skipped_count'   => $report['skipped']   ?? 0,
                'warnings_count'  => count($report['warnings'] ?? []),
                'api_calls'       => $report['api_calls'] ?? 0,
                'daily_remaining' => $report['requests_remaining'] ?? null,
                'details'         => null,
            ]);

            $totalCreated += $report['created'];
            $totalUpdated += $report['updated'];
            $results[]     = $report;
        }

        return [
            'season'           => $season,
            'mode'             => $mode,
            'results'          => $results,
            'fixtures_created' => $totalCreated,
            'fixtures_updated' => $totalUpdated,
        ];
    }

    /**
     * @return array{competition_slug:string,league_id:string,status:string,created:int,updated:int,unchanged:int,skipped:int,warnings:list<string>,api_calls:int,requests_remaining:int|null,minute_remaining:int|null,message?:string}
     */
    public function syncCompetition(CompetitionExternalId $cei, int $season, string $mode): array
    {
        $ds          = $this->dataSource();
        $competition = Competition::find($cei->competition_id);
        $slug        = $competition?->slug ?? "competition-{$cei->competition_id}";

        $base = [
            'competition_slug'   => $slug,
            'league_id'          => $cei->external_id,
            'created'            => 0,
            'updated'            => 0,
            'unchanged'          => 0,
            'skipped'            => 0,
            'warnings'           => [],
            'api_calls'          => 0,
            'requests_remaining' => null,
            'minute_remaining'   => null,
        ];

        // Require season_external_id to exist — no auto-create
        $sei = SeasonExternalId::where('data_source_id', $ds->id)
            ->where('competition_id', $cei->competition_id)
            ->where('external_id', (string) $season)
            ->first();

        if (!$sei) {
            $msg = "no season_external_id for {$slug} season {$season} — skipped";
            Log::warning("api-football-fixture-sync: {$msg}");
            return array_merge($base, ['status' => 'skipped', 'message' => $msg]);
        }

        $seasonId = $sei->season_id;

        // Pre-load team external id map: external_id => team_id
        $teamMap = TeamExternalId::where('data_source_id', $ds->id)
            ->pluck('team_id', 'external_id')
            ->all();

        // Fetch all fixtures, following pagination
        $allFixtures        = [];
        $apiCalls           = 0;
        $lastRemaining      = null;
        $lastMinuteRemaining = null;
        $page               = 1;

        do {
            $params = ['league' => $cei->external_id, 'season' => $season];
            if ($page > 1) {
                $params['page'] = $page;
            }

            $response = $this->client->get('fixtures', $params);
            $apiCalls++;
            $lastRemaining       = $response->requestsRemaining;
            $lastMinuteRemaining = $response->rateLimitRemaining;
            $allFixtures         = array_merge($allFixtures, $response->response);

            $currentPage = (int) ($response->paging['current'] ?? 1);
            $totalPages  = (int) ($response->paging['total']  ?? 1);
            $page++;
        } while ($currentPage < $totalPages && $page <= 50);

        $base['api_calls']          = $apiCalls;
        $base['requests_remaining'] = $lastRemaining;
        $base['minute_remaining']   = $lastMinuteRemaining;

        // Pre-load existing match external ids for this data source
        $existingMatchMap = MatchExternalId::where('data_source_id', $ds->id)
            ->pluck('match_id', 'external_id')
            ->all();

        // Pre-load the actual match rows for dirty-check and status inspection
        $matchIds     = array_values($existingMatchMap);
        $matchesById  = $matchIds
            ? FootballMatch::whereIn('id', $matchIds)->get()->keyBy('id')->all()
            : [];

        // Process each fixture
        foreach ($allFixtures as $item) {
            $result = $this->processFixtureItem(
                $item, $ds, $cei->competition_id, $seasonId,
                $teamMap, $existingMatchMap, $matchesById, $mode
            );

            match ($result['outcome']) {
                'created'   => $base['created']++,
                'updated'   => $base['updated']++,
                'unchanged' => $base['unchanged']++,
                'skipped'   => $base['skipped']++,
                default     => null,
            };

            if (isset($result['warning'])) {
                $base['warnings'][] = $result['warning'];
            }
        }

        return array_merge($base, ['status' => 'ok']);
    }

    /**
     * @return array{outcome:string, warning?:string}
     */
    private function processFixtureItem(
        array $item,
        DataSource $ds,
        int $competitionId,
        int $seasonId,
        array $teamMap,
        array $existingMatchMap,
        array $matchesById,
        string $mode
    ): array {
        $fixtureData = $item['fixture'] ?? [];
        $extId       = (string) ($fixtureData['id'] ?? '');

        if ($extId === '') {
            return ['outcome' => 'skipped', 'warning' => 'fixture item missing id'];
        }

        // Parse kickoff
        $kickoffAt = null;
        if (!empty($fixtureData['date'])) {
            try {
                $kickoffAt = Carbon::parse($fixtureData['date'])->utc();
            } catch (\Throwable) {
                // leave null
            }
        }
        $kickoffTimezone = $fixtureData['timezone'] ?? null;

        // Status
        $apiShortStatus  = $fixtureData['status']['short'] ?? 'NS';
        $canonicalStatus = $this->mapStatus($apiShortStatus);

        // Venue
        $venueName = $fixtureData['venue']['name'] ?? null;

        // Round / matchday
        $leagueData = $item['league'] ?? [];
        $rawRound   = $leagueData['round'] ?? null;
        $round      = $rawRound !== null ? substr($rawRound, 0, 50) : null;
        $matchday   = $round !== null ? $this->parseMatchday($round) : null;

        // Teams
        $teamsData = $item['teams'] ?? [];
        $homeExtId = (string) ($teamsData['home']['id'] ?? '');
        $awayExtId = (string) ($teamsData['away']['id'] ?? '');

        // Scores
        $scoreData    = $item['score'] ?? [];
        $homeScoreHT  = $scoreData['halftime']['home']  ?? null;
        $awayScoreHT  = $scoreData['halftime']['away']  ?? null;
        $homeScoreFT  = $scoreData['fulltime']['home']  ?? null;
        $awayScoreFT  = $scoreData['fulltime']['away']  ?? null;
        $homeScoreET  = $scoreData['extratime']['home'] ?? null;
        $awayScoreET  = $scoreData['extratime']['away'] ?? null;
        $homeScorePen = $scoreData['penalty']['home']   ?? null;
        $awayScorePen = $scoreData['penalty']['away']   ?? null;

        $newScalars = [
            'kickoff_timezone'     => $kickoffTimezone,
            'status'               => $canonicalStatus,
            'round'                => $round,
            'matchday'             => $matchday,
            'venue_name'           => $venueName,
            'home_score_ht'        => $homeScoreHT,
            'away_score_ht'        => $awayScoreHT,
            'home_score_ft'        => $homeScoreFT,
            'away_score_ft'        => $awayScoreFT,
            'home_score_et'        => $homeScoreET,
            'away_score_et'        => $awayScoreET,
            'home_score_penalties' => $homeScorePen,
            'away_score_penalties' => $awayScorePen,
        ];

        // --- Existing match ---
        if (isset($existingMatchMap[$extId])) {
            $matchId = $existingMatchMap[$extId];
            $match   = $matchesById[$matchId] ?? null;

            if (!$match) {
                return ['outcome' => 'skipped', 'warning' => "fixture {$extId}: match {$matchId} missing from pre-load"];
            }

            // REFRESH: skip matches that are already definitively settled
            if ($mode === self::MODE_REFRESH && in_array($match->status, self::DEFINITIVE_STATUSES, true)) {
                return ['outcome' => 'skipped'];
            }

            $dirty = $this->detectDirty($match, $newScalars, $kickoffAt);

            if (empty($dirty)) {
                return ['outcome' => 'unchanged'];
            }

            // Restore Carbon object for kickoff_at before persisting
            if (array_key_exists('kickoff_at', $dirty)) {
                $dirty['kickoff_at'] = $kickoffAt;
            }

            $match->update($dirty);
            return ['outcome' => 'updated'];
        }

        // --- New fixture: require team mappings ---
        if ($homeExtId === '' || !isset($teamMap[$homeExtId])) {
            return ['outcome' => 'skipped', 'warning' => "fixture {$extId}: home team ext_id '{$homeExtId}' not in team_external_ids"];
        }
        if ($awayExtId === '' || !isset($teamMap[$awayExtId])) {
            return ['outcome' => 'skipped', 'warning' => "fixture {$extId}: away team ext_id '{$awayExtId}' not in team_external_ids"];
        }

        $match = FootballMatch::create([
            'competition_id'       => $competitionId,
            'season_id'            => $seasonId,
            'home_team_id'         => $teamMap[$homeExtId],
            'away_team_id'         => $teamMap[$awayExtId],
            'kickoff_at'           => $kickoffAt,
            'kickoff_timezone'     => $kickoffTimezone,
            'round'                => $round,
            'matchday'             => $matchday,
            'status'               => $canonicalStatus,
            'venue_name'           => $venueName,
            'home_score_ht'        => $homeScoreHT,
            'away_score_ht'        => $awayScoreHT,
            'home_score_ft'        => $homeScoreFT,
            'away_score_ft'        => $awayScoreFT,
            'home_score_et'        => $homeScoreET,
            'away_score_et'        => $awayScoreET,
            'home_score_penalties' => $homeScorePen,
            'away_score_penalties' => $awayScorePen,
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $ds->id,
            'external_id'    => $extId,
            'external_name'  => null,
        ]);

        return ['outcome' => 'created'];
    }

    /**
     * Returns array of fields that differ between the stored match and new API values.
     * kickoff_at is compared as UTC strings to avoid Carbon/string type mismatches.
     * Scalar fields use null-safe string comparison so DB string/int differences are ignored.
     */
    private function detectDirty(FootballMatch $match, array $newScalars, ?Carbon $newKickoffAt): array
    {
        $dirty = [];

        // kickoff_at: compare formatted UTC strings
        $existingKickoff = $match->kickoff_at?->utc()->format('Y-m-d H:i:s');
        $newKickoffStr   = $newKickoffAt?->format('Y-m-d H:i:s');
        if ($existingKickoff !== $newKickoffStr) {
            // Store the sentinel so we can swap the Carbon back later
            $dirty['kickoff_at'] = $newKickoffStr;
        }

        foreach ($newScalars as $field => $newVal) {
            $existing = $match->$field;
            // null-safe string comparison avoids PHP null==0 and string/int issues
            if ($existing === null && $newVal === null) {
                continue;
            }
            if ($existing === null || $newVal === null || (string) $existing !== (string) $newVal) {
                $dirty[$field] = $newVal;
            }
        }

        return $dirty;
    }

    private function mapStatus(string $apiShort): string
    {
        return self::STATUS_MAP[$apiShort] ?? 'unknown';
    }

    /**
     * Extract the trailing integer from round strings like "Regular Season - 12" → 12.
     * Returns null for strings like "Final" or "Quarter-Finals" that have no trailing number.
     */
    private function parseMatchday(string $round): ?int
    {
        if (preg_match('/(\d+)$/', $round, $m)) {
            return (int) $m[1];
        }
        return null;
    }
}
