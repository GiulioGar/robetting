<?php

namespace App\Services\Analytics;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\MatchLineupPlayer;
use App\Models\MatchPlayerStatistic;
use App\Models\PlayerAbsence;
use App\Models\PlayerExternalId;
use Carbon\Carbon;

/**
 * Pre-match, fail-closed measure of how much of a player's "really available"
 * playing time (matches where he was not under a certain injury/suspension)
 * he actually used. Availability V1 — Step 5G. Not wired to the Prediction
 * Engine; standalone calculator only.
 *
 * ── Denominator universe (covered_matches) ──────────────────────────────────
 *
 * Team matches with kickoff_at < cutoff AND matches.injuries_fetched_at IS
 * NOT NULL (a pre-match injury snapshot was actually taken for that fixture —
 * see ApiFootballInjurySyncService). Matches never checked for injuries carry
 * no information either way and are excluded entirely (not treated as 0
 * absences).
 *
 * ── Forced unavailability (excluded_absence_matches) ────────────────────────
 *
 * A covered match is removed from the denominator when player_absences has a
 * row for (match, player, team, data_source=api-football) with
 * absence_type = 'Missing Fixture' AND reason IN self::FORCED_UNAVAILABLE_REASONS
 * (injury + medical + suspension reasons validated in Step 5F/5G — see class
 * constant below). `Questionable` absences and any EXCLUDE/unlisted reason do
 * NOT force unavailability — the match stays a normal available-match
 * candidate.
 *
 * ── Minutes semantics (Step 5E policy) ──────────────────────────────────────
 *
 * For each remaining (available-candidate) match:
 *   - no match_player_statistics row at all → player was not part of the
 *     matchday squad → 0 played minutes (assumption; not an injury/suspension
 *     case, so the match is NOT excluded).
 *   - games_minutes is numeric → used as-is.
 *   - games_minutes NULL + games_substitute=1 + no substitution-in event
 *     evidence (match_events, event_type=substitution,
 *     related_player_external_id = this player's api-football external id)
 *     → 0 (unused bench, Step 5E "BENCH_NOT_USED").
 *   - games_minutes NULL + evidence of entry (Step 5E "SUB_USED_NULL"), OR
 *     games_substitute=0 with NULL minutes (never observed in Step 5E, but
 *     handled the same way defensively), OR no external-id mapping exists to
 *     even check for evidence → genuinely missing data: the player-match is
 *     dropped entirely from both numerator and denominator and counted in
 *     invalid_minutes_matches. intStat() is never touched.
 *
 * `available_matches` = covered_matches − excluded_absence_matches −
 * invalid_minutes_matches. `available_minutes` = available_matches × 90.
 * `available_minutes_share` = played_minutes / available_minutes, or null
 * when available_matches = 0 (never a fake 0).
 *
 * ── Membership window (Step 5H) ──────────────────────────────────────────
 *
 * season_player has no temporal columns — it only proves a player belonged
 * to a team SOMEWHERE in the season, never when. To avoid counting matches
 * played before a (possibly mid-season transferred) player ever joined the
 * team, the calculator first derives a `first membership evidence` kickoff:
 * the earliest kickoff_at, strictly before cutoff, across three independent
 * certain-evidence sources for THIS (player, team) pair:
 *   - match_player_statistics (player_id + team_id on the row itself);
 *   - match_lineup_players, joined via this player's api-football external
 *     id to match_lineups.team_id;
 *   - player_absences (player_id + team_id on the row itself — a club
 *     reporting an absence already proves the player was part of its squad
 *     tracking at that date).
 * Only matches with kickoff_at >= that floor (and still < cutoff) ever enter
 * the denominator. No transfer date is ever invented. If NONE of the three
 * sources has any evidence for (player, team) before cutoff, the calculator
 * fails closed: zero covered matches, not "member since season start".
 *
 * Step 5I adds the membership CEILING (cessation): the earliest certain
 * evidence, still strictly before cutoff, of the SAME player at ANY OTHER
 * team, strictly after the floor above. When found, only matches with
 * kickoff_at < ceiling are kept — this is what stops a transferred player
 * from still being counted as "available with 0 minutes" at his old club
 * after he has already certainly moved on. Evidence tied to the exact same
 * timestamp as the floor is never treated as a ceiling (ambiguous/simultaneous
 * evidence never flips membership — fail-closed). When no later other-team
 * evidence exists, membership is treated as open through cutoff (no ceiling).
 *

 * ── No-leakage guarantee ──────────────────────────────────────────────────
 *
 * The query itself enforces kickoff_at < cutoff; an internal timestamp
 * comparison on the fetched rows is a second line of defence (same pattern as
 * TeamAbsenceImpactCalculator / PlayerRecentLoadCalculator). The membership
 * floor is itself derived only from evidence strictly before cutoff.
 *
 * ── Diagnostic flag (Step 6C) ────────────────────────────────────────────
 *
 * `has_membership_evidence` distinguishes, when available_minutes_share is
 * null, WHY: false means firstMembershipKickoff() found nothing at all
 * (no evidence this player ever belonged to this team before cutoff); true
 * means membership was proven but no usable covered match remained (e.g.
 * zero covered history in the window, or every candidate match was either
 * forced-absence-excluded or had genuinely invalid minutes). Used by callers
 * (TeamAvailabilityCalculator's incompleteness-cause analysis) without
 * duplicating this calculator's internal membership logic.
 */
