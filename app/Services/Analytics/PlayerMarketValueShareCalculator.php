<?php

namespace App\Services\Analytics;

use App\Models\DataSource;
use App\Models\PlayerExternalId;
use Carbon\Carbon;

/**
 * Availability V1 — Step 6A. Second availability ingredient: how large a
 * player's Transfermarkt market value is relative to his own team's
 * Structural TOP25 market value. Standalone, read-only, fail-closed.
 * Not wired to the Prediction Engine, no weighted absence, no role/rank.
 *
 * ── Resolution chain (no name matching anywhere) ─────────────────────────
 *
 *   players.id --[player_external_ids, data_source=transfermarkt]--> tm_player_id
 *   tm_player_id --[structural_players_current.json, teams[teamId].players]--> market_value
 *   teamId --[structural_strength_current.json, teams[teamId]]--> top25_market_value
 *
 * Both artifacts key their `teams` map by the canonical Robetting team_id
 * (verified against the DB when these artifacts were generated — see P16D/E),
 * never by Transfermarkt's own club id or by name.
 *
 * ── Fail-closed ──────────────────────────────────────────────────────────
 *
 * Any missing link (no Transfermarkt mapping, tm_player_id absent from the
 * player artifact's team roster, team absent from either artifact, a
 * non-positive market_value or top25_market_value) returns null for the
 * affected field(s) — never 0, never a silent fallback, never a name-based
 * lookup.
 *
 * ── Anti-leakage cutoff (Step 6B) ─────────────────────────────────────────
 *
 * Both artifacts are CURRENT snapshots (one `generated_at` per file) — they
 * have no historical versions. `calculate()` therefore takes a cutoff
 * (normally the target match's kickoff) and checks each artifact's own
 * `generated_at` against it independently: when an artifact was generated
 * strictly AFTER cutoff, it reflects information that could not have been
 * known at cutoff time, so IT alone is treated as unusable (its derived
 * value becomes null) — the two artifacts are never both discarded just
 * because one of them is too recent. No historical value is ever
 * reconstructed; the result is simply null, fail-closed.
 */
class PlayerMarketValueShareCalculator
{
    private const PLAYERS_ARTIFACT   = 'structural_players_current.json';
    private const STRENGTH_ARTIFACT  = 'structural_strength_current.json';

    private static ?string $artifactDir = null;
    private static array   $artifactCache = [];

    /** Override artifact directory (for unit tests without Laravel container). */
    public static function setArtifactDir(string $dir): void
    {
        self::$artifactDir   = rtrim($dir, '/\\');
        self::$artifactCache = [];
    }

    /** Reset overrides (call in tearDownAfterClass). */
    public static function reset(): void
    {
        self::$artifactDir   = null;
        self::$artifactCache = [];
    }

    /**
     * @return array{
     *     market_value: int|null,
     *     team_top25_market_value: int|null,
     *     market_value_share_top25: float|null,
     *     market_value_null_cause: string|null,
     *     top25_null_cause: string|null,
     * }
     */
    public static function calculate(int $playerId, int $teamId, Carbon $cutoff): array
    {
        $tmPlayerId = self::resolveTransfermarktPlayerId($playerId);

        $marketValue = $tmPlayerId !== null
            ? self::lookupPlayerMarketValue($teamId, $tmPlayerId, $cutoff)
            : null;

        $top25 = self::lookupTeamTop25($teamId, $cutoff);

        $share = null;
        if ($marketValue !== null && $marketValue > 0 && $top25 !== null && $top25 > 0) {
            $share = (float) ($marketValue / $top25);
        }

        return [
            'market_value'             => $marketValue,
            'team_top25_market_value'  => $top25,
            'market_value_share_top25' => $share,
            'market_value_null_cause'  => $marketValue === null
                ? self::diagnoseMarketValueNullCause($tmPlayerId, $cutoff)
                : null,
            'top25_null_cause'         => $top25 === null
                ? self::diagnoseTop25NullCause($cutoff)
                : null,
        ];
    }

