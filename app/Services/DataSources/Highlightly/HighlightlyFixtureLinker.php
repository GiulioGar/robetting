<?php

namespace App\Services\DataSources\Highlightly;

use App\Models\CompetitionExternalId;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\TeamExternalId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Links Highlightly match IDs to canonical Robetting matches.
 *
 * Does NOT create canonical matches — only creates MatchExternalId mappings for
 * matches already created by FDO. Team mappings are cached as TeamExternalId rows
 * so subsequent runs take the fast path.
 *
 * Strategy: caller provides the canonical competition_id and season_id explicitly.
 * No fuzzy matching. Ambiguous or unresolvable → skip + log.
 */
class HighlightlyFixtureLinker
{
    public const SOURCE_SLUG = 'highlightly';

    private int $alreadyMapped = 0;
    private int $linked        = 0;
    private int $skipped       = 0;

    private array $unresolvedTeams = [];

    public function ensureDataSource(): DataSource
    {
        return DataSource::firstOrCreate(
            ['slug' => self::SOURCE_SLUG],
            [
                'name'               => 'Highlightly',
                'source_type'        => 'api_json',
                'base_url'           => 'https://soccer.highlightly.net',
                'rate_limit_per_day' => 100,
                'is_active'          => true,
                'notes'              => 'Basic plan: 100 req/day. Auth: x-rapidapi-key + x-rapidapi-host.',
            ],
        );
    }

    public function ensureCompetitionExternalId(
        DataSource $source,
        int $hlLeagueId,
        int $competitionId,
    ): void {
        CompetitionExternalId::firstOrCreate(
            ['data_source_id' => $source->id, 'external_id' => (string) $hlLeagueId],
            ['competition_id' => $competitionId, 'external_name' => null],
        );
    }

    /**
     * Attempts to link a single Highlightly match object to its canonical match.
     *
     * @param  array  $hlMatch  One item from GET /matches response "data" array.
     * @param  DataSource  $source
     * @param  int  $competitionId  Canonical competition ID (already known from league context).
     * @param  int  $seasonId  Canonical season ID (already known from command context).
     * @param  array  $aliases  From config/team-aliases/highlightly.php.
     * @return string  'already_mapped' | 'linked' | 'skipped'
     */
    public function linkMatch(
        array $hlMatch,
        DataSource $source,
        int $competitionId,
        int $seasonId,
        array $aliases,
    ): string {
        $hlMatchId = $hlMatch['id'] ?? null;

        if (!$hlMatchId) {
            Log::warning('highlightly-linker: match missing id');
            $this->skipped++;
            return 'skipped';
        }

        $hlMatchId = (string) $hlMatchId;

        // Fast path: already mapped
        if (MatchExternalId::where('data_source_id', $source->id)->where('external_id', $hlMatchId)->exists()) {
            $this->alreadyMapped++;
            return 'already_mapped';
        }

        // Resolve canonical team IDs
        $hlHomeId   = (string) ($hlMatch['homeTeam']['id'] ?? '');
        $hlHomeName = (string) ($hlMatch['homeTeam']['name'] ?? '');
        $hlAwayId   = (string) ($hlMatch['awayTeam']['id'] ?? '');
        $hlAwayName = (string) ($hlMatch['awayTeam']['name'] ?? '');

        $homeTeamId = $this->resolveTeamId($hlHomeId, $hlHomeName, $source, $aliases);
        $awayTeamId = $this->resolveTeamId($hlAwayId, $hlAwayName, $source, $aliases);

        if (!$homeTeamId || !$awayTeamId) {
            $this->trackUnresolved($hlHomeName, $homeTeamId);
            $this->trackUnresolved($hlAwayName, $awayTeamId);
            Log::info('highlightly-linker: unresolved team(s)', [
                'hl_match_id'  => $hlMatchId,
                'home'         => $hlHomeName,
                'away'         => $hlAwayName,
                'homeResolved' => $homeTeamId !== null,
                'awayResolved' => $awayTeamId !== null,
            ]);
            $this->skipped++;
            return 'skipped';
        }

        // Find canonical match (competition + season + home_team + away_team is unique)
        $candidates = FootballMatch::where('competition_id', $competitionId)
            ->where('season_id', $seasonId)
            ->where('home_team_id', $homeTeamId)
            ->where('away_team_id', $awayTeamId)
            ->get();

        if ($candidates->count() === 0) {
            Log::info('highlightly-linker: no canonical match found', [
                'hl_match_id'   => $hlMatchId,
                'home_team_id'  => $homeTeamId,
                'away_team_id'  => $awayTeamId,
                'competition_id' => $competitionId,
                'season_id'     => $seasonId,
            ]);
            $this->skipped++;
            return 'skipped';
        }

        if ($candidates->count() > 1) {
            Log::warning('highlightly-linker: ambiguous canonical match', [
                'hl_match_id'  => $hlMatchId,
                'candidates'   => $candidates->pluck('id')->all(),
            ]);
            $this->skipped++;
            return 'skipped';
        }

        $match = $candidates->first();

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $source->id,
            'external_id'    => $hlMatchId,
            'external_name'  => "{$hlHomeName} vs {$hlAwayName}",
        ]);

        $this->ensureTeamExternalId($homeTeamId, $hlHomeId, $hlHomeName, $source);
        $this->ensureTeamExternalId($awayTeamId, $hlAwayId, $hlAwayName, $source);

        $this->linked++;
        return 'linked';
    }

    public function getResult(): array
    {
        return [
            'already_mapped' => $this->alreadyMapped,
            'linked'         => $this->linked,
            'skipped'        => $this->skipped,
        ];
    }

    public function getUnresolvedTeams(): array
    {
        return array_keys($this->unresolvedTeams);
    }

    private function resolveTeamId(
        string $hlTeamId,
        string $teamName,
        DataSource $source,
        array $aliases,
    ): ?int {
        // Fast path: team already mapped from a previous run
        if ($hlTeamId) {
            $extId = TeamExternalId::where('data_source_id', $source->id)
                ->where('external_id', $hlTeamId)
                ->first();

            if ($extId) {
                return $extId->team_id;
            }
        }

        // Alias file: Highlightly name → canonical teams.name
        $canonicalName = $aliases[$teamName] ?? $teamName;

        $team = DB::table('teams')->where('name', $canonicalName)->first();

        return $team?->id;
    }

    private function ensureTeamExternalId(
        int $canonicalTeamId,
        string $hlTeamId,
        string $teamName,
        DataSource $source,
    ): void {
        if (!$hlTeamId) {
            return;
        }

        TeamExternalId::firstOrCreate(
            ['data_source_id' => $source->id, 'external_id' => $hlTeamId],
            ['team_id' => $canonicalTeamId, 'external_name' => $teamName],
        );
    }

    private function trackUnresolved(string $name, ?int $resolvedId): void
    {
        if ($name && $resolvedId === null) {
            $this->unresolvedTeams[$name] = true;
        }
    }
}
