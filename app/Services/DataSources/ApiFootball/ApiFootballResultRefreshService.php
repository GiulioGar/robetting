<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ApiFootballResultRefreshService
{
    private const BATCH_SIZE = 20;

    // Mirrors ApiFootballFixtureSyncService::STATUS_MAP — keep in sync.
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
     * Normal 5-minute refresh: candidates with kickoff <= now + 5 minutes.
     */
    public function refresh(): array
    {
        return $this->run(catchUp: false);
    }

    /**
     * Startup catch-up: all past non-definitive matches regardless of age.
     */
    public function catchUp(): array
    {
        return $this->run(catchUp: true);
    }

    private function run(bool $catchUp): array
    {
        $ds       = $this->dataSource();
        $syncType = $catchUp ? 'catch_up' : 'result_refresh';
        $started  = now();

        $candidateIds   = $this->findCandidateMatchIds($catchUp);
        $candidateCount = $candidateIds->count();

        if ($candidateIds->isEmpty()) {
            DataSyncRun::create($this->buildRunPayload($ds->id, $syncType, $started, 0, 0, 0, 0, 0, null));
            return ['status' => 'ok', 'sync_type' => $syncType, 'candidates' => 0, 'updated' => 0, 'api_calls' => 0, 'daily_remaining' => null];
        }

        // external_id (string) → match_id (int)
        $extIdMap = MatchExternalId::where('data_source_id', $ds->id)
            ->whereIn('match_id', $candidateIds)
            ->pluck('match_id', 'external_id')
            ->all();

        if (empty($extIdMap)) {
            Log::warning("api-football-{$syncType}: {$candidateCount} candidate(s) but none have api-football external IDs");
            DataSyncRun::create($this->buildRunPayload($ds->id, $syncType, $started, 0, 0, $candidateCount, 0, 0, null));
            return ['status' => 'ok', 'sync_type' => $syncType, 'candidates' => $candidateCount, 'updated' => 0, 'api_calls' => 0, 'daily_remaining' => null];
        }

        $matchesById = FootballMatch::whereIn('id', array_values($extIdMap))
            ->get()->keyBy('id')->all();

        $batches       = array_chunk(array_keys($extIdMap), self::BATCH_SIZE);
        $apiCalls      = 0;
        $updated       = 0;
        $unchanged     = 0;
        $skipped       = 0;
        $warnings      = [];
        $lastRemaining = null;

        foreach ($batches as $batch) {
            try {
                $response      = $this->client->get('fixtures', ['ids' => implode('-', $batch)]);
                $apiCalls++;
                $lastRemaining = $response->requestsRemaining;

                foreach ($response->response as $item) {
                    $result = $this->processItem($item, $extIdMap, $matchesById);
                    match ($result['outcome']) {
                        'updated'   => $updated++,
                        'unchanged' => $unchanged++,
                        default     => $skipped++,
                    };
                    if (isset($result['warning'])) {
                        $warnings[] = $result['warning'];
                    }
                }
            } catch (ApiFootballException $e) {
                Log::error("api-football-{$syncType}: batch failed — {$e->getMessage()}");
                $warnings[] = "batch failed: {$e->getMessage()}";
            }
        }

        DataSyncRun::create($this->buildRunPayload(
            $ds->id, $syncType, $started,
            $updated, $unchanged, $skipped, count($warnings), $apiCalls, $lastRemaining,
            empty($warnings) ? null : ['warnings' => $warnings],
        ));

        return [
            'status'          => 'ok',
            'sync_type'       => $syncType,
            'candidates'      => $candidateCount,
            'updated'         => $updated,
            'unchanged'       => $unchanged,
            'api_calls'       => $apiCalls,
            'daily_remaining' => $lastRemaining,
        ];
    }

    private function findCandidateMatchIds(bool $catchUp): \Illuminate\Support\Collection
    {
        $cutoff = $catchUp ? now() : now()->addMinutes(5);

        return FootballMatch::whereNotIn('status', ApiFootballFixtureSyncService::DEFINITIVE_STATUSES)
            ->whereNotNull('kickoff_at')
            ->where('kickoff_at', '<=', $cutoff)
            ->pluck('id');
    }

    private function processItem(array $item, array $extIdMap, array $matchesById): array
    {
        $fixtureData = $item['fixture'] ?? [];
        $extId       = (string) ($fixtureData['id'] ?? '');

        if ($extId === '' || !isset($extIdMap[$extId])) {
            return ['outcome' => 'skipped'];
        }

        $matchId = $extIdMap[$extId];
        $match   = $matchesById[$matchId] ?? null;

        if (!$match) {
            return ['outcome' => 'skipped', 'warning' => "result: match {$matchId} not pre-loaded"];
        }

        $kickoffAt = null;
        if (!empty($fixtureData['date'])) {
            try {
                $kickoffAt = Carbon::parse($fixtureData['date'])->utc();
            } catch (\Throwable) {
                // leave null
            }
        }

        $apiShortStatus  = $fixtureData['status']['short'] ?? 'NS';
        $canonicalStatus = self::STATUS_MAP[$apiShortStatus] ?? 'unknown';

        $leagueData = $item['league'] ?? [];
        $rawRound   = $leagueData['round'] ?? null;
        $round      = $rawRound !== null ? substr($rawRound, 0, 50) : null;
        $matchday   = $round !== null ? $this->parseMatchday($round) : null;

        $scoreData  = $item['score'] ?? [];
        $isDefinitive = in_array($canonicalStatus, ApiFootballFixtureSyncService::DEFINITIVE_STATUSES, true);

        $newScalars = [
            'kickoff_timezone'     => $fixtureData['timezone'] ?? null,
            'status'               => $canonicalStatus,
            'round'                => $round,
            'matchday'             => $matchday,
            'venue_name'           => $fixtureData['venue']['name'] ?? null,
            // Score fields: always sourced from score.* — never from goals.*
            'home_score_ht'        => $scoreData['halftime']['home']  ?? null,
            'away_score_ht'        => $scoreData['halftime']['away']  ?? null,
            'home_score_ft'        => $scoreData['fulltime']['home']  ?? null,
            'away_score_ft'        => $scoreData['fulltime']['away']  ?? null,
            'home_score_et'        => $scoreData['extratime']['home'] ?? null,
            'away_score_et'        => $scoreData['extratime']['away'] ?? null,
            'home_score_penalties' => $scoreData['penalty']['home']   ?? null,
            'away_score_penalties' => $scoreData['penalty']['away']   ?? null,
            // Live fields: current running score + granular minute/status
            'current_home_score'   => $item['goals']['home'] ?? null,
            'current_away_score'   => $item['goals']['away'] ?? null,
            'live_minute'          => $isDefinitive ? null : ($fixtureData['status']['elapsed'] ?? null),
            'live_status'          => $apiShortStatus,
        ];

        // Record the moment Robetting first detects the definitive transition.
        // Never overwrite once set — it must represent the earliest detection, not the latest refresh.
        if ($isDefinitive && $match->definitive_at === null) {
            $newScalars['definitive_at'] = now();
        }

        $dirty = $this->detectDirty($match, $newScalars, $kickoffAt);

        if (empty($dirty)) {
            return ['outcome' => 'unchanged'];
        }

        if (array_key_exists('kickoff_at', $dirty)) {
            $dirty['kickoff_at'] = $kickoffAt;
        }

        $match->update($dirty);
        return ['outcome' => 'updated'];
    }

    private function detectDirty(FootballMatch $match, array $newScalars, ?Carbon $newKickoffAt): array
    {
        $dirty = [];

        $existingKickoff = $match->kickoff_at?->utc()->format('Y-m-d H:i:s');
        $newKickoffStr   = $newKickoffAt?->format('Y-m-d H:i:s');
        if ($existingKickoff !== $newKickoffStr) {
            $dirty['kickoff_at'] = $newKickoffStr;
        }

        foreach ($newScalars as $field => $newVal) {
            $existing = $match->$field;
            if ($existing === null && $newVal === null) {
                continue;
            }
            if ($existing === null || $newVal === null || (string) $existing !== (string) $newVal) {
                $dirty[$field] = $newVal;
            }
        }

        return $dirty;
    }

    private function parseMatchday(string $round): ?int
    {
        if (preg_match('/(\d+)$/', $round, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function buildRunPayload(
        int    $dsId,
        string $syncType,
        mixed  $started,
        int    $updated,
        int    $unchanged,
        int    $skipped,
        int    $warnings,
        int    $apiCalls,
        ?int   $dailyRemaining,
        ?array $details = null,
    ): array {
        return [
            'data_source_id'  => $dsId,
            'sync_type'       => $syncType,
            'competition_id'  => null,
            'season_id'       => null,
            'mode'            => null,
            'started_at'      => $started,
            'finished_at'     => now(),
            'status'          => 'ok',
            'created_count'   => 0,
            'updated_count'   => $updated,
            'unchanged_count' => $unchanged,
            'skipped_count'   => $skipped,
            'warnings_count'  => $warnings,
            'api_calls'       => $apiCalls,
            'daily_remaining' => $dailyRemaining,
            'details'         => $details,
        ];
    }
}
