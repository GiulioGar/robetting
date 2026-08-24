<?php

namespace App\Services\Imports;

use App\Models\Competition;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\Season;
use App\Services\DataSources\FootballDataOrg\FootballDataOrgClient;
use App\Services\DataSources\FootballDataOrg\FootballDataOrgImporter;
use App\Services\Matches\CanonicalMatchResolver;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Finds competitions with a match currently in progress (based on
 * kickoff_at already stored in DB) and refreshes only those from
 * football-data.org, via FootballDataOrgImporter::syncMatches().
 *
 * Callable from the scheduled `robetting:sync-live-scores` command and from
 * the manual "Aggiorna ora" button in the Upload Manager UI — same call
 * either way, nothing duplicated between the two entry points.
 */
class LiveScoreSyncService
{
    private const FINAL_STATUSES = ['finished', 'postponed', 'cancelled'];

    /**
     * @return array{
     *     status: string,
     *     leagues: list<array{slug: string, status: string, result?: array, error?: string}>
     * }
     */
    public function sync(?int $hoursAfterKickoff = null, ?OutputInterface $output = null): array
    {
        $output ??= new NullOutput();
        $hoursAfterKickoff ??= (int) config('imports.live_sync_hours_after_kickoff', 3);

        $apiKey = config('services.football_data_org.api_key');

        if (empty($apiKey)) {
            throw new \RuntimeException('FOOTBALL_DATA_ORG_API_KEY non configurata in .env');
        }

        $inProgress = FootballMatch::query()
            ->where('kickoff_at', '<=', now())
            ->where('kickoff_at', '>=', now()->subHours($hoursAfterKickoff))
            ->whereNotIn('status', self::FINAL_STATUSES)
            ->select('competition_id', 'season_id')
            ->distinct()
            ->get();

        if ($inProgress->isEmpty()) {
            return ['status' => 'idle', 'leagues' => []];
        }

        $leagues       = require config_path('imports/football-data-co-uk-leagues.php');
        $fdoCodeBySlug = collect($leagues)->pluck('fdo_code', 'slug');

        $client   = new FootballDataOrgClient(config('services.football_data_org.base_url'), $apiKey);
        $resolver = new CanonicalMatchResolver();

        $report = ['status' => 'success', 'leagues' => []];

        foreach ($inProgress as $row) {
            $competition = Competition::find($row->competition_id);
            $season      = Season::find($row->season_id);

            if (!$competition || !$season) {
                $report['leagues'][] = [
                    'slug'   => '?',
                    'status' => 'skipped',
                    'error'  => "competition/season non trovati (competition_id={$row->competition_id}, season_id={$row->season_id})",
                ];
                continue;
            }

            $fdoCode = $fdoCodeBySlug->get($competition->slug);

            if ($fdoCode === null) {
                $report['leagues'][] = [
                    'slug'   => $competition->slug,
                    'status' => 'skipped',
                    'error'  => 'nessun codice football-data.org mappato per questa competizione',
                ];
                continue;
            }

            try {
                $importer = new FootballDataOrgImporter($client, $resolver, $output);
                $result   = $importer->syncMatches($competition, $season, $fdoCode);

                $report['leagues'][] = [
                    'slug'   => $competition->slug,
                    'status' => 'success',
                    'result' => $result,
                ];
            } catch (\Throwable $e) {
                $report['status']    = 'failed';
                $report['leagues'][] = [
                    'slug'   => $competition->slug,
                    'status' => 'failed',
                    'error'  => $e->getMessage(),
                ];
            }
        }

        return $report;
    }