class PlayerAvailableMinutesShareCalculator
{
    private const MINUTES_PER_MATCH = 90;

    /** Step 5F/5G validated INJURY reasons under absence_type = 'Missing Fixture'. */
    private const INJURY_REASONS = [
        'Knee Injury', 'Injury', 'Muscle Injury', 'Ankle Injury', 'Thigh Injury',
        'Hamstring Injury', 'Calf Injury', 'Back Injury', 'Groin Injury',
        'Shoulder Injury', 'Foot Injury', 'Hip Injury', 'Achilles Tendon Injury',
        'Broken calfbone', 'Broken shinbone', 'Abdominal strain', 'Arm Injury',
        'Broken Leg', 'Hand Injury', 'Knock', 'Leg Injury', 'Broken nose',
        'Contusion', 'Neck Injury', 'Ribs Injury', 'Wrist Injury',
    ];

    /** Step 5G validated MEDICAL reasons under absence_type = 'Missing Fixture'. */
    private const MEDICAL_REASONS = [
        'Heart Problems', 'Surgery', 'Hernia', 'Health problems', 'Illness',
    ];

    /** Step 5G validated SUSPENSION reasons under absence_type = 'Missing Fixture'. */
    private const SUSPENSION_REASONS = [
        'Red Card', 'Suspended', 'Doping', 'Yellow Cards',
    ];

