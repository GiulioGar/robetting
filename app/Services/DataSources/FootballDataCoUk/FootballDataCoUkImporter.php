<?php

namespace App\Services\DataSources\FootballDataCoUk;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\TeamExternalId;
use App\Services\Matches\CanonicalMatchResolver;
use App\Services\Matches\MatchFieldPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;

class FootballDataCoUkImporter
{
    public const SOURCE_SLUG = 'football_data_co_uk';

    private const REQUIRED_HEADERS = [
        'Div', 'Date', 'Time', 'HomeTeam', 'AwayTeam',
        'FTHG', 'FTAG', 'FTR', 'HTHG', 'HTAG', 'HTR',
    ];

    private array $result = [
        'teams'      => ['created' => 0, 'updated' => 0],
        'matches'    => ['created' => 0, 'linked' => 0, 'updated' => 0, 'skipped' => 0],
        'statistics' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
    ];

    public function __construct(
        private readonly TeamResolver          $resolver,
        private readonly CanonicalMatchResolver $matchResolver,
        private readonly OutputInterface        $output,
    ) {}

    public function import(string $filePath, string $season, array $options = []): array
    {
        $competitionSlug = $options['competition_slug'] ?? null;
        $dryRun          = (bool) ($options['dry_run'] ?? false);
        $limit           = isset($options['limit']) ? (int) $options['limit'] : null;

        // 1. Parse CSV
        $rows = $this->parseCsv($filePath);

        if ($limit !== null) {
            $rows = array_slice($rows, 0, $limit);
            $this->output->writeln("[INFO]  Limiting to first {$limit} rows");
        }

        // 2. Pre-validation: resolve all teams before touching the DB
        $uniqueNames = $this->extractUniqueTeamNames($rows);
        $resolved    = $this->resolver->resolveAll($uniqueNames);

        $unresolved = array_filter($resolved, fn (ResolveResult $r) => !$r->resolved);

        if (!empty($unresolved)) {
            $lines = array_map(
                fn (ResolveResult $r) => "  - {$r->csvName}: {$r->detail}",
                $unresolved,
            );
            throw new \RuntimeException(
                "Unresolved teams — add them to the alias file or create them canonically first:\n" .
                implode("\n", $lines)
            );
        }

        // 3. Determine Div from first row
        $div = trim($rows[0]['Div'] ?? '');
        if ($div === '') {
            throw new \RuntimeException("Cannot determine Div from CSV first row.");
        }

        // 4. Main import inside outer transaction
        DB::beginTransaction();

        try {
            $dataSource = $this->ensureDataSource();
            $this->output->writeln("[INFO]  DataSource: {$dataSource->slug}");

            $competition = $this->processCompetition($div, $dataSource, $competitionSlug);
            $this->output->writeln("[INFO]  Competition: {$competition->name} ({$competition->slug})");

            $seasonObj = $this->processSeason($season, $competition, $dataSource);
            $this->output->writeln("[INFO]  Season: {$seasonObj->name}");

            $teamIdMap = $this->processTeams($resolved, $dataSource);
            $this->output->writeln(sprintf(
                "[INFO]  Teams: %d new mappings registered, %d already mapped",
                $this->result['teams']['created'],
                count($teamIdMap) - $this->result['teams']['created'],
            ));

            $this->processMatches($rows, $competition, $seasonObj, $teamIdMap, $dataSource, $season);
            $this->output->writeln(sprintf(
                "[INFO]  Matches: %d created, %d linked, %d updated, %d skipped",
                $this->result['matches']['created'],
                $this->result['matches']['linked'],
                $this->result['matches']['updated'],
                $this->result['matches']['skipped'],
            ));
            $this->output->writeln(sprintf(
                "[INFO]  Statistics: %d created, %d updated, %d skipped (all-null)",
                $this->result['statistics']['created'],
                $this->result['statistics']['updated'],
                $this->result['statistics']['skipped'],
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

    // -------------------------------------------------------------------------
    // CSV parsing
    // -------------------------------------------------------------------------

    private function parseCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: {$filePath}");
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw new \RuntimeException("CSV file is empty: {$filePath}");
            }

            // Strip UTF-8 BOM and normalise line endings
            $firstLine = str_replace("\xEF\xBB\xBF", '', trim($firstLine));
            $headers   = str_getcsv($firstLine);

            $missing = array_diff(self::REQUIRED_HEADERS, $headers);
            if (!empty($missing)) {
                throw new \RuntimeException("CSV missing required headers: " . implode(', ', $missing));
            }

            $headerCount = count($headers);
            $rows        = [];
            $lineNum     = 1;

            while (($values = fgetcsv($handle, 0, ',')) !== false) {
                $lineNum++;

                if ($values === [null]) {
                    continue;
                }

                if (count($values) !== $headerCount) {
                    Log::warning(sprintf(
                        "football-data-co-uk: row %d has %d fields, expected %d — skipping",
                        $lineNum, count($values), $headerCount,
                    ));
                    continue;
                }

                $rows[] = array_combine($headers, $values);
            }

            return $rows;

        } finally {
            fclose($handle);
        }
    }

    private function extractUniqueTeamNames(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            $home = trim($row['HomeTeam'] ?? '');
            $away = trim($row['AwayTeam'] ?? '');
            if ($home !== '') {
                $names[$home] = true;
            }
            if ($away !== '') {
                $names[$away] = true;
            }
        }

        return array_keys($names);
    }

