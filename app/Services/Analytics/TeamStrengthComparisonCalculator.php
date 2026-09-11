<?php

namespace App\Services\Analytics;

use App\Models\DataSource;
use App\Models\FootballMatch;
use Carbon\Carbon;

/**
 * Compares structural and dynamic strength for both teams of a future match.
 *
 * The two strength signals are intentionally kept separate — this calculator
 * surfaces them for inspection, NOT combined into a single score or probability.
 *
 * ── Structural (TeamStructuralRatingCalculator) ───────────────────────────────
 * Most recent transfermarkt snapshot with snapshot_date <= kickoff_at.
 * No snapshot before that date → structural fields are null. No invented fallback.
 *
 * ── Dynamic Elo (TeamEloCalculator) ──────────────────────────────────────────
 * All definitive results with kickoff_at STRICTLY LESS THAN the match kickoff.
 * The match itself is always excluded. Future matches are always excluded.
 * Teams with no prior history return INITIAL_ELO (= 1500).
 *
 * ── Output keys ──────────────────────────────────────────────────────────────
 *
 *   home_structural / away_structural:
 *     structural_rating        float|null
 *     market_value             int|null
 *     structural_snapshot_date Carbon|null
 *
 *   home_elo / away_elo:        float (always)
 *   structural_rating_diff:     float|null   home − away; null if either missing
 *   elo_diff:                   float        home − away
 *   log_market_value_ratio:     float|null   ln(home_mv / away_mv); null if either missing
 *   elo_diff_scaled:            float        elo_diff / 400  (raw logistic input)
 *   structural_favorite:        HOME|AWAY|EVEN|UNKNOWN
 *   elo_favorite:               HOME|AWAY|EVEN
 *   signals_agree:              true|false|null  (null when structural unavailable or EVEN)
 */
class TeamStrengthComparisonCalculator
{
    /**
     * Compute structural + dynamic strength comparison for a single match.
     *
     * @return array{
     *   home_structural: array{structural_rating: float|null, market_value: int|null, structural_snapshot_date: \Carbon\Carbon|null},
     *   away_structural: array{structural_rating: float|null, market_value: int|null, structural_snapshot_date: \Carbon\Carbon|null},
     *   home_elo: float,
     *   away_elo: float,
     *   structural_rating_diff: float|null,
     *   elo_diff: float,
     *   log_market_value_ratio: float|null,
     *   elo_diff_scaled: float,
     *   structural_favorite: string,
     *   elo_favorite: string,
     *   signals_agree: bool|null,
     * }
     */
    public static function calculateForMatch(FootballMatch $match): array
    {
        $referenceDate = $match->kickoff_at instanceof Carbon
            ? $match->kickoff_at
            : ($match->kickoff_at !== null ? Carbon::instance($match->kickoff_at) : Carbon::now('UTC'));

        $homeId = (int) $match->home_team_id;
        $awayId = (int) $match->away_team_id;

        // ── Structural ────────────────────────────────────────────────────────

        $dataSourceId = DataSource::where('slug', 'transfermarkt')->value('id');

        $homeStructuralData = null;
        $awayStructuralData = null;

        if ($dataSourceId !== null) {
            $homeStructuralData = TeamStructuralRatingCalculator::calculateForTeamAtDate(
                $homeId,
                $referenceDate,
                (int) $dataSourceId
            );
            $awayStructuralData = TeamStructuralRatingCalculator::calculateForTeamAtDate(
                $awayId,
                $referenceDate,
                (int) $dataSourceId
            );
        }

        $homeRating = $homeStructuralData['structural_rating'] ?? null;
        $awayRating = $awayStructuralData['structural_rating'] ?? null;
        $homeMv     = $homeStructuralData['market_value'] ?? null;
        $awayMv     = $awayStructuralData['market_value'] ?? null;

        // ── Dynamic Elo ───────────────────────────────────────────────────────

        $eloData = TeamEloCalculator::calculateForMatch($match);
        $homeElo = $eloData['home_elo'];
        $awayElo = $eloData['away_elo'];

        // ── Diffs & ratios ────────────────────────────────────────────────────

        $structuralRatingDiff = ($homeRating !== null && $awayRating !== null)
            ? $homeRating - $awayRating
            : null;

        $eloDiff = $homeElo - $awayElo;

        $logMarketValueRatio = ($homeMv !== null && $awayMv !== null && $homeMv > 0 && $awayMv > 0)
            ? log($homeMv / $awayMv)
            : null;

        $eloDiffScaled = $eloDiff / 400.0;

        // ── Diagnostics ───────────────────────────────────────────────────────

        $structuralFavorite = self::resolveFavorite($homeRating, $awayRating, 'UNKNOWN');
        $eloFavorite        = self::resolveFavorite($homeElo, $awayElo, 'EVEN');
        $signalsAgree       = self::computeSignalsAgree($structuralFavorite, $eloFavorite);

        // ── Build output ──────────────────────────────────────────────────────

        return [
            'home_structural' => [
                'structural_rating'        => $homeRating,
                'market_value'             => $homeMv,
                'structural_snapshot_date' => $homeStructuralData['snapshot_date'] ?? null,
            ],
            'away_structural' => [
                'structural_rating'        => $awayRating,
                'market_value'             => $awayMv,
                'structural_snapshot_date' => $awayStructuralData['snapshot_date'] ?? null,
            ],
            'home_elo'               => $homeElo,
            'away_elo'               => $awayElo,
            'structural_rating_diff' => $structuralRatingDiff,
            'elo_diff'               => $eloDiff,
            'log_market_value_ratio' => $logMarketValueRatio,
            'elo_diff_scaled'        => $eloDiffScaled,
            'structural_favorite'    => $structuralFavorite,
            'elo_favorite'           => $eloFavorite,
            'signals_agree'          => $signalsAgree,
        ];
    }

    /**
     * Returns HOME / AWAY / EVEN based on which value is larger.
     * Returns $nullLabel when either value is null (structural-only case).
     */
    private static function resolveFavorite(?float $home, ?float $away, string $nullLabel): string
    {
        if ($home === null || $away === null) {
            return $nullLabel;
        }
        if ($home > $away) {
            return 'HOME';
        }
        if ($away > $home) {
            return 'AWAY';
        }
        return 'EVEN';
    }

    /**
     * signals_agree: do structural and dynamic Elo point to the same winner?
     *
     * Returns null when the structural signal is absent (UNKNOWN) or ambiguous
     * (EVEN) — in those cases there is no directional claim to compare against.
     */
    private static function computeSignalsAgree(string $structural, string $elo): ?bool
    {
        if ($structural === 'UNKNOWN' || $structural === 'EVEN') {
            return null;
        }

        return $structural === $elo;
    }
}
