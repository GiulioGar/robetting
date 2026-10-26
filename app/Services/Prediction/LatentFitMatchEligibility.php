<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Single source of truth for "which matches are eligible to fit the CURRENT
 * Latent Attack/Defence snapshot" — shared by the export command that feeds
 * the Python fitter (ExportLatentFitMatches) and by the read-only status
 * command (PredictionSnapshotsStatus), so the two can never silently drift
 * apart on what counts as eligible.
 *
 * Eligibility (unchanged from the original P19A policy):
 *   - status = 'finished' (awarded/walkover excluded, as in ML labels V1)
 *   - valid FT score, accepted by MatchOutcomeLabelBuilder (non-null, non-negative)
 *   - competition population per $latentMode (see below)
 *   - season year_start >= $fromSeason (history starts 2024/25)
 *   - kickoff_at strictly before $before
 */
class LatentFitMatchEligibility
{
    private const TRAINING_STATUS = 'finished';

    public const LATENT_MODE_LEGACY   = 'legacy';
    public const LATENT_MODE_EXTENDED = 'extended';

    /** UEFA club competitions added in 'extended' mode — national cups never included. */
    private const LATENT_EXTENDED_UEFA_SLUGS = [
        'uefa-champions-league',
        'uefa-europa-league',
        'uefa-conference-league',
    ];

    /**
     * @param  string  $latentMode  OPT-IN only.
     *                                - 'legacy' (default): IDENTICAL to pre-existing behaviour —
     *                                  core leagues only (config api-football.core_leagues).
     *                                - 'extended': core leagues + UEFA Champions/Europa/Conference
     *                                  League (config api-football.core_leagues +
     *                                  self::LATENT_EXTENDED_UEFA_SLUGS) — national cups excluded.
     *                              Every other criterion (status/score/cutoff/season) is unchanged;
     *                              only the competition population changes.
     * @throws InvalidArgumentException when $latentMode is unknown.
     */
    public static function query(
        CarbonImmutable $before,
        int $fromSeason = 2024,
        string $latentMode = self::LATENT_MODE_LEGACY,
    ): Builder {
        if (! in_array($latentMode, [self::LATENT_MODE_LEGACY, self::LATENT_MODE_EXTENDED], true)) {
            throw new InvalidArgumentException(
                "Unknown latentMode '{$latentMode}'. Valid: legacy, extended."
            );
        }

        $slugs = $latentMode === self::LATENT_MODE_EXTENDED
            ? array_merge(
                array_values((array) config('api-football.core_leagues', [])),
                self::LATENT_EXTENDED_UEFA_SLUGS,
            )
            : array_values((array) config('api-football.core_leagues', []));

        return FootballMatch::query()
            ->where('status', self::TRAINING_STATUS)
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where('kickoff_at', '<', $before)
            ->whereHas('competition', fn ($q) => $q->whereIn('slug', $slugs))
            ->whereHas('season', fn ($q) => $q->where('year_start', '>=', $fromSeason));
    }

    /**
     * Count eligible matches and find the latest eligible kickoff, applying
     * the same MatchOutcomeLabelBuilder validity check as the export command
     * (guards against a negative/corrupt score slipping through the SQL filters).
     *
     * @return array{count: int, latest_kickoff_at: string|null}
     */
    public static function countAndLatestKickoff(
        CarbonImmutable $before,
        int $fromSeason = 2024,
        string $latentMode = self::LATENT_MODE_LEGACY,
    ): array {
        $matches = self::query($before, $fromSeason, $latentMode)
            ->orderBy('kickoff_at')
            ->get(['id', 'status', 'kickoff_at', 'home_score_ft', 'away_score_ft']);

        $count = 0;
        $latest = null;
        foreach ($matches as $match) {
            try {
                MatchOutcomeLabelBuilder::build($match);
            } catch (InvalidArgumentException) {
                continue;
            }
            $count++;
            $latest = $match->kickoff_at->copy()->utc()->toIso8601String();
        }

        return ['count' => $count, 'latest_kickoff_at' => $latest];
    }
}