    // -------------------------------------------------------------------------
    // Entity processors
    // -------------------------------------------------------------------------

    private function ensureDataSource(): DataSource
    {
        return DataSource::firstOrCreate(
            ['slug' => self::SOURCE_SLUG],
            [
                'name'        => 'Football-Data.co.uk',
                'source_type' => 'csv',
                'base_url'    => 'https://www.football-data.co.uk',
                'is_active'   => true,
            ],
        );
    }

    private function processCompetition(
        string $div,
        DataSource $source,
        ?string $slug,
    ): Competition {
        $extId = CompetitionExternalId::where('data_source_id', $source->id)
            ->where('external_id', $div)
            ->first();

        if ($extId) {
            return $extId->competition;
        }

        if (empty($slug)) {
            throw new \RuntimeException(
                "Competition '{$div}' has no mapping for source '{$source->slug}'. " .
                "Pass --competition-slug=<slug> to link it. Example: --competition-slug=serie-a"
            );
        }

        $competition = Competition::where('slug', $slug)->first();

        if (!$competition) {
            throw new \RuntimeException("No competition found with slug '{$slug}'.");
        }

        CompetitionExternalId::create([
            'competition_id' => $competition->id,
            'data_source_id' => $source->id,
            'external_id'    => $div,
            'external_name'  => $div,
        ]);

        return $competition;
    }

    private function processSeason(
        string $seasonKey,
        Competition $competition,
        DataSource $source,
    ): Season {
        $extId = SeasonExternalId::where('data_source_id', $source->id)
            ->where('competition_id', $competition->id)
            ->where('external_id', $seasonKey)
            ->first();

        if ($extId) {
            return $extId->season;
        }

        [$yearStart, $yearEnd] = explode('-', $seasonKey);
        $name = $yearStart . '/' . substr($yearEnd, -2);

        $season = Season::firstOrCreate(
            ['competition_id' => $competition->id, 'name' => $name],
            [
                'year_start' => (int) $yearStart,
                'year_end'   => (int) $yearEnd,
                'is_current' => true,
            ],
        );

        SeasonExternalId::firstOrCreate(
            [
                'data_source_id' => $source->id,
                'competition_id' => $competition->id,
                'external_id'    => $seasonKey,
            ],
            [
                'season_id'     => $season->id,
                'external_name' => $name,
            ],
        );

        return $season;
    }

