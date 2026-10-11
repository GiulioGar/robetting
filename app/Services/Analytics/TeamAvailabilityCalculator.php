<?php

namespace App\Services\Analytics;

use App\Models\FootballMatch;
use App\Models\PlayerAbsence;

/**
 * Availability V1 — Step 6B/6C. First Team Availability aggregate: combines
 * the two already-validated per-player ingredients —
 * PlayerAvailableMinutesShareCalculator (U, utilisation) and
 * PlayerMarketValueShareCalculator (V, relative squad weight) — into a single
 * `weighted_absence` figure for a target match + team. Standalone, read-only,
 * fail-closed. NOT wired to the Prediction Engine.
 *
 * ── Why not TeamAbsenceImpactCalculator ───────────────────────────────────
 *
 * Inspected first, per instructions, before writing this class (Step 6B). It
 * measures a different thing entirely: a 30-day/last-5-match "how much were
 * the absent players actually used recently" impact score, built directly
 * from match_player_statistics with no concept of forced-unavailable reason
 * classification, no U/V shares, no market value, and no per-target-match
 * `injuries_fetched_at` coverage gate. None of its output fields, inputs, or
 * internal queries overlap with weighted_absence = Σ(U × V). Extending it
 * would mean rewriting nearly the whole class, so a new calculator was
 * written instead rather than forcing an unrelated shape onto it.
 *
 * ── Formula ────────────────────────────────────────────────────────────────
 *
 * For each player forced-unavailable at the target match (player_absences,
 * absence_type='Missing Fixture', reason in
 * PlayerAvailableMinutesShareCalculator::forcedUnavailableReasons() — the
 * same Step 5F/5G injury+medical+suspension set; `Questionable` and any
 * EXCLUDE/unlisted reason never enter this sum):
 *
 *   U = PlayerAvailableMinutesShareCalculator::calculate(player, team, kickoff)['available_minutes_share']
 *   V = PlayerMarketValueShareCalculator::calculate(player, team, kickoff)['market_value_share_top25']
 *   player_absence_weight = U * V   (only when BOTH are non-null)
 *
 * No other weight is introduced (no role, no rank, no log market value, no
 * manual multiplier, no home/away split).
 *
 * ── Two distinct totals (Step 6C) ───────────────────────────────────────────
 *
 * `known_weighted_absence` = Σ player_absence_weight over only the
 * forced-absentees whose weight could actually be computed. This number
 * exists purely as a transparency aid — it is NEVER itself a usable
 * Availability measure, because it silently treats every unweighable
 * absentee as if their impact were 0, which is false information, not a
 * known fact.
 *
 * `weighted_absence` is the one safe to use as a complete measure:
 *   - injuries NOT covered for the target match → null
 *   - covered, zero forced-absentees → 0.0 (a real, certain zero)
 *   - covered, forced-absentees > 0, AND at least one is unweighable → null
 *     (the true total is genuinely unknown — never silently assume 0 for
 *     the missing player; `known_weighted_absence` is still reported so the
 *     gap is visible, but it must never be read as "the" availability loss)
 *   - covered, forced-absentees > 0, ALL weighable → the full, exact sum
 *     (identical to known_weighted_absence in this case)
 *
 * ── Incompleteness-cause diagnostics (Step 6C) ──────────────────────────────
 *
 * Each absentee detail row carries `u_null_cause` / `v_null_cause` (null
 * when that ingredient resolved). Derived purely by reading the diagnostic
 * fields PlayerAvailableMinutesShareCalculator / PlayerMarketValueShareCalculator
 * already compute (`has_membership_evidence`, `covered_matches`,
 * `invalid_minutes_matches`, `market_value_null_cause`, `top25_null_cause`) —
 * no logic is duplicated, this class only reads and labels:
 *
 *   U_NULL_NO_MEMBERSHIP_EVIDENCE — no evidence the player ever belonged to
 *     this team before cutoff (PlayerAvailableMinutesShareCalculator
 *     has_membership_evidence = false).
 *   U_NULL_NO_COVERED_HISTORY — membership proven, but no injury-covered
 *     historical match remained usable (covered_matches = 0, or every
 *     covered match was absence-excluded with nothing invalid either).
 *   U_NULL_INVALID_MINUTES — at least one covered match had genuinely
 *     missing minutes data (SUB_USED_NULL or unmapped) and no usable match
 *     was left.
 *   V_NULL_NO_TM_MAPPING / V_NULL_NOT_IN_ARTIFACT / V_NULL_ARTIFACT_AFTER_CUTOFF
 *     — taken directly from PlayerMarketValueShareCalculator's own
 *     market_value_null_cause / top25_null_cause (player-side cause takes
 *     priority when both are set).
 */
