<?php

namespace App\Services\Analytics;

use App\Models\FootballMatch;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Dynamic Elo rating system for football teams.
 *
 * Ratings are computed by replaying every definitive match result in strict
 * chronological order (kickoff_at ASC, id ASC as a stable tie-breaker) up to
 * a given cutoff.  No DB writes are performed — everything runs in memory.
 *
 * ── Constants (tunable) ───────────────────────────────────────────────────────
 *
 *   INITIAL_ELO    = 1500   Starting rating for every team, regardless of tier
 *                           or division.  Promoted / newly-created teams enter
 *                           at this baseline; no promotion/relegation adjustment
 *                           is applied yet — intentional for the first baseline.
 *
 *   K_FACTOR       = 20     Maximum points exchanged per match.  A higher K
 *                           makes ratings react faster but oscillate more.
 *
 *   HOME_ADVANTAGE = 60     Effective rating bonus for the home team used
 *                           ONLY inside the expected-score formula.  It shifts
 *                           the home win probability upward without permanently
 *                           inflating the stored rating.  See §Home advantage.
 *
 * ── Home advantage ───────────────────────────────────────────────────────────
 *
 * The home team is treated as if their rating were (home_elo + HOME_ADVANTAGE)
 * for the purpose of computing the expected outcome:
 *
 *   expected_home = 1 / (1 + 10 ^ ((away_elo − home_elo − HOME_ADVANTAGE) / 400))
 *   expected_away = 1 − expected_home
 *
 * After the match the true (stored) ratings are updated:
 *   new_home_elo = home_elo + K * (actual_home − expected_home)
 *   new_away_elo = away_elo + K * (actual_away − expected_away)
 *
 * The stored rating of the home team is NEVER permanently incremented by
 * HOME_ADVANTAGE; the advantage appears only in the probability estimate.
 *
 * ── Actual score ─────────────────────────────────────────────────────────────
 *
 * Full-time regulation result only (home_score_ft / away_score_ft).  Extra
 * time, penalties, and goal-difference multipliers are deliberately omitted
 * to keep this a clean baseline.  All three map to the standard {1, 0.5, 0}
 * scoring convention.
 *
 * ── Scope ────────────────────────────────────────────────────────────────────
 *
 * All competitions in the DB are weighted equally.  Per-competition K scaling
 * (e.g. lower K for cups) can be introduced later by replacing K_FACTOR with a
 * competition-keyed map keyed on competition_id.
 *
 * ── Performance ──────────────────────────────────────────────────────────────
 *
 * One DB query loads all qualifying matches (6 columns).  Processing is O(N)
 * in match count and O(T) in team count, entirely in-memory.  No per-team
 * sub-queries, no N+1.
 *
 * ── Zero-sum guarantee ───────────────────────────────────────────────────────
 *
 * Because actual_home + actual_away = 1 and expected_home + expected_away = 1,
 * delta_home + delta_away = K * ((actuals sum) − (expecteds sum)) = 0.
 * The total Elo in the system is therefore conserved.
 */
class TeamEloCalculator
{
    public const INITIAL_ELO    = 1500.0;
    public const K_FACTOR       = 20.0;
    public const HOME_ADVANTAGE = 60.0;

    private const DEFINITIVE_STATUSES = ['finished', 'awarded', 'walkover'];