    /**
     * Creates missing team_external_ids entries for the FDCUK source.
     * Teams are already resolved; this just registers the source mapping.
     *
     * @param  array<string, ResolveResult>  $resolved
     * @return array<string, int>  csvName → team_id
     */
    private function processTeams(array $resolved, DataSource $source): array
    {
        $teamIdMap = [];

        foreach ($resolved as $csvName => $result) {
            $teamIdMap[$csvName] = $result->teamId;

            // Create mapping only if it wasn't the source of resolution
            // (resolved via 'external_id' means the mapping already exists)
            if ($result->reason !== 'external_id') {
                TeamExternalId::create([
                    'team_id'        => $result->teamId,
                    'data_source_id' => $source->id,
                    'external_id'    => $csvName,
                    'external_name'  => $csvName,
                ]);
                $this->result['teams']['created']++;
            }
        }

        return $teamIdMap;
    }

    // -------------------------------------------------------------------------
    // Match processing
    // -------------------------------------------------------------------------

    private function processMatches(
        array $rows,
        Competition $competition,
        Season $season,
        array $teamIdMap,
        DataSource $source,
        string $seasonKey,
    ): void {
        foreach ($rows as $row) {
            try {
                $this->processMatch($row, $competition, $season, $teamIdMap, $source, $seasonKey);
            } catch (\Throwable $e) {
                $key = $this->buildMatchKey($row, $seasonKey);
                Log::warning("football-data-co-uk: skipping match [{$key}]: {$e->getMessage()}");
                $this->result['matches']['skipped']++;
            }
        }
    }

    private function processMatch(
        array $row,
        Competition $competition,
        Season $season,
        array $teamIdMap,
        DataSource $source,
        string $seasonKey,
    ): void {
        $homeCSV = trim($row['HomeTeam'] ?? '');
        $awayCSV = trim($row['AwayTeam'] ?? '');
        $dateStr = trim($row['Date'] ?? '');
        $timeStr = trim($row['Time'] ?? '');

        if ($homeCSV === '' || $awayCSV === '') {
            throw new \RuntimeException("Missing HomeTeam or AwayTeam");
        }

        if ($dateStr === '' || $timeStr === '') {
            throw new \RuntimeException("Missing Date or Time");
        }

        // Parse kickoff — source timezone is Europe/London, store as UTC
        try {
            $kickoffLocal = Carbon::createFromFormat('d/m/Y H:i', $dateStr . ' ' . $timeStr, 'Europe/London');
            if ($kickoffLocal === false) {
                throw new \RuntimeException("createFromFormat returned false");
            }
            $kickoffUtc = $kickoffLocal->copy()->utc();
        } catch (\Throwable $e) {
            throw new \RuntimeException("Invalid datetime '{$dateStr} {$timeStr}': " . $e->getMessage());
        }

        $homeTeamId = $teamIdMap[$homeCSV] ?? null;
        $awayTeamId = $teamIdMap[$awayCSV] ?? null;

        // Defensive: should never happen after successful pre-validation
        if ($homeTeamId === null || $awayTeamId === null) {
            throw new \RuntimeException("Team not in resolved map: home='{$homeCSV}' away='{$awayCSV}'");
        }

        $ftHome = $this->parseScore($row['FTHG'] ?? '');
        $ftAway = $this->parseScore($row['FTAG'] ?? '');
        $htHome = $this->parseScore($row['HTHG'] ?? '');
        $htAway = $this->parseScore($row['HTAG'] ?? '');

        // Sanity checks — import regardless, scores are the source of truth
        $matchKey = $this->buildMatchKey($row, $seasonKey);

        if ($ftHome !== null && $ftAway !== null && trim($row['FTR'] ?? '') !== '') {
            $expectedFtr = $ftHome > $ftAway ? 'H' : ($ftHome < $ftAway ? 'A' : 'D');
            if (trim($row['FTR']) !== $expectedFtr) {
                Log::warning(
                    "football-data-co-uk: FTR mismatch [{$matchKey}]: CSV={$row['FTR']} calc={$expectedFtr}"
                );
            }
        }

        if ($htHome !== null && $htAway !== null && trim($row['HTR'] ?? '') !== '') {
            $expectedHtr = $htHome > $htAway ? 'H' : ($htHome < $htAway ? 'A' : 'D');
            if (trim($row['HTR']) !== $expectedHtr) {
                Log::warning(
                    "football-data-co-uk: HTR mismatch [{$matchKey}]: CSV={$row['HTR']} calc={$expectedHtr}"
                );
            }
        }

        $matchFields = [
            'competition_id'       => $competition->id,
            'season_id'            => $season->id,
            'home_team_id'         => $homeTeamId,
            'away_team_id'         => $awayTeamId,
            'kickoff_at'           => $kickoffUtc,
            'kickoff_timezone'     => 'Europe/London',
            'status'               => $this->determineStatus($ftHome, $ftAway, $kickoffUtc),
            'home_score_ht'        => $htHome,
            'away_score_ht'        => $htAway,
            'home_score_ft'        => $ftHome,
            'away_score_ft'        => $ftAway,
            'home_score_et'        => null,
            'away_score_et'        => null,
            'home_score_penalties' => null,
            'away_score_penalties' => null,
            'round'                => null,
            'matchday'             => null,
            'venue_name'           => null,
        ];

        $resolved = $this->matchResolver->resolve(
            $competition->id,
            $season->id,
            $homeTeamId,
            $awayTeamId,
            $source,
            $matchKey,
            $matchFields,
            MatchFieldPolicy::forFdcuk(),
            "fdcuk match {$matchKey}",
        );
        $this->result['matches'][$resolved->action]++;

        if ($resolved->match !== null) {
            try {
                $this->processStatistics($resolved->match, $source, $row);
            } catch (\Throwable $e) {
                Log::warning("football-data-co-uk: statistics failed [{$matchKey}]: {$e->getMessage()}");
            }
        }
    }

