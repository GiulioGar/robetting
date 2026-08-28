<?php

namespace App\Services\DataSources\GoalApi;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\Season;
use App\Models\TeamExternalId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GoalApiFixtureLinker
{
    public const SOURCE_SLUG = 'goal_api';

    private array $result = [
        'already_mapped' => 0,
        'linked'         => 0,
        'skipped'        => 0,
    ];

    private array $unresolvedTeams = [];

    /** Cache: "competition_id:leagueYear" => Season|null */
    private array $seasonCache = [];

    public function ensureDataSource(): DataSource
    {
        return DataSource::firstOrCreate(
            ['slug' => self::SOURCE_SLUG],
            [
                'name'               => 'Goal-API.com',
                'source_type'        => 'api_json',
                'base_url'           => 'https://api.goal-api.com/v1',
                'rate_limit_per_day' => 1000,
                'is_active'          => true,
                'notes'              => 'Free tier: 1000 req/day, 1000/900s rolling window. Use internal string IDs as route params (not numeric apiId).',
            ],
        );
    }

    public function ensureCompetitionExternalId(DataSource $source, string $goalApiLeagueId, int $competitionId): void
    {
        CompetitionExternalId::firstOrCreate(
            ['data_source_id' => $source->id, 'external_id' => $goalApiLeagueId],
            ['competition_id' => $competitionId, 'external_name' => null],
        );
    }

    /**
     * Links a single GOAL API fixture to its canonical Robetting match.
     *
     * Does NOT create new canonical matches — only creates MatchExternalId mappings
     * for matches that already exist (created by FDO). Also creates TeamExternalId
     * mappings as a side effect so future polls hit the fast path.
     *
     * Returns one of: 'already_mapped' | 'linked' | 'skipped'
     */
    public function linkFixture(array $fixture, DataSource $source, array $aliases): string
    {
        $goalApiFixtureId = $fixture['id'] ?? null;

        if (!$goalApiFixtureId) {
            Log::warning('goal-api-linker: fixture missing id');
            $this->result['skipped']++;
            return 'skipped';
        }

        // 1. Already mapped? (idempotency fast path)
        $existing = MatchExternalId::where('data_source_id', $source->id)
            ->where('external_id', $goalApiFixtureId)
            ->first();

        if ($existing) {
            $this->result['already_mapped']++;
            return 'already_mapped';
        }

        // 2. Resolve canonical competition via competition_external_ids
        $leagueId = $fixture['leagueId'] ?? null;

        if (!$leagueId) {
            Log::warning('goal-api-linker: fixture missing leagueId', ['fixture_id' => $goalApiFixtureId]);
            $this->result['skipped']++;
            return 'skipped';
        }

        $compExtId = CompetitionExternalId::where('data_source_id', $source->id)
            ->where('external_id', $leagueId)
            ->first();

        if (!$compExtId) {
            $this->result['skipped']++;
            return 'skipped';
        }

        $competition = $compExtId->competition;

        // 3. Resolve canonical season from GOAL API leagueYear (e.g. "2026/2027").
        //    Does NOT use MAX(year_start) — requires exact competition_id + year_start match.
        //    This prevents future-season leakage when the DB already contains season 2027/28.
        $leagueYear = $fixture['leagueYear'] ?? null;

        if (!$leagueYear) {
            Log::warning('goal-api-linker: fixture missing leagueYear', ['fixture_id' => $goalApiFixtureId]);
            $this->result['skipped']++;
            return 'skipped';
        }

        $season = $this->resolveSeasonFromLeagueYear($competition, $leagueYear, $goalApiFixtureId);

        if (!$season) {
            $this->result['skipped']++;
            return 'skipped';
        }

        // 4. Resolve canonical team IDs
        $homeTeamId = $this->resolveTeamId(
            $fixture['homeTeamId'] ?? '',
            $fixture['homeTeamName'] ?? '',
            $source,
            $aliases,
        );
        $awayTeamId = $this->resolveTeamId(
            $fixture['awayTeamId'] ?? '',
            $fixture['awayTeamName'] ?? '',
            $source,
            $aliases,
        );

        if (!$homeTeamId || !$awayTeamId) {
            $this->trackUnresolvedTeam($fixture['homeTeamName'] ?? '', $homeTeamId);
            $this->trackUnresolvedTeam($fixture['awayTeamName'] ?? '', $awayTeamId);

            Log::warning('goal-api-linker: unresolved team(s)', [
                'fixture_id'   => $goalApiFixtureId,
                'home'         => $fixture['homeTeamName'] ?? null,
                'away'         => $fixture['awayTeamName'] ?? null,
                'homeResolved' => $homeTeamId !== null,
                'awayResolved' => $awayTeamId !== null,
            ]);
            $this->result['skipped']++;
            return 'skipped';
        }

        // 5. Find canonical match — no kickoff tolerance needed:
        //    competition + season + home_team + away_team is unique per season.
        $candidates = FootballMatch::where('competition_id', $competition->id)
            ->where('season_id', $season->id)
            ->where('home_team_id', $homeTeamId)
            ->where('away_team_id', $awayTeamId)
            ->get();

        if ($candidates->count() === 0) {
            Log::info('goal-api-linker: no canonical match found', [
                'fixture_id'   => $goalApiFixtureId,
                'competition'  => $competition->slug,
                'season'       => $season->name,
                'home_team_id' => $homeTeamId,
                'away_team_id' => $awayTeamId,
            ]);
            $this->result['skipped']++;
            return 'skipped';
        }

        if ($candidates->count() > 1) {
            Log::warning('goal-api-linker: multiple canonical matches — ambiguous, skipping', [
                'fixture_id' => $goalApiFixtureId,
                'candidates' => $candidates->pluck('id')->all(),
            ]);
            $this->result['skipped']++;
            return 'skipped';
        }

        $match = $candidates->first();

        // 6. Create match_external_id + team_external_ids
        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $source->id,
            'external_id'    => $goalApiFixtureId,
            'external_name'  => null,
        ]);

        $this->ensureTeamExternalId(
            $homeTeamId,
            $fixture['homeTeamId'] ?? '',
            $fixture['homeTeamName'] ?? null,
            $source,
        );
        $this->ensureTeamExternalId(
            $awayTeamId,
            $fixture['awayTeamId'] ?? '',
            $fixture['awayTeamName'] ?? null,
            $source,
        );

        $this->result['linked']++;
        return 'linked';
    }

    public function getResult(): array
    {
        return $this->result;
    }

    public function getUnresolvedTeams(): array
    {
        return array_keys($this->unresolvedTeams);
    }

    /**
     * Resolves the canonical Season by parsing year_start from GOAL API's leagueYear field.
     * Results are cached per (competition_id, leagueYear) to avoid redundant DB queries.
     *
     * Requires exactly 1 Season for (competition_id, year_start).
     * Returns null and logs if 0 or >1 seasons found.
     */
    private function resolveSeasonFromLeagueYear(
        Competition $competition,
        string $leagueYear,
        string $fixtureId,
    ): ?Season {
        $cacheKey = $competition->id . ':' . $leagueYear;

        if (array_key_exists($cacheKey, $this->seasonCache)) {
            return $this->seasonCache[$cacheKey];
        }

        // "2026/2027" → year_start = 2026
        $yearStart = (int) substr($leagueYear, 0, 4);

        if ($yearStart < 2000 || $yearStart > 2100) {
            Log::warning('goal-api-linker: unparseable leagueYear', [
                'fixture_id' => $fixtureId,
                'leagueYear' => $leagueYear,
            ]);
            return $this->seasonCache[$cacheKey] = null;
        }

        $seasons = Season::where('competition_id', $competition->id)
            ->where('year_start', $yearStart)
            ->get();

        if ($seasons->count() === 0) {
            Log::warning('goal-api-linker: no season in DB for leagueYear', [
                'fixture_id'     => $fixtureId,
                'competition'    => $competition->slug,
                'leagueYear'     => $leagueYear,
                'year_start'     => $yearStart,
            ]);
            return $this->seasonCache[$cacheKey] = null;
        }

        if ($seasons->count() > 1) {
            Log::warning('goal-api-linker: multiple seasons for leagueYear — ambiguous', [
                'fixture_id'  => $fixtureId,
                'competition' => $competition->slug,
                'leagueYear'  => $leagueYear,
                'season_ids'  => $seasons->pluck('id')->all(),
            ]);
            return $this->seasonCache[$cacheKey] = null;
        }

        return $this->seasonCache[$cacheKey] = $seasons->first();
    }

    private function resolveTeamId(
        string $goalApiTeamId,
        string $teamName,
        DataSource $source,
        array $aliases,
    ): ?int {
        // Fast path: team already mapped via previous run
        if ($goalApiTeamId) {
            $extId = TeamExternalId::where('data_source_id', $source->id)
                ->where('external_id', $goalApiTeamId)
                ->first();

            if ($extId) {
                return $extId->team_id;
            }
        }

        // Alias file: GOAL API team name → canonical teams.name
        if ($teamName && isset($aliases[$teamName])) {
            $team = DB::table('teams')->where('name', $aliases[$teamName])->first();
            return $team?->id;
        }

        // Exact match on teams.name (for APIs that use the canonical name directly)
        if ($teamName) {
            $team = DB::table('teams')->where('name', $teamName)->first();
            return $team?->id;
        }

        return null;
    }

    private function ensureTeamExternalId(
        int $canonicalTeamId,
        string $goalApiTeamId,
        ?string $teamName,
        DataSource $source,
    ): void {
        if (!$goalApiTeamId) {
            return;
        }

        TeamExternalId::firstOrCreate(
            ['data_source_id' => $source->id, 'external_id' => $goalApiTeamId],
            ['team_id' => $canonicalTeamId, 'external_name' => $teamName],
        );
    }

    private function trackUnresolvedTeam(string $name, ?int $resolvedId): void
    {
        if (!$name || $resolvedId !== null) {
            return;
        }
        $this->unresolvedTeams[$name] = true;
    }
}
