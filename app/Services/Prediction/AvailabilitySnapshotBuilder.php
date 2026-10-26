<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use App\Services\Analytics\TeamAvailabilityCalculator;
use Carbon\CarbonInterface;

/**
 * Builds the Availability snapshot frozen together with a NEW prediction
 * (predictions.availability_snapshot_json). Pure read: reuses
 * TeamAvailabilityCalculator verbatim (fail-closed on non-pre-match
 * snapshots), never feeds the model, never touches features_json.
 *
 * The snapshot is built once at capture time and stored; nothing updates it
 * afterwards. A team without a certified pre-match injuries snapshot is
 * stored explicitly as injuries_covered=false with null values — nothing is
 * invented.
 *
 * Shape (schema_version 1):
 *   {
 *     schema_version, captured_at, match_id,
 *     home: <team>, away: <team>
 *   }
 *   <team> = { team_id, injuries_covered, injuries_fetched_at,
 *              forced_absent_count, weighted_absence, known_weighted_absence,
 *              weighted_player_count, unweighted_player_count, is_complete,
 *              absentees: [ { player_id, reason, available_minutes_share,
 *                             market_value_share_top25, player_absence_weight,
 *                             u_null_cause, v_null_cause } ] }
 * Player names are not stored: player_id is stable and resolves via `players`.
 */
class AvailabilitySnapshotBuilder
{
    public const SCHEMA_VERSION = 1;

    /** @return array<string, mixed> */
    public static function build(FootballMatch $match, CarbonInterface $capturedAt): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'captured_at'    => $capturedAt->toIso8601String(),
            'match_id'       => (int) $match->id,
            'home'           => self::team($match, (int) $match->home_team_id),
            'away'           => self::team($match, (int) $match->away_team_id),
        ];
    }

    /**
     * Never throws: a failure computing Availability must not block saving the
     * prediction; the snapshot is then simply NULL (unknown), never fabricated.
     *
     * @return array<string, mixed>|null
     */
    public static function buildOrNull(FootballMatch $match, CarbonInterface $capturedAt): ?array
    {
        try {
            return self::build($match, $capturedAt);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private static function team(FootballMatch $match, int $teamId): array
    {
        $r = TeamAvailabilityCalculator::calculateForMatch($match, $teamId);

        return [
            'team_id'                 => $teamId,
            'injuries_covered'        => (bool) $r['injuries_covered'],
            'injuries_fetched_at'     => $match->injuries_fetched_at?->toIso8601String(),
            'forced_absent_count'     => $r['forced_absent_count'],
            'weighted_absence'        => $r['weighted_absence'],
            'known_weighted_absence'  => $r['known_weighted_absence'],
            'weighted_player_count'   => $r['weighted_player_count'],
            'unweighted_player_count' => $r['unweighted_player_count'],
            'is_complete'             => $r['is_complete'],
            'absentees'               => array_map(static fn (array $a) => [
                'player_id'                => $a['player_id'],
                'reason'                   => $a['reason'],
                'available_minutes_share'  => $a['available_minutes_share'],
                'market_value_share_top25' => $a['market_value_share_top25'],
                'player_absence_weight'    => $a['player_absence_weight'],
                'u_null_cause'             => $a['u_null_cause'],
                'v_null_cause'             => $a['v_null_cause'],
            ], $r['absentees']),
        ];
    }
}
