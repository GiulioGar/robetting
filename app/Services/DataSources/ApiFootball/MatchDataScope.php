<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\Season;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for classifying a match as CORE, TEAM_HISTORY,
 * ELO_ONLY or UNKNOWN — see A5.4/A5.5/A5.6 audit. Every advanced-data sync
 * entrypoint that should respect this scope goes through here instead of
 * re-deriving the same core_leagues / additional_competitions / season_team
 * logic.
 *
 *   CORE         — competition_id ∈ config('api-football.core_leagues').
 *   TEAM_HISTORY — competition_id ∈ config('api-football.additional_competitions')
 *                  AND (home OR away) team is a core-league team in the SAME
 *                  seasons.year_start (never compared by season_id directly —
 *                  the additional competition's season row is a different
 *                  row from the domestic one; only year_start is comparable).
 *   ELO_ONLY     — an additional_competitions match where neither team is
 *                  core in that year_start.
 *   UNKNOWN      — competition in neither config list. NEVER implicitly
 *                  treated as CORE — mass advanced-data enrichment must
 *                  treat UNKNOWN as not eligible. Fixture/calendar sync and
 *                  result/status refresh never consult this scope at all,
 *                  so UNKNOWN has no effect on them (see A5.6 audit).
 *
 * Batch-safe by design: classifyMany() issues at most 3 queries total for
 * any number of matches (competition-id lookups + season_id -> year_start +
 * season_team core-team membership) — never one query per match. Nothing is
 * cached across calls (deliberately: a static cross-call cache would go
 * stale the moment core_leagues/additional_competitions or the competitions
 * table changes mid-process), so every call recomputes from the current DB
 * state — cheap, since each lookup is a single batched query.
 */
class MatchDataScope
{
    public const CORE         = 'core';
    public const TEAM_HISTORY = 'team_history';
    public const ELO_ONLY     = 'elo_only';
    public const UNKNOWN      = 'unknown';

    public static function classify(FootballMatch $match): string
    {
        return self::classifyMany(collect([$match]))[$match->id];
    }

    /**
     * @param  Collection<int, FootballMatch>  $matches  Must expose id, competition_id,
     *                                                    season_id, home_team_id, away_team_id.
     * @return array<int, string>  match_id => self::CORE|TEAM_HISTORY|ELO_ONLY|UNKNOWN
     */
    public static function classifyMany(Collection $matches): array
    {
        if ($matches->isEmpty()) {
            return [];
        }

        $coreCompetitionIds       = self::competitionIdsForSlugs((array) config('api-football.core_leagues', []));
        $additionalCompetitionIds = self::competitionIdsForSlugs((array) config('api-football.additional_competitions', []));

        $result        = [];
        $needCoreCheck = []; // match_id => [year_start, home_team_id, away_team_id]

        $seasonIds = $matches->pluck('season_id')->filter()->unique();
        $yearStartBySeasonId = $seasonIds->isEmpty()
            ? collect()
            : Season::whereIn('id', $seasonIds)->pluck('year_start', 'id');

        foreach ($matches as $match) {
            if (in_array((int) $match->competition_id, $coreCompetitionIds, true)) {
                $result[$match->id] = self::CORE;
                continue;
            }

            if (!in_array((int) $match->competition_id, $additionalCompetitionIds, true)) {
                // Not configured in either list — never implicitly CORE.
                $result[$match->id] = self::UNKNOWN;
                continue;
            }

            $yearStart = $yearStartBySeasonId[$match->season_id] ?? null;
            if ($yearStart === null) {
                $result[$match->id] = self::ELO_ONLY;
                continue;
            }

            $needCoreCheck[$match->id] = [$yearStart, (int) $match->home_team_id, (int) $match->away_team_id];
        }

        if ($needCoreCheck !== []) {
            $yearStarts         = array_values(array_unique(array_column($needCoreCheck, 0)));
            $coreTeamIdsByYear   = self::coreTeamIdsByYearStart($yearStarts);

            foreach ($needCoreCheck as $matchId => [$yearStart, $homeId, $awayId]) {
                $coreTeams = $coreTeamIdsByYear[$yearStart] ?? [];
                $result[$matchId] = (isset($coreTeams[$homeId]) || isset($coreTeams[$awayId]))
                    ? self::TEAM_HISTORY
                    : self::ELO_ONLY;
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, FootballMatch>  $matches
     * @param  array<int, string>               $allowedScopes
     * @return Collection<int, FootballMatch>
     */
    public static function filterByScope(Collection $matches, array $allowedScopes): Collection
    {
        if ($matches->isEmpty()) {
            return $matches;
        }

        $scopes = self::classifyMany($matches);

        return $matches
            ->filter(fn (FootballMatch $m) => in_array($scopes[$m->id] ?? null, $allowedScopes, true))
            ->values();
    }

    /** @param  array<int, string>  $slugMap  config array, e.g. [135 => 'serie-a', ...] */
    private static function competitionIdsForSlugs(array $slugMap): array
    {
        $slugs = array_values($slugMap);
        if ($slugs === []) {
            return [];
        }

        return Competition::whereIn('slug', $slugs)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  array<int, int>  $yearStarts
     * @return array<int, array<int, true>>  year_start => [team_id => true]
     */
    private static function coreTeamIdsByYearStart(array $yearStarts): array
    {
        $byYear = array_fill_keys($yearStarts, []);

        $coreSlugs = array_values((array) config('api-football.core_leagues', []));
        if ($coreSlugs === []) {
            return $byYear;
        }

        $rows = DB::table('season_team')
            ->join('seasons', 'seasons.id', '=', 'season_team.season_id')
            ->join('competitions', 'competitions.id', '=', 'seasons.competition_id')
            ->whereIn('competitions.slug', $coreSlugs)
            ->whereIn('seasons.year_start', $yearStarts)
            ->get(['seasons.year_start', 'season_team.team_id']);

        foreach ($rows as $row) {
            $byYear[(int) $row->year_start][(int) $row->team_id] = true;
        }

        return $byYear;
    }
}
