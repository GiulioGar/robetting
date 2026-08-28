<?php

namespace App\Services\Imports;

use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Services\DataSources\FootballDataOrg\FootballDataOrgClient;
use App\Services\DataSources\FootballDataOrg\FootballDataOrgImporter;
use App\Services\Matches\CanonicalMatchResolver;
use App\Services\Matches\MatchFieldPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Daily FDO calendar sync for the 5 core leagues.
 *
 * Season absent  → delegates entirely to FootballDataOrgImporter::import()
 * Season present → FootballDataOrgImporter::syncMatches() + before/after
 *                  kickoff comparison to detect and log real schedule changes.
 *
 * Kickoff updates use MatchFieldPolicy::forFdo() (kickoff: overwrite), so any
 * difference between FDO and canonical DB is applied unconditionally.
 * Normalises to UTC integer timestamps before comparing, avoiding false
 * positives from timezone representation differences.
 */
class FdoCalendarSyncService
{
    private const NON_TRACKABLE_STATUSES = ['finished', 'cancelled'];

    public function sync(?OutputInterface $output = null): array
    {
        $output ??= new NullOutput();

        $apiKey = config('services.football_data_org.api_key');

        if (empty($apiKey)) {
            throw new \RuntimeException('FOOTBALL_DATA_ORG_API_KEY non configurata in .env');
        }

        $client  = new FootballDataOrgClient(config('services.football_data_org.base_url'), $apiKey);
        $leagues = require config_path('imports/football-data-co-uk-leagues.php');

        $report = ['leagues' => []];

        foreach ($leagues as $cfg) {
            $slug    = $cfg['slug'];
            $fdoCode = $cfg['fdo_code'];

            try {
                $report['leagues'][] = $this->syncLeague($slug, $fdoCode, $client, $output);
            } catch (\Throwable $e) {
                Log::error('fdo-calendar-sync: league failed', [
                    'slug'  => $slug,
                    'error' => $e->getMessage(),
                ]);
                $report['leagues'][] = [
                    'slug'            => $slug,
                    'season'          => null,
                    'action'          => 'error',
                    'kickoff_changes' => 0,
                    'kickoff_log'     => [],
                    'result'          => null,
                    'error'           => $e->getMessage(),
                ];
            }
        }

        return $report;
    }

    private function syncLeague(
        string $slug,
        string $fdoCode,
        FootballDataOrgClient $client,
        OutputInterface $output,
    ): array {
        // Get FDO's authoritative current season (1 API call per league)
        $competitionData = $client->getCompetition($fdoCode);
        $startDate       = $competitionData['currentSeason']['startDate'] ?? null;
        $fdoYearStart    = $startDate ? (int) substr($startDate, 0, 4) : null;

        if (!$fdoYearStart) {
            throw new \RuntimeException(
                "No currentSeason.startDate in FDO response for competition code '{$fdoCode}'"
            );
        }

        $yearEnd    = $fdoYearStart + 1;
        $seasonName = $fdoYearStart . '/' . substr((string) $yearEnd, -2);

        // Resolve against canonical DB
        $competition = Competition::where('slug', $slug)->first();
        $season      = $competition
            ? Season::where('competition_id', $competition->id)
                ->where('year_start', $fdoYearStart)
                ->first()
            : null;

        $importer = new FootballDataOrgImporter($client, new CanonicalMatchResolver(), $output);

        // Season absent: delegate to full import (competition + teams + fixtures)
        if (!$season) {
            $result = $importer->import($fdoCode, $fdoYearStart, ['slug' => $slug]);

            return [
                'slug'            => $slug,
                'season'          => $seasonName,
                'action'          => 'imported',
                'kickoff_changes' => 0,
                'kickoff_log'     => [],
                'result'          => $result,
                'error'           => null,
            ];
        }

        // Season present: snapshot kickoffs of non-final matches before sync
        $beforeMap = FootballMatch::where('season_id', $season->id)
            ->whereNotIn('status', self::NON_TRACKABLE_STATUSES)
            ->get(['id', 'kickoff_at'])
            ->mapWithKeys(fn ($m) => [$m->id => $m->kickoff_at?->utc()->timestamp]);

        $result = $importer->syncMatches($competition, $season, $fdoCode, MatchFieldPolicy::forFdoCalendar());

        // Reload same match IDs after sync and compare UTC timestamps
        $afterMap = FootballMatch::whereIn('id', $beforeMap->keys())
            ->get(['id', 'kickoff_at'])
            ->mapWithKeys(fn ($m) => [$m->id => $m->kickoff_at?->utc()->timestamp]);

        $kickoffLog = [];

        foreach ($beforeMap as $matchId => $beforeTs) {
            $afterTs = $afterMap[$matchId] ?? null;

            // Normalised UTC integer timestamps: identical means no real change
            if ($beforeTs === $afterTs) {
                continue;
            }

            $entry = [
                'match_id' => $matchId,
                'old'      => $beforeTs !== null
                    ? Carbon::createFromTimestamp($beforeTs)->utc()->toIso8601String()
                    : null,
                'new'      => $afterTs !== null
                    ? Carbon::createFromTimestamp($afterTs)->utc()->toIso8601String()
                    : null,
            ];

            Log::info('fdo-calendar-sync: kickoff updated', array_merge($entry, ['slug' => $slug]));
            $kickoffLog[] = $entry;
        }

        return [
            'slug'            => $slug,
            'season'          => $season->name,
            'action'          => 'synced',
            'kickoff_changes' => count($kickoffLog),
            'kickoff_log'     => $kickoffLog,
            'result'          => $result,
            'error'           => null,
        ];
    }
}
