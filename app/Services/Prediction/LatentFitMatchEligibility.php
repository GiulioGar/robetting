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
 *   - core leagues only (config api-football.core_leagues)
 *   - season year_start >= $fromSeason (history starts 2024/25)
 *   - kickoff_at strictly before $before
 */
class LatentFitMatchEligibility
{
    private const TRAINING_STATUS = 'finished';

    public static function query(CarbonImmutable $before, int $fromSeason = 2024): Builder
    {
        $coreSlugs = array_values(config('api-football.core_leagues', []));

        return FootballMatch::query()
            ->where('status', self::TRAINING_STATUS)
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where('kickoff_at', '<', $before)
            ->whereHas('competition', fn ($q) => $q->whereIn('slug', $coreSlugs))
            ->whereHas('season', fn ($q) => $q->where('year_start', '>=', $fromSeason));
    }

    /**
     * Count eligible matches and find the latest eligible kickoff, applying
     * the same MatchOutcomeLabelBuilder validity check as the export command
     * (guards against a negative/corrupt score slipping through the SQL filters).
     *
     * @return array{count: int, latest_kickoff_at: string|null}
     */
    public static function countAndLatestKickoff(CarbonImmutable $before, int $fromSeason = 2024): array
    {
        $matches = self::query($before, $fromSeason)
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
