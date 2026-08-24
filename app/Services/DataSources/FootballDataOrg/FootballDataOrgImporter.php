<?php

namespace App\Services\DataSources\FootballDataOrg;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\Matches\CanonicalMatchResolver;
use App\Services\Matches\MatchFieldPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;

class FootballDataOrgImporter
{
    private const STATUS_MAP = [
        'SCHEDULED'  => 'scheduled',
        'TIMED'      => 'scheduled',
        'IN_PLAY'    => 'live',
        'PAUSED'     => 'live',
        'FINISHED'   => 'finished',
        'AWARDED'    => 'finished',
        'POSTPONED'  => 'postponed',
        'SUSPENDED'  => 'suspended',
        'CANCELLED'  => 'cancelled',
    ];

    private const SOURCE_SLUG = 'football_data_org';

    private array $result = [
        'countries' => ['created' => 0, 'updated' => 0],
        'teams'     => ['created' => 0, 'updated' => 0],
        'matches'   => ['created' => 0, 'linked' => 0, 'updated' => 0, 'skipped' => 0],
    ];

    public function __construct(
        private readonly FootballDataOrgClient  $client,
        private readonly CanonicalMatchResolver $matchResolver,
        private readonly OutputInterface        $output,
    ) {}

    public function import(string $competitionCode, int $season, array $options = []): array
    {
        $slug   = $options['slug'] ?? null;
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit  = isset($options['limit']) ? (int) $options['limit'] : null;

        DB::beginTransaction();

        try {
            $dataSource = $this->ensureDataSource();
            $this->output->writeln("[INFO]  DataSource: {$dataSource->slug}");

            $competitionData = $this->client->getCompetition($competitionCode);

            $country = $this->processCountry($competitionData['area'] ?? []);
            $this->output->writeln("[INFO]  Country: {$country->name}");

            $competition = $this->processCompetition($competitionData, $country, $dataSource, $slug);
            $this->output->writeln("[INFO]  Competition: {$competition->name} ({$competition->slug})");

            $teamsData = $this->client->getTeams($competitionCode, $season);

            $seasonObj = $this->processSeason($competitionData, $teamsData, $competition, $dataSource);
            $this->output->writeln("[INFO]  Season: {$seasonObj->name}");

            $teamIdMap = $this->processTeams($teamsData['teams'] ?? [], $dataSource);
            $this->output->writeln(sprintf(
                "[INFO]  Teams: %d created, %d updated",
                $this->result['teams']['created'],
                $this->result['teams']['updated'],
            ));

            $matchesData  = $this->client->getMatches($competitionCode, $season);
            $matches      = $matchesData['matches'] ?? [];
            $totalFromApi = $matchesData['resultSet']['count'] ?? count($matches);

            if ($totalFromApi !== count($matches)) {
                Log::warning(
                    "football-data.org: expected {$totalFromApi} matches from API, received " . count($matches)
                );
            }

            if ($limit !== null) {
                $matches = array_slice($matches, 0, $limit);
                $this->output->writeln("[INFO]  Limiting to first {$limit} matches");
            }

            $this->processMatches($matches, $competition, $seasonObj, $teamIdMap, $dataSource);
            $this->output->writeln(sprintf(
                "[INFO]  Matches: %d created, %d linked, %d updated, %d skipped",
                $this->result['matches']['created'],
                $this->result['matches']['linked'],
                $this->result['matches']['updated'],
                $this->result['matches']['skipped'],
            ));

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->result;
    }

    /**
     * Lightweight refresh for a competition/season that has already been
     * imported once: skips getCompetition()/getTeams() (team rosters don't
     * change mid-season) and reuses the TeamExternalId mappings already in
     * DB, so it costs a single API call instead of the 3 that import() uses.
     * Intended for frequent polling while matches are in progress.
     */
    public function syncMatches(Competition $competition, Season $season, string $competitionCode): array
    {
        DB::beginTransaction();

        try {
            $dataSource = $this->ensureDataSource();

            $teamIdMap = TeamExternalId::where('data_source_id', $dataSource->id)
                ->pluck('team_id', 'external_id')
                ->all();

            $matchesData = $this->client->getMatches($competitionCode, $season->year_start);
            $matches     = $matchesData['matches'] ?? [];

            $this->processMatches($matches, $competition, $season, $teamIdMap, $dataSource);
            $this->output->writeln(sprintf(
                "[INFO]  Matches: %d created, %d linked, %d updated, %d skipped",
                $this->result['matches']['created'],
                $this->result['matches']['linked'],
                $this->result['matches']['updated'],
                $this->result['matches']['skipped'],
            ));

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->result;
    }

    private function ensureDataSource(): DataSource
    {
        return DataSource::firstOrCreate(
            ['slug' => self::SOURCE_SLUG],
            [
                'name'        => 'Football-Data.org',
                'source_type' => 'api_json',
                'base_url'    => config('services.football_data_org.base_url'),
                'is_active'   => true,
            ],
        );
    }

    private function processCountry(array $area): Country
    {
        $code = $area['code'] ?? null;
        $name = $area['name'] ?? null;

        if (empty($name)) {
            return Country::firstOrCreate(
                ['name' => 'Unknown'],
                ['is_active' => false],
            );
        }

        if ($code) {
            $country = Country::where('football_code', $code)->first()
                ?? Country::where('iso_code_alpha3', $code)->first();

            if ($country) {
                if (empty($country->football_code)) {
                    $country->football_code = $code;
                    $country->save();
                    $this->result['countries']['updated']++;
                }
                return $country;
            }
        }

        $country = Country::where('name', $name)->first();

        if ($country) {
            if ($code && empty($country->football_code)) {
                $country->football_code = $code;
                $country->save();
                $this->result['countries']['updated']++;
            }
            return $country;
        }

        $country = Country::create([
            'name'            => $name,
            'football_code'   => $code,
            'iso_code_alpha3' => ($code && strlen($code) === 3) ? $code : null,
            'is_active'       => true,
        ]);

        $this->result['countries']['created']++;

        return $country;
    }

    private function resolveCountry(array $area): ?Country
    {
        $code = $area['code'] ?? null;
        $name = $area['name'] ?? null;

        if ($code) {
            $country = Country::where('football_code', $code)->first()
                ?? Country::where('iso_code_alpha3', $code)->first();

            if ($country) {
                return $country;
            }
        }

        if ($name) {
            return Country::where('name', $name)->first();
        }

        return null;
    }

    private function processCompetition(
        array $data,
        Country $country,
        DataSource $source,
        ?string $slug,
    ): Competition {
        $fdoId = (string) ($data['id'] ?? '');
        $name  = $data['name'] ?? '';

        $extId = CompetitionExternalId::where('data_source_id', $source->id)
            ->where('external_id', $fdoId)
            ->first();

        if ($extId) {
            $competition       = $extId->competition;
            $competition->name = $name;
            $competition->save();
            return $competition;
        }

        if (empty($slug)) {
            throw new \RuntimeException(
                "Competition '{$fdoId}' ({$name}) not found in database. " .
                "Pass --slug=<canonical-slug> to create it. Example: --slug=serie-a"
            );
        }

        $competition = Competition::where('slug', $slug)->first();

        if (!$competition) {
            $competition = Competition::create([
                'country_id' => $country->id,
                'name'       => $name,
                'short_name' => $name,
                'slug'       => $slug,
                'format'     => 'league',
                'gender'     => 'male',
                'is_active'  => true,
            ]);
        }

        CompetitionExternalId::create([
            'competition_id' => $competition->id,
            'data_source_id' => $source->id,
            'external_id'    => $fdoId,
            'external_name'  => $name,
        ]);

        return $competition;
    }

    private function processSeason(
        array $competitionData,
        array $teamsData,
        Competition $competition,
        DataSource $source,
    ): Season {
        $seasonData = !empty($teamsData['season']) ? $teamsData['season'] : ($competitionData['currentSeason'] ?? []);

        $fdoSeasonId = (string) ($seasonData['id'] ?? '');
        $startDate   = $seasonData['startDate'] ?? null;
        $endDate     = $seasonData['endDate'] ?? null;

        $yearStart = $startDate ? (int) substr($startDate, 0, 4) : null;
        $yearEnd   = $endDate   ? (int) substr($endDate, 0, 4)   : null;

        $name = ($yearStart && $yearEnd && $yearStart !== $yearEnd)
            ? "{$yearStart}/" . substr((string) $yearEnd, -2)
            : (string) ($yearStart ?? 'unknown');

        if ($fdoSeasonId) {
            $extId = SeasonExternalId::where('data_source_id', $source->id)
                ->where('competition_id', $competition->id)
                ->where('external_id', $fdoSeasonId)
                ->first();

            if ($extId) {
                $season             = $extId->season;
                $season->start_date = $startDate;
                $season->end_date   = $endDate;
                $season->save();
                return $season;
            }
        }

        $season = Season::firstOrCreate(
            ['competition_id' => $competition->id, 'name' => $name],
            [
                'year_start' => $yearStart,
                'year_end'   => $yearEnd,
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'is_current' => true,
            ],
        );

        if ($fdoSeasonId) {
            SeasonExternalId::firstOrCreate(
                [
                    'data_source_id' => $source->id,
                    'competition_id' => $competition->id,
                    'external_id'    => $fdoSeasonId,
                ],
                [
                    'season_id'     => $season->id,
                    'external_name' => $name,
                ],
            );
        }

        return $season;
    }

    private function processTeams(array $teams, DataSource $source): array
    {
        $teamIdMap = [];

        foreach ($teams as $teamData) {
            $fdoTeamId = (string) ($teamData['id'] ?? '');

            if (empty($fdoTeamId)) {
                Log::warning('football-data.org: team missing id, skipping');
                continue;
            }

            $extId = TeamExternalId::where('data_source_id', $source->id)
                ->where('external_id', $fdoTeamId)
                ->first();

            if ($extId) {
                $teamIdMap[$fdoTeamId] = $extId->team_id;
                $team = $extId->team;
                if ($team) {
                    $team->fill([
                        'name'       => $teamData['name'] ?? $team->name,
                        'short_name' => $teamData['shortName'] ?? $team->short_name,
                        'code'       => $teamData['tla'] ?? $team->code,
                    ]);
                    if ($team->isDirty()) {
                        $team->save();
                        $this->result['teams']['updated']++;
                    }
                }
                continue;
            }

            $teamCountry = isset($teamData['area'])
                ? $this->resolveCountry($teamData['area'])
                : null;

            $team = Team::create([
                'country_id'   => $teamCountry?->id,
                'name'         => $teamData['name'] ?? 'Unknown',
                'short_name'   => $teamData['shortName'] ?? null,
                'code'         => $teamData['tla'] ?? null,
                'type'         => 'club',
                'founded_year' => isset($teamData['founded']) ? (int) $teamData['founded'] : null,
                'is_active'    => true,
            ]);

            TeamExternalId::create([
                'team_id'        => $team->id,
                'data_source_id' => $source->id,
                'external_id'    => $fdoTeamId,
                'external_name'  => $teamData['name'] ?? null,
            ]);

            $teamIdMap[$fdoTeamId] = $team->id;
            $this->result['teams']['created']++;
        }

        return $teamIdMap;
    }

    private function processMatches(
        array $matches,
        Competition $competition,
        Season $season,
        array $teamIdMap,
        DataSource $source,
    ): void {
        foreach ($matches as $matchData) {
            try {
                $this->processMatch($matchData, $competition, $season, $teamIdMap, $source);
            } catch (\Throwable $e) {
                $fdoId = (string) ($matchData['id'] ?? 'unknown');
                Log::error("football-data.org: match {$fdoId} failed: {$e->getMessage()}");
                $this->result['matches']['skipped']++;
            }
        }
    }

    private function processMatch(
        array $matchData,
        Competition $competition,
        Season $season,
        array $teamIdMap,
        DataSource $source,
    ): void {
        $fdoMatchId = (string) ($matchData['id'] ?? '');
        $fdoHomeId  = (string) ($matchData['homeTeam']['id'] ?? '');
        $fdoAwayId  = (string) ($matchData['awayTeam']['id'] ?? '');

        $homeTeamId = $teamIdMap[$fdoHomeId] ?? null;
        $awayTeamId = $teamIdMap[$fdoAwayId] ?? null;

        if ($homeTeamId === null || $awayTeamId === null) {
            Log::warning(
                "football-data.org: unresolved team for match {$fdoMatchId} " .
                "(home FDO: {$fdoHomeId}, away FDO: {$fdoAwayId}) — skipping"
            );
            $this->result['matches']['skipped']++;
            return;
        }

        $status    = $this->mapStatus($matchData['status'] ?? '', $fdoMatchId);
        $scores    = $this->mapScores($matchData['score'] ?? []);
        $kickoffAt = isset($matchData['utcDate'])
            ? Carbon::parse($matchData['utcDate'])->utc()
            : null;

        $matchFields = [
            'competition_id'       => $competition->id,
            'season_id'            => $season->id,
            'home_team_id'         => $homeTeamId,
            'away_team_id'         => $awayTeamId,
            'kickoff_at'           => $kickoffAt,
            'kickoff_timezone'     => 'UTC',
            'round'                => $matchData['stage'] ?? null,
            'matchday'             => isset($matchData['matchday']) ? (int) $matchData['matchday'] : null,
            'status'               => $status,
            'venue_name'           => $matchData['venue'] ?? null,
            'home_score_ht'        => $scores['ht_home'],
            'away_score_ht'        => $scores['ht_away'],
            'home_score_ft'        => $scores['ft_home'],
            'away_score_ft'        => $scores['ft_away'],
            'home_score_et'        => $scores['et_home'],
            'away_score_et'        => $scores['et_away'],
            'home_score_penalties' => $scores['pen_home'],
            'away_score_penalties' => $scores['pen_away'],
        ];

        $resolved = $this->matchResolver->resolve(
            $competition->id,
            $season->id,
            $homeTeamId,
            $awayTeamId,
            $source,
            $fdoMatchId,
            $matchFields,
            MatchFieldPolicy::forFdo(),
            "fdo match {$fdoMatchId}",
        );
        $this->result['matches'][$resolved->action]++;
    }

    private function mapStatus(string $fdoStatus, string $matchId): string
    {
        if (array_key_exists($fdoStatus, self::STATUS_MAP)) {
            return self::STATUS_MAP[$fdoStatus];
        }

        Log::warning("football-data.org: unknown status '{$fdoStatus}' for match {$matchId}");

        return 'unknown';
    }

    private function mapScores(array $score): array
    {
        $ftHome = $score['regularTime']['home'] ?? $score['fullTime']['home'] ?? null;
        $ftAway = $score['regularTime']['away'] ?? $score['fullTime']['away'] ?? null;

        $htHome = $score['halfTime']['home'] ?? null;
        $htAway = $score['halfTime']['away'] ?? null;

        $etHome = array_key_exists('extraTime', $score) ? ($score['extraTime']['home'] ?? null) : null;
        $etAway = array_key_exists('extraTime', $score) ? ($score['extraTime']['away'] ?? null) : null;

        $penHome = array_key_exists('penalties', $score) ? ($score['penalties']['home'] ?? null) : null;
        $penAway = array_key_exists('penalties', $score) ? ($score['penalties']['away'] ?? null) : null;

        return [
            'ht_home'  => $htHome,
            'ht_away'  => $htAway,
            'ft_home'  => $ftHome,
            'ft_away'  => $ftAway,
            'et_home'  => $etHome,
            'et_away'  => $etAway,
            'pen_home' => $penHome,
            'pen_away' => $penAway,
        ];
    }
}