    /**
     * Reconciliation pass for the 5 core leagues' current season: finds
     * matches whose kickoff has already passed but that are still not
     * finalized in DB (status != finished, or FT score missing), and
     * refreshes them from football-data.org.
     *
     * Deliberately bounded to the current season per competition (highest
     * year_start — Season.is_current is not reliably maintained, see
     * comment on that column's usage elsewhere) and to a configurable
     * lookback window (`imports.catch_up_max_days`), so this never turns
     * into a full historical rescan: older seasons are finalized via the
     * FDCUK CSV import instead.
     *
     * The actual persistence is delegated entirely to
     * FootballDataOrgImporter::syncMatches() — the same call sync() makes —
     * so precedence rules (kickoff/status overwrite, FT/HT score fill-only
     * with conflict logging) are never duplicated here. This method only
     * adds: candidate selection, and a read-only classification pass
     * (candidates/checked/updated/already_current/conflicts/errors) built
     * from a before/after DB snapshot plus one read-only lookup against the
     * same FDO payload, purely for reporting.
     *
     * @return array{leagues: list<array{
     *     slug: string, season: ?string, candidates: int, checked: int,
     *     updated: int, already_current: int, conflicts: int, errors: int,
     *     error_messages: list<string>
     * }>}
     */
    public function catchUp(?int $maxDays = null, ?OutputInterface $output = null): array
    {
        $output ??= new NullOutput();
        $maxDays ??= (int) config('imports.catch_up_max_days', 7);

        $apiKey = config('services.football_data_org.api_key');

        if (empty($apiKey)) {
            throw new \RuntimeException('FOOTBALL_DATA_ORG_API_KEY non configurata in .env');
        }

        $leagues = require config_path('imports/football-data-co-uk-leagues.php');
        $client  = new FootballDataOrgClient(config('services.football_data_org.base_url'), $apiKey);

        // Literal source slug — same convention already used in
        // HistoricalSeasonImportService::verifyLeague() rather than
        // exposing FootballDataOrgImporter::SOURCE_SLUG (private const).
        $fdoSourceId = DataSource::where('slug', 'football_data_org')->value('id');

        $report = ['leagues' => []];

        foreach ($leagues as $div => $cfg) {
            $slug    = $cfg['slug'];
            $fdoCode = $cfg['fdo_code'];

            $competition = Competition::where('slug', $slug)->first();

            if (!$competition) {
                $report['leagues'][] = $this->emptyCatchUpReport($slug, null, ['competizione non trovata']);
                continue;
            }

            // "Current season" = highest year_start for this competition —
            // Season.is_current is set true on every season ever imported
            // and never cleared, so it cannot be used to pick one.
            $season = Season::where('competition_id', $competition->id)
                ->orderByDesc('year_start')
                ->first();

            if (!$season) {
                $report['leagues'][] = $this->emptyCatchUpReport($slug, null, ['nessuna stagione trovata']);
                continue;
            }

            $candidates = FootballMatch::where('season_id', $season->id)
                ->where('kickoff_at', '<', now())
                ->where('kickoff_at', '>=', now()->subDays($maxDays))
                ->where(function ($q) {
                    $q->where('status', '!=', 'finished')
                        ->orWhereNull('status')
                        ->orWhereNull('home_score_ft')
                        ->orWhereNull('away_score_ft');
                })
                ->get(['id', 'status', 'home_score_ft', 'away_score_ft']);

            $candidateCount = $candidates->count();

            if ($candidateCount === 0) {
                $report['leagues'][] = $this->emptyCatchUpReport($slug, $season->name, []);
                continue;
            }

            $before = $candidates->keyBy('id')->map(fn (FootballMatch $m) => [
                'status'   => $m->status,
                'home_ft'  => $m->home_score_ft,
                'away_ft'  => $m->away_score_ft,
            ]);

            $checked       = 0;
            $conflicts     = 0;
            $errors        = 0;
            $errorMessages = [];

            try {
                $matchesData = $client->getMatches($fdoCode, $season->year_start);
                $byFdoId     = collect($matchesData['matches'] ?? [])->keyBy(fn (array $m) => (string) ($m['id'] ?? ''));

                $externalIds = MatchExternalId::where('data_source_id', $fdoSourceId)
                    ->whereIn('match_id', $candidates->pluck('id'))
                    ->pluck('external_id', 'match_id');

                foreach ($candidates as $m) {
                    $fdoId = $externalIds[$m->id] ?? null;

                    if ($fdoId === null) {
                        $errors++;
                        $errorMessages[] = "match {$m->id}: nessuna mappatura football_data_org trovata";
                        continue;
                    }

                    $fdoMatch = $byFdoId[$fdoId] ?? null;

                    if ($fdoMatch === null) {
                        $errors++;
                        $errorMessages[] = "match {$m->id}: id FDO {$fdoId} non presente nella risposta API";
                        continue;
                    }

                    $checked++;

                    $incomingFinished = ($fdoMatch['status'] ?? '') === 'FINISHED';
                    $incomingHome     = $fdoMatch['score']['fullTime']['home'] ?? null;
                    $incomingAway     = $fdoMatch['score']['fullTime']['away'] ?? null;

                    $localHome = $before[$m->id]['home_ft'];
                    $localAway = $before[$m->id]['away_ft'];

                    if (
                        $incomingFinished
                        && $localHome !== null && $localAway !== null
                        && $incomingHome !== null && $incomingAway !== null
                        && ((int) $localHome !== (int) $incomingHome || (int) $localAway !== (int) $incomingAway)
                    ) {
                        $conflicts++;
                    }
                }

                // Actual write — same call sync() makes, nothing duplicated.
                $importer = new FootballDataOrgImporter($client, new CanonicalMatchResolver(), $output);
                $importer->syncMatches($competition, $season, $fdoCode);
            } catch (\Throwable $e) {
                $report['leagues'][] = [
                    'slug'            => $slug,
                    'season'          => $season->name,
                    'candidates'      => $candidateCount,
                    'checked'         => $checked,
                    'updated'         => 0,
                    'already_current' => 0,
                    'conflicts'       => $conflicts,
                    'errors'          => $candidateCount - $checked,
                    'error_messages'  => array_merge($errorMessages, [$e->getMessage()]),
                ];
                continue;
            }

            $after = FootballMatch::whereIn('id', $candidates->pluck('id'))
                ->get(['id', 'status', 'home_score_ft', 'away_score_ft'])
                ->keyBy('id');

            $scoresEqual = fn ($x, $y) => $x === null || $y === null ? $x === $y : (int) $x === (int) $y;

            $updated = 0;
            $alreadyCurrent = 0;

            foreach ($candidates as $m) {
                $b = $before[$m->id];
                $a = $after[$m->id];

                $changed = $b['status'] !== $a->status
                    || !$scoresEqual($b['home_ft'], $a->home_score_ft)
                    || !$scoresEqual($b['away_ft'], $a->away_score_ft);

                if ($changed) {
                    $updated++;
                } else {
                    $alreadyCurrent++;
                }
            }

            $report['leagues'][] = [
                'slug'            => $slug,
                'season'          => $season->name,
                'candidates'      => $candidateCount,
                'checked'         => $checked,
                'updated'         => $updated,
                'already_current' => $alreadyCurrent,
                'conflicts'       => $conflicts,
                'errors'          => $errors,
                'error_messages'  => $errorMessages,
            ];
        }

        return $report;
    }

    /**
     * @return array{slug: string, season: ?string, candidates: int, checked: int, updated: int, already_current: int, conflicts: int, errors: int, error_messages: list<string>}
     */
    private function emptyCatchUpReport(string $slug, ?string $season, array $errorMessages): array
    {
        return [
            'slug'            => $slug,
            'season'          => $season,
            'candidates'      => 0,
            'checked'         => 0,
            'updated'         => 0,
            'already_current' => 0,
            'conflicts'       => 0,
            'errors'          => count($errorMessages),
            'error_messages'  => $errorMessages,
        ];
    }
}
