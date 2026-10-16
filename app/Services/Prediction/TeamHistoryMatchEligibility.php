<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use App\Services\DataSources\ApiFootball\MatchDataScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "Team History" match eligibility — a team's
 * real competitive history, cross-competition, cross-season, strict as-of.
 *
 * Eligibility:
 *   - match involves $teamId (home or away);
 *   - status = 'finished' (a match that was actually played);
 *   - home_score_ft / away_score_ft not null;
 *   - kickoff_at strictly before the target kickoff;
 *   - competition scope ∈ {CORE, TEAM_HISTORY} per MatchDataScope — ELO_ONLY
 *     and UNKNOWN are excluded (see Step C1 / A5.4-A5.6).
 *
 * No day limits, no season reset, no weights, no decay, no arbitrary
 * thresholds — those are caller concerns (e.g. ->slice(-10)), not this
 * class's. Reuses MatchDataScope; never re-derives the CORE/TEAM_HISTORY/
 * ELO_ONLY classification.
 *
 * Deliberately opt-in: the existing (legacy, competition+season bound)
 * Recent pipeline in PreMatchFeatureAggregator is untouched by this class
 * unless a caller explicitly opts into the new "team_history" window mode.
 */
class TeamHistoryMatchEligibility
{
    /**
     * All eligible matches for $teamId strictly before $targetKickoff,
     * ordered chronologically ascending (oldest first) — same convention as
     * PreMatchFeatureAggregator's existing windows, so ->slice(-10) takes
     * the most recent N.
     *
     * @return Collection<int, FootballMatch>
     */
    public static function matchesBefore(int $teamId, CarbonInterface $targetKickoff): Collection
    {
        $candidates = FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where('kickoff_at', '<', $targetKickoff)
            ->where(function ($q) use ($teamId): void {
                $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->orderBy('kickoff_at')
            ->get([
                'id', 'competition_id', 'season_id', 'home_team_id', 'away_team_id',
                'kickoff_at', 'status', 'home_score_ft', 'away_score_ft',
                'home_score_ht', 'away_score_ht',
            ]);

        return MatchDataScope::filterByScope(
            $candidates,
            [MatchDataScope::CORE, MatchDataScope::TEAM_HISTORY],
        )->values();
    }
}