class TeamAvailabilityCalculator
{
    /**
     * @return array{
     *     weighted_absence: float|null,
     *     known_weighted_absence: float|null,
     *     injuries_covered: bool,
     *     forced_absent_count: int|null,
     *     weighted_player_count: int|null,
     *     unweighted_player_count: int|null,
     *     is_complete: bool|null,
     *     absentees: array<int, array{
     *         player_id: int,
     *         available_minutes_share: float|null,
     *         market_value_share_top25: float|null,
     *         player_absence_weight: float|null,
     *         reason: string|null,
     *         u_null_cause: string|null,
     *         v_null_cause: string|null,
     *     }>,
     * }
     */
    public static function calculateForMatch(FootballMatch $targetMatch, int $teamId): array
    {
        if ($targetMatch->injuries_fetched_at === null || $targetMatch->kickoff_at === null) {
            return self::notCoveredResult();
        }

        $cutoff = $targetMatch->kickoff_at;

        $absences = PlayerAbsence::where('match_id', $targetMatch->id)
            ->where('team_id', $teamId)
            ->where('absence_type', 'Missing Fixture')
            ->whereIn('reason', PlayerAvailableMinutesShareCalculator::forcedUnavailableReasons())
            ->get(['player_id', 'reason']);

        $forcedAbsentCount = $absences->count();

        if ($forcedAbsentCount === 0) {
            return [
                'weighted_absence'         => 0.0,
                'known_weighted_absence'   => 0.0,
                'injuries_covered'         => true,
                'forced_absent_count'      => 0,
                'weighted_player_count'    => 0,
                'unweighted_player_count'  => 0,
                'is_complete'              => true,
                'absentees'                => [],
            ];
        }

        $knownSum        = 0.0;
        $weightedCount   = 0;
        $unweightedCount = 0;
        $detail          = [];

        foreach ($absences as $absence) {
            $playerId = (int) $absence->player_id;

            $u = PlayerAvailableMinutesShareCalculator::calculate($playerId, $teamId, $cutoff);
            $v = PlayerMarketValueShareCalculator::calculate($playerId, $teamId, $cutoff);

            $uShare = $u['available_minutes_share'];
            $vShare = $v['market_value_share_top25'];

            $weight = null;
            if ($uShare !== null && $vShare !== null) {
                $weight = $uShare * $vShare;
                $knownSum += $weight;
                $weightedCount++;
            } else {
                $unweightedCount++;
            }

            $detail[] = [
                'player_id'                 => $playerId,
                'available_minutes_share'   => $uShare,
                'market_value_share_top25'  => $vShare,
                'player_absence_weight'     => $weight,
                'reason'                    => $absence->reason,
                'u_null_cause'              => $uShare === null ? self::diagnoseUCause($u) : null,
                'v_null_cause'              => $vShare === null ? self::diagnoseVCause($v) : null,
            ];
        }

        $isComplete = $unweightedCount === 0;

        return [
            'weighted_absence'        => $isComplete ? $knownSum : null,
            'known_weighted_absence'  => $knownSum,
            'injuries_covered'        => true,
            'forced_absent_count'     => $forcedAbsentCount,
            'weighted_player_count'   => $weightedCount,
            'unweighted_player_count' => $unweightedCount,
            'is_complete'             => $isComplete,
            'absentees'               => $detail,
        ];
    }

    /** @param array<string, mixed> $u PlayerAvailableMinutesShareCalculator::calculate() output */
    private static function diagnoseUCause(array $u): string
    {
        if (!$u['has_membership_evidence']) {
            return 'U_NULL_NO_MEMBERSHIP_EVIDENCE';
        }
        if ($u['covered_matches'] === 0) {
            return 'U_NULL_NO_COVERED_HISTORY';
        }
        if ($u['invalid_minutes_matches'] > 0) {
            return 'U_NULL_INVALID_MINUTES';
        }
        // available_matches = 0 with covered_matches > 0 and no invalid rows:
        // every covered match was forced-absence-excluded, none usable.
        return 'U_NULL_NO_COVERED_HISTORY';
    }

    /** @param array<string, mixed> $v PlayerMarketValueShareCalculator::calculate() output */
    private static function diagnoseVCause(array $v): string
    {
        return $v['market_value_null_cause'] ?? $v['top25_null_cause'] ?? 'V_NULL_UNKNOWN';
    }

    private static function notCoveredResult(): array
    {
        return [
            'weighted_absence'         => null,
            'known_weighted_absence'   => null,
            'injuries_covered'         => false,
            'forced_absent_count'      => null,
            'weighted_player_count'    => null,
            'unweighted_player_count'  => null,
            'is_complete'              => null,
            'absentees'                => [],
        ];
    }
}