    // -------------------------------------------------------------------------
    // Statistics processing
    // -------------------------------------------------------------------------

    private function processStatistics(FootballMatch $match, DataSource $source, array $row): void
    {
        $statsFields = array_filter([
            'home_shots'           => $this->parseScore($row['HS']  ?? ''),
            'away_shots'           => $this->parseScore($row['AS']  ?? ''),
            'home_shots_on_target' => $this->parseScore($row['HST'] ?? ''),
            'away_shots_on_target' => $this->parseScore($row['AST'] ?? ''),
            'home_fouls'           => $this->parseScore($row['HF']  ?? ''),
            'away_fouls'           => $this->parseScore($row['AF']  ?? ''),
            'home_corners'         => $this->parseScore($row['HC']  ?? ''),
            'away_corners'         => $this->parseScore($row['AC']  ?? ''),
            'home_yellow_cards'    => $this->parseScore($row['HY']  ?? ''),
            'away_yellow_cards'    => $this->parseScore($row['AY']  ?? ''),
            'home_red_cards'       => $this->parseScore($row['HR']  ?? ''),
            'away_red_cards'       => $this->parseScore($row['AR']  ?? ''),
        ], fn ($v) => $v !== null);

        if (empty($statsFields)) {
            $this->result['statistics']['skipped']++;
            return;
        }

        $statistic = MatchStatistic::updateOrCreate(
            ['match_id' => $match->id, 'data_source_id' => $source->id],
            $statsFields,
        );

        if ($statistic->wasRecentlyCreated) {
            $this->result['statistics']['created']++;
        } else {
            $this->result['statistics']['updated']++;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildMatchKey(array $row, string $seasonKey): string
    {
        return implode('|', [
            trim($row['Div'] ?? ''),
            $seasonKey,
            trim($row['HomeTeam'] ?? ''),
            trim($row['AwayTeam'] ?? ''),
        ]);
    }

    private function parseScore(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function determineStatus(?int $ftHome, ?int $ftAway, Carbon $kickoffUtc): string
    {
        if ($ftHome !== null && $ftAway !== null) {
            return 'finished';
        }

        return $kickoffUtc->copy()->utc()->isPast() ? 'unknown' : 'scheduled';
    }
}