    /**
     * Calculate current Elo ratings for every team that has appeared in at
     * least one definitive match strictly before $cutoff.
     *
     * Teams with no prior history are implicitly at INITIAL_ELO (use
     * `$ratings[$teamId] ?? self::INITIAL_ELO` when reading).
     *
     * @return array<int, float>  [team_id => elo_rating]
     */
    public static function calculateRatingsBefore(Carbon $cutoff): array
    {
        $cutoffTs = $cutoff->getTimestamp();

        $matches = FootballMatch::whereIn('status', self::DEFINITIVE_STATUSES)
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where('kickoff_at', '<', $cutoff)
            ->orderBy('kickoff_at')
            ->orderBy('id')
            ->get(['id', 'kickoff_at', 'home_team_id', 'away_team_id', 'home_score_ft', 'away_score_ft']);

        $ratings = [];

        foreach ($matches as $m) {
            // Internal anti-leakage guard (second line of defence, timestamp-safe).
            if ($m->kickoff_at->getTimestamp() >= $cutoffTs) {
                continue;
            }

            $homeId = (int) $m->home_team_id;
            $awayId = (int) $m->away_team_id;

            $homeElo = $ratings[$homeId] ?? self::INITIAL_ELO;
            $awayElo = $ratings[$awayId] ?? self::INITIAL_ELO;

            // Expected scores — HOME_ADVANTAGE applied here only.
            $expectedHome = 1.0 / (1.0 + 10.0 ** (($awayElo - $homeElo - self::HOME_ADVANTAGE) / 400.0));
            $expectedAway = 1.0 - $expectedHome;

            // Actual scores (FT regulation only).
            $homeGoals = (int) $m->home_score_ft;
            $awayGoals = (int) $m->away_score_ft;

            if ($homeGoals > $awayGoals) {
                $actualHome = 1.0;
                $actualAway = 0.0;
            } elseif ($homeGoals < $awayGoals) {
                $actualHome = 0.0;
                $actualAway = 1.0;
            } else {
                $actualHome = 0.5;
                $actualAway = 0.5;
            }

            // Update stored ratings (delta_home + delta_away = 0 by construction).
            $ratings[$homeId] = $homeElo + self::K_FACTOR * ($actualHome - $expectedHome);
            $ratings[$awayId] = $awayElo + self::K_FACTOR * ($actualAway - $expectedAway);
        }

        return $ratings;
    }

    /**
     * Elo ratings for both teams of $match, computed from all definitive results
     * strictly before $match->kickoff_at.  The match itself is never included.
     *
     * Returns INITIAL_ELO for any team with no prior history.
     *
     * @return array{home_elo: float, away_elo: float, elo_difference: float}
     */
    public static function calculateForMatch(FootballMatch $match): array
    {
        if ($match->kickoff_at === null) {
            return [
                'home_elo'       => self::INITIAL_ELO,
                'away_elo'       => self::INITIAL_ELO,
                'elo_difference' => 0.0,
            ];
        }

        $ratings = self::calculateRatingsBefore($match->kickoff_at);

        $homeElo = $ratings[$match->home_team_id] ?? self::INITIAL_ELO;
        $awayElo = $ratings[$match->away_team_id] ?? self::INITIAL_ELO;

        return [
            'home_elo'       => $homeElo,
            'away_elo'       => $awayElo,
            'elo_difference' => $homeElo - $awayElo,
        ];
    }