    /**
     * Step 6C — primary cause of a null market_value, for diagnostic use
     * (TeamAvailabilityCalculator's incompleteness-cause analysis). Never
     * changes the fail-closed value itself.
     */
    private static function diagnoseMarketValueNullCause(?int $tmPlayerId, Carbon $cutoff): string
    {
        if ($tmPlayerId === null) {
            return 'V_NULL_NO_TM_MAPPING';
        }
        if (self::artifactTooRecent(self::loadArtifact(self::PLAYERS_ARTIFACT), $cutoff)) {
            return 'V_NULL_ARTIFACT_AFTER_CUTOFF';
        }
        return 'V_NULL_NOT_IN_ARTIFACT';
    }

    /** Step 6C — primary cause of a null team_top25_market_value. */
    private static function diagnoseTop25NullCause(Carbon $cutoff): string
    {
        if (self::artifactTooRecent(self::loadArtifact(self::STRENGTH_ARTIFACT), $cutoff)) {
            return 'V_NULL_ARTIFACT_AFTER_CUTOFF';
        }
        return 'V_NULL_NOT_IN_ARTIFACT';
    }

    /** players.id → tm_player_id via player_external_ids (Transfermarkt). Null if unmapped. */
    private static function resolveTransfermarktPlayerId(int $playerId): ?int
    {
        $tmDsId = DataSource::where('slug', 'transfermarkt')->value('id');
        if ($tmDsId === null) {
            return null;
        }

        $externalId = PlayerExternalId::where('player_id', $playerId)
            ->where('data_source_id', $tmDsId)
            ->value('external_id');

        return $externalId !== null ? (int) $externalId : null;
    }

    /** market_value for tm_player_id within $teamId's roster in the player artifact. Null if any link missing, <= 0, or the artifact is too recent for $cutoff. */
    private static function lookupPlayerMarketValue(int $teamId, int $tmPlayerId, Carbon $cutoff): ?int
    {
        $artifact = self::loadArtifact(self::PLAYERS_ARTIFACT);
        if (self::artifactTooRecent($artifact, $cutoff)) {
            return null;
        }
        $team = $artifact['teams'][(string) $teamId] ?? null;
        if (!is_array($team) || !isset($team['players']) || !is_array($team['players'])) {
            return null;
        }

        foreach ($team['players'] as $player) {
            if (!is_array($player) || !isset($player['tm_player_id'])) {
                continue;
            }
            if ((int) $player['tm_player_id'] === $tmPlayerId) {
                $value = $player['market_value'] ?? null;
                return (is_int($value) || is_float($value)) && $value > 0 ? (int) $value : null;
            }
        }

        return null;
    }

    /** top25_market_value for $teamId in the strength artifact. Null if missing, <= 0, or the artifact is too recent for $cutoff. */
    private static function lookupTeamTop25(int $teamId, Carbon $cutoff): ?int
    {
        $artifact = self::loadArtifact(self::STRENGTH_ARTIFACT);
        if (self::artifactTooRecent($artifact, $cutoff)) {
            return null;
        }
        $team = $artifact['teams'][(string) $teamId] ?? null;
        if (!is_array($team) || !isset($team['top25_market_value'])) {
            return null;
        }

        $value = $team['top25_market_value'];
        return (is_int($value) || is_float($value)) && $value > 0 ? (int) $value : null;
    }

    /**
     * True when the artifact's own `generated_at` is strictly after $cutoff
     * (the snapshot did not exist yet at cutoff time — using it would leak
     * future information), or when `generated_at` is missing/unparseable
     * (fail-closed: an artifact we cannot date is never trusted).
     */
    private static function artifactTooRecent(array $artifact, Carbon $cutoff): bool
    {
        if (!isset($artifact['generated_at']) || !is_string($artifact['generated_at'])) {
            return true;
        }

        try {
            $generatedAt = Carbon::parse($artifact['generated_at']);
        } catch (\Throwable) {
            return true;
        }

        return $generatedAt->greaterThan($cutoff);
    }

    /** @return array<string, mixed> Empty array on any read/decode failure (fail-closed, never throws). */
    private static function loadArtifact(string $filename): array
    {
        if (isset(self::$artifactCache[$filename])) {
            return self::$artifactCache[$filename];
        }

        $dir  = self::$artifactDir ?? base_path('tools/models');
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($path)) {
            return self::$artifactCache[$filename] = [];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return self::$artifactCache[$filename] = [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return self::$artifactCache[$filename] = [];
        }

        return self::$artifactCache[$filename] = $decoded;
    }
}