    /**
     * @return array{
     *     played_minutes: int,
     *     available_minutes: int,
     *     available_minutes_share: float|null,
     *     covered_matches: int,
     *     available_matches: int,
     *     excluded_absence_matches: int,
     *     invalid_minutes_matches: int,
     *     has_membership_evidence: bool,
     * }
     */
    public static function calculate(int $playerId, int $teamId, Carbon $cutoff): array
    {
        $dsId = DataSource::where('slug', 'api-football')->value('id');
        if ($dsId === null) {
            return self::emptyResult(false);
        }

        $cutoffTs = $cutoff->getTimestamp();

        // ── Membership window (Step 5H start, Step 5I end): START = earliest
        // certain evidence of this player at this team; END = earliest certain
        // evidence of the SAME player at ANY OTHER team, strictly after START.
        // Both bounds use only evidence strictly before cutoff. No START at
        // all → fail closed, nothing is covered.
        $membershipFloor = self::firstMembershipKickoff($playerId, $teamId, $dsId, $cutoff);
        if ($membershipFloor === null) {
            return self::emptyResult(false);
        }
        $membershipFloorTs = $membershipFloor->getTimestamp();

        $membershipCeiling = self::firstOtherTeamKickoffAfter($playerId, $teamId, $dsId, $cutoff, $membershipFloorTs);
        $membershipCeilingTs = $membershipCeiling?->getTimestamp();

        // ── Covered matches: START <= kickoff_at < cutoff (and < END, if any)
        // AND injuries_fetched_at set ──
        $matchesQuery = FootballMatch::where('kickoff_at', '<', $cutoff)
            ->where('kickoff_at', '>=', $membershipFloor)
            ->whereNotNull('injuries_fetched_at')
            ->where(function ($q) use ($teamId) {
                $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            });
        if ($membershipCeiling !== null) {
            $matchesQuery->where('kickoff_at', '<', $membershipCeiling);
        }
        $matches = $matchesQuery->get(['id', 'kickoff_at']);

        // Internal anti-leakage + membership guard (second line of defence).
        $matches = $matches->filter(
            fn($m) => $m->kickoff_at !== null
                && $m->kickoff_at->getTimestamp() < $cutoffTs
                && $m->kickoff_at->getTimestamp() >= $membershipFloorTs
                && ($membershipCeilingTs === null || $m->kickoff_at->getTimestamp() < $membershipCeilingTs)
        );

        $coveredMatches = $matches->count();
        if ($coveredMatches === 0) {
            return self::emptyResult(true);
        }

        $matchIds = $matches->pluck('id')->map(fn($id) => (int) $id)->all();

        // ── Forced-unavailable matches (certain injury/medical/suspension) ───
        $forcedUnavailableMatchIds = PlayerAbsence::whereIn('match_id', $matchIds)
            ->where('player_id', $playerId)
            ->where('team_id', $teamId)
            ->where('data_source_id', $dsId)
            ->where('absence_type', 'Missing Fixture')
            ->whereIn('reason', self::FORCED_UNAVAILABLE_REASONS())
            ->pluck('match_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->all();

        $excludedAbsenceMatches = count($forcedUnavailableMatchIds);
        $forcedSet = array_flip($forcedUnavailableMatchIds);

        $remainingMatchIds = array_values(array_filter(
            $matchIds,
            fn($id) => !isset($forcedSet[$id])
        ));

        if (empty($remainingMatchIds)) {
            return [
                'played_minutes'            => 0,
                'available_minutes'         => 0,
                'available_minutes_share'   => null,
                'covered_matches'           => $coveredMatches,
                'available_matches'         => 0,
                'excluded_absence_matches'  => $excludedAbsenceMatches,
                'invalid_minutes_matches'   => 0,
                'has_membership_evidence'   => true,
            ];
        }

        // ── Player's stat rows for the remaining candidate matches ───────────
        $statsByMatchId = MatchPlayerStatistic::whereIn('match_id', $remainingMatchIds)
            ->where('player_id', $playerId)
            ->where('team_id', $teamId)
            ->get(['match_id', 'games_minutes', 'games_substitute'])
            ->keyBy(fn($row) => (int) $row->match_id);

        // ── Substitution-in evidence, only needed for NULL+substitute rows ───
        $externalId = PlayerExternalId::where('player_id', $playerId)
            ->where('data_source_id', $dsId)
            ->value('external_id');

        $subInMatchIds = [];
        if ($externalId !== null) {
            $subInMatchIds = MatchEvent::whereIn('match_id', $remainingMatchIds)
                ->where('event_type', 'substitution')
                ->where('related_player_external_id', $externalId)
                ->pluck('match_id')
                ->map(fn($id) => (int) $id)
                ->unique()
                ->flip()
                ->all();
        }

        $playedMinutes         = 0;
        $availableMatches      = 0;
        $invalidMinutesMatches = 0;

        foreach ($remainingMatchIds as $matchId) {
            $row = $statsByMatchId->get($matchId);

            if ($row === null) {
                // Not part of the matchday squad at all — 0 played, still available.
                $minutes = 0;
            } elseif ($row->games_minutes !== null) {
                $minutes = (int) $row->games_minutes;
            } elseif ((bool) $row->games_substitute === true) {
                if ($externalId !== null && !isset($subInMatchIds[$matchId])) {
                    // NULL + bench + no evidence of entry → unused sub.
                    $minutes = 0;
                } else {
                    // Evidence of entry, or no mapping to even check → genuinely missing.
                    $invalidMinutesMatches++;
                    continue;
                }
            } else {
                // games_substitute = 0 with NULL minutes: never observed in
                // Step 5E, handled defensively as genuinely missing.
                $invalidMinutesMatches++;
                continue;
            }

            $availableMatches++;
            $playedMinutes += $minutes;
        }

        $availableMinutes = $availableMatches * self::MINUTES_PER_MATCH;

        return [
            'played_minutes'           => $playedMinutes,
            'available_minutes'        => $availableMinutes,
            'available_minutes_share'  => $availableMatches > 0
                ? (float) ($playedMinutes / $availableMinutes)
                : null,
            'covered_matches'          => $coveredMatches,
            'available_matches'        => $availableMatches,
            'excluded_absence_matches' => $excludedAbsenceMatches,
            'invalid_minutes_matches'  => $invalidMinutesMatches,
            'has_membership_evidence'  => true,
        ];
    }

    /** @return array<int, string> */
    private static function FORCED_UNAVAILABLE_REASONS(): array
    {
        return array_merge(self::INJURY_REASONS, self::MEDICAL_REASONS, self::SUSPENSION_REASONS);
    }

    /**
     * Public accessor for the Step 5F/5G validated forced-unavailable reason
     * set (injury + medical + suspension, under absence_type='Missing
     * Fixture'). Single source of truth, reused by TeamAvailabilityCalculator
     * (Step 6B) so the classification is never duplicated.
     *
     * @return array<int, string>
     */
    public static function forcedUnavailableReasons(): array
    {
        return self::FORCED_UNAVAILABLE_REASONS();
    }

    /**
     * Earliest kickoff_at, strictly before $cutoff, across three independent
     * certain-evidence sources that this player belonged to this team. Returns
     * null when none of the three sources has any evidence (fail-closed: no
     * assumed membership from season start). See class docblock, "Membership
     * window (Step 5H)".
     */
    private static function firstMembershipKickoff(
        int $playerId,
        int $teamId,
        int $dsId,
        Carbon $cutoff,
    ): ?Carbon {
        $candidates = [];

        $fromStats = MatchPlayerStatistic::join('matches', 'matches.id', '=', 'match_player_statistics.match_id')
            ->where('match_player_statistics.player_id', $playerId)
            ->where('match_player_statistics.team_id', $teamId)
            ->where('matches.kickoff_at', '<', $cutoff)
            ->min('matches.kickoff_at');
        if ($fromStats !== null) {
            $candidates[] = Carbon::parse($fromStats);
        }

        $fromAbsences = PlayerAbsence::join('matches', 'matches.id', '=', 'player_absences.match_id')
            ->where('player_absences.player_id', $playerId)
            ->where('player_absences.team_id', $teamId)
            ->where('matches.kickoff_at', '<', $cutoff)
            ->min('matches.kickoff_at');
        if ($fromAbsences !== null) {
            $candidates[] = Carbon::parse($fromAbsences);
        }

        $externalId = PlayerExternalId::where('player_id', $playerId)
            ->where('data_source_id', $dsId)
            ->value('external_id');
        if ($externalId !== null) {
            $fromLineups = MatchLineupPlayer::join('match_lineups', 'match_lineups.id', '=', 'match_lineup_players.match_lineup_id')
                ->join('matches', 'matches.id', '=', 'match_lineups.match_id')
                ->where('match_lineup_players.player_external_id', $externalId)
                ->where('match_lineups.team_id', $teamId)
                ->where('matches.kickoff_at', '<', $cutoff)
                ->min('matches.kickoff_at');
            if ($fromLineups !== null) {
                $candidates[] = Carbon::parse($fromLineups);
            }
        }

        if (empty($candidates)) {
            return null;
        }

        return collect($candidates)->sortBy(fn($c) => $c->getTimestamp())->first();
    }

    /**
     * Step 5I — membership end. Earliest kickoff_at, strictly before $cutoff
     * AND strictly after $afterTs (the player's own START at $teamId), across
     * the same three evidence sources, for this player at ANY OTHER team.
     * Evidence tied exactly to $afterTs (same timestamp as START) is ignored
     * on purpose — simultaneous/ambiguous evidence must never flip membership
     * (fail-closed: keep the proven team, don't invent a cessation). Returns
     * null when no later other-team evidence exists — membership is treated
     * as still open at $teamId through cutoff.
     */
    private static function firstOtherTeamKickoffAfter(
        int $playerId,
        int $teamId,
        int $dsId,
        Carbon $cutoff,
        int $afterTs,
    ): ?Carbon {
        $after = Carbon::createFromTimestamp($afterTs);
        $candidates = [];

        $fromStats = MatchPlayerStatistic::join('matches', 'matches.id', '=', 'match_player_statistics.match_id')
            ->where('match_player_statistics.player_id', $playerId)
            ->where('match_player_statistics.team_id', '!=', $teamId)
            ->where('matches.kickoff_at', '<', $cutoff)
            ->where('matches.kickoff_at', '>', $after)
            ->min('matches.kickoff_at');
        if ($fromStats !== null) {
            $candidates[] = Carbon::parse($fromStats);
        }

        $fromAbsences = PlayerAbsence::join('matches', 'matches.id', '=', 'player_absences.match_id')
            ->where('player_absences.player_id', $playerId)
            ->where('player_absences.team_id', '!=', $teamId)
            ->where('matches.kickoff_at', '<', $cutoff)
            ->where('matches.kickoff_at', '>', $after)
            ->min('matches.kickoff_at');
        if ($fromAbsences !== null) {
            $candidates[] = Carbon::parse($fromAbsences);
        }

        $externalId = PlayerExternalId::where('player_id', $playerId)
            ->where('data_source_id', $dsId)
            ->value('external_id');
        if ($externalId !== null) {
            $fromLineups = MatchLineupPlayer::join('match_lineups', 'match_lineups.id', '=', 'match_lineup_players.match_lineup_id')
                ->join('matches', 'matches.id', '=', 'match_lineups.match_id')
                ->where('match_lineup_players.player_external_id', $externalId)
                ->where('match_lineups.team_id', '!=', $teamId)
                ->where('matches.kickoff_at', '<', $cutoff)
                ->where('matches.kickoff_at', '>', $after)
                ->min('matches.kickoff_at');
            if ($fromLineups !== null) {
                $candidates[] = Carbon::parse($fromLineups);
            }
        }

        if (empty($candidates)) {
            return null;
        }

        return collect($candidates)->sortBy(fn($c) => $c->getTimestamp())->first();
    }

    private static function emptyResult(bool $hasMembershipEvidence): array
    {
        return [
            'played_minutes'           => 0,
            'available_minutes'        => 0,
            'available_minutes_share'  => null,
            'covered_matches'          => 0,
            'available_matches'        => 0,
            'excluded_absence_matches' => 0,
            'invalid_minutes_matches'  => 0,
            'has_membership_evidence'  => $hasMembershipEvidence,
        ];
    }
}
