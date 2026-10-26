<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\Season;
use Illuminate\Support\Collection;

/**
 * Eligibility policy for `match_statistics` sync ONLY (events, player
 * statistics, lineups and injuries keep using MatchDataScope directly).
 *
 * On top of the scopes a caller allows (default CORE + TEAM_HISTORY), admits
 * ELO_ONLY matches that belong to a configured UEFA club competition
 * (Champions / Europa / Conference League) AND to that competition's current
 * season, so finished UEFA match pages can show real match statistics.
 *
 * "Current season" = the competition's season with the highest year_start
 * (is_current is unreliable — see live score sync notes). Past UEFA seasons
 * and any other ELO_ONLY / UNKNOWN competition (national cups) stay excluded.
 * MatchDataScope itself is untouched.
 */
class MatchStatisticsSyncPolicy
{
    /** UEFA club competition slugs eligible for current-season statistics. */
    public const UEFA_SLUGS = [
        'uefa-champions-league',
        'uefa-europa-league',
        'uefa-conference-league',
    ];

    /**
     * @param  Collection<int, FootballMatch>  $matches  Needs id, competition_id, season_id, home_team_id, away_team_id.
     * @param  array<int, string>              $allowedScopes
     * @param  bool                            $includeUefaCurrentSeason
     * @return Collection<int, FootballMatch>
     */
    public static function filter(
        Collection $matches,
        array $allowedScopes = [MatchDataScope::CORE, MatchDataScope::TEAM_HISTORY],
        bool $includeUefaCurrentSeason = true,
    ): Collection {
        if ($matches->isEmpty()) {
            return $matches;
        }

        $scopes      = MatchDataScope::classifyMany($matches);
        $eloOnly     = $matches->filter(fn (FootballMatch $m) => ($scopes[$m->id] ?? null) === MatchDataScope::ELO_ONLY);
        $uefaCurrent = ($includeUefaCurrentSeason && $eloOnly->isNotEmpty())
            ? self::uefaCurrentSeasonMatchIds($eloOnly)
            : [];

        return $matches
            ->filter(fn (FootballMatch $m) => in_array($scopes[$m->id] ?? null, $allowedScopes, true)
                || isset($uefaCurrent[$m->id]))
            ->values();
    }

    /**
     * @param  Collection<int, FootballMatch>  $eloOnlyMatches
     * @return array<int, true>  match_id => true
     */
    private static function uefaCurrentSeasonMatchIds(Collection $eloOnlyMatches): array
    {
        $configured = array_values(array_intersect(
            self::UEFA_SLUGS,
            array_values((array) config('api-football.additional_competitions', []))
        ));
        if ($configured === []) {
            return [];
        }

        $uefaCompetitionIds = Competition::whereIn('slug', $configured)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($uefaCompetitionIds === []) {
            return [];
        }

        $maxYearByCompetition = Season::whereIn('competition_id', $uefaCompetitionIds)
            ->selectRaw('competition_id, MAX(year_start) as max_year')
            ->groupBy('competition_id')
            ->pluck('max_year', 'competition_id');

        $yearBySeasonId = Season::whereIn('id', $eloOnlyMatches->pluck('season_id')->filter()->unique())
            ->pluck('year_start', 'id');

        $eligible = [];
        foreach ($eloOnlyMatches as $m) {
            $competitionId = (int) $m->competition_id;
            if (! in_array($competitionId, $uefaCompetitionIds, true)) {
                continue;
            }
            $year = $yearBySeasonId[$m->season_id] ?? null;
            if ($year !== null && (int) $year === (int) ($maxYearByCompetition[$competitionId] ?? -1)) {
                $eligible[$m->id] = true;
            }
        }

        return $eligible;
    }
}