    /**
     * Batch variant: compute Elo ratings of both participants immediately before
     * each match in $matches, using a SINGLE chronological replay instead of one
     * full replay per match.
     *
     * For every match m in $matches, the returned ratings are identical to what
     * calculateRatingsBefore(m->kickoff_at) would give — the invariant is
     * enforced by the capture-before-apply order in the loop below.
     *
     * ── Complexity ────────────────────────────────────────────────────────────
     *
     *   DB queries : 1  (loads all definitive matches ≤ latest target kickoff)
     *   Elo replay : 1 pass, O(H) where H = historical match count
     *   Memory     : O(T) ratings map + O(K) snapshot map (K = distinct cutoffs)
     *
     * @param  Collection  $matches  FootballMatch instances with kickoff_at,
     *                               home_team_id, away_team_id populated.
     * @return array<int, array{home_elo: float, away_elo: float}>  keyed by match id
     */
    public static function calculateRatingsBeforeMatches(Collection $matches): array
    {
        if ($matches->isEmpty()) {
            return [];
        }

        // Collect distinct target cutoffs as timestamps (ascending).
        $targetTs = $matches
            ->map(fn ($m) => $m->kickoff_at?->getTimestamp())
            ->filter(fn ($ts) => $ts !== null)
            ->unique()
            ->sort()
            ->values()
            ->all();

        // All matches have null kickoff → fall back to INITIAL_ELO everywhere.
        if (empty($targetTs)) {
            $result = [];
            foreach ($matches as $m) {
                $result[$m->id] = ['home_elo' => self::INITIAL_ELO, 'away_elo' => self::INITIAL_ELO];
            }
            return $result;
        }

        $maxTs      = end($targetTs);
        $maxKickoff = Carbon::createFromTimestamp($maxTs, 'UTC');

        // One query: all definitive matches up to and including the latest
        // target kickoff.  Matches at the exact cutoff are included so the
        // loop can process them — but we CAPTURE state before applying them,
        // which enforces the same strict-< semantics as calculateRatingsBefore.
        $history = FootballMatch::whereIn('status', self::DEFINITIVE_STATUSES)
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where('kickoff_at', '<=', $maxKickoff)
            ->orderBy('kickoff_at')
            ->orderBy('id')
            ->get(['id', 'kickoff_at', 'home_team_id', 'away_team_id', 'home_score_ft', 'away_score_ft']);

        $ratings     = [];
        $snapshots   = []; // [timestamp => [team_id => elo, ...]]
        $capturedIdx = 0;  // pointer into $targetTs (ascending)

        foreach ($history as $hm) {
            $hmTs = $hm->kickoff_at->getTimestamp();

            // Capture the current rating state for every target cutoff whose
            // timestamp ≤ this match's kickoff.  Because we capture BEFORE
            // applying $hm, this is correct pre-kickoff state for any target
            // match with kickoff_at == $hm->kickoff_at.
            while ($capturedIdx < count($targetTs) && $targetTs[$capturedIdx] <= $hmTs) {
                $snapshots[$targetTs[$capturedIdx]] = $ratings; // PHP array copy
                $capturedIdx++;
            }

            // Apply Elo update — same formula as calculateRatingsBefore.
            $homeId  = (int) $hm->home_team_id;
            $awayId  = (int) $hm->away_team_id;
            $homeElo = $ratings[$homeId] ?? self::INITIAL_ELO;
            $awayElo = $ratings[$awayId] ?? self::INITIAL_ELO;

            $expectedHome = 1.0 / (1.0 + 10.0 ** (($awayElo - $homeElo - self::HOME_ADVANTAGE) / 400.0));
            $expectedAway = 1.0 - $expectedHome;

            $hg = (int) $hm->home_score_ft;
            $ag = (int) $hm->away_score_ft;

            if ($hg > $ag) {
                [$actualHome, $actualAway] = [1.0, 0.0];
            } elseif ($hg < $ag) {
                [$actualHome, $actualAway] = [0.0, 1.0];
            } else {
                [$actualHome, $actualAway] = [0.5, 0.5];
            }

            $ratings[$homeId] = $homeElo + self::K_FACTOR * ($actualHome - $expectedHome);
            $ratings[$awayId] = $awayElo + self::K_FACTOR * ($actualAway - $expectedAway);
        }

        // Capture any remaining cutoffs that lie beyond the last historical match.
        while ($capturedIdx < count($targetTs)) {
            $snapshots[$targetTs[$capturedIdx]] = $ratings;
            $capturedIdx++;
        }

        // Map results back to match ids.
        $result = [];
        foreach ($matches as $m) {
            if ($m->kickoff_at === null) {
                $result[$m->id] = ['home_elo' => self::INITIAL_ELO, 'away_elo' => self::INITIAL_ELO];
                continue;
            }
            $ts    = $m->kickoff_at->getTimestamp();
            $state = $snapshots[$ts] ?? [];
            $result[$m->id] = [
                'home_elo' => $state[(int) $m->home_team_id] ?? self::INITIAL_ELO,
                'away_elo' => $state[(int) $m->away_team_id] ?? self::INITIAL_ELO,
            ];
        }

        return $result;
    }
}
