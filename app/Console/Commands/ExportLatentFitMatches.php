<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\Prediction\MatchOutcomeLabelBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Export the match results used to fit the CURRENT Latent Attack/Defence snapshot
 * (tools/scripts/generate_latent_strength_snapshot.py reads this CSV).
 *
 * Selection mirrors the prediction dataset policy (BuildPredictionDataset):
 *   - status 'finished' only (awarded/walkover excluded, as in ML labels V1)
 *   - FT score present and accepted by MatchOutcomeLabelBuilder
 *   - core leagues only (config api-football.core_leagues)
 *   - seasons with year_start >= --from-season (history starts 2024/25)
 *   - kickoff_at strictly before --before (anti-leakage cutoff = snapshot generated_at)
 */
class ExportLatentFitMatches extends Command
{
    protected $signature = 'robetting:export-latent-fit-matches
        {--before=          : Cutoff, ISO8601 UTC. Only matches with kickoff_at strictly before it are exported.}
        {--from-season=2024 : First season year_start included}
        {--output=          : Output CSV path}';

    protected $description = 'Export finished core-league results (before a cutoff) for the Latent Attack/Defence snapshot fit.';

    private const TRAINING_STATUS = 'finished';

    public function handle(): int
    {
        $before = (string) $this->option('before');
        $output = (string) $this->option('output');

        if ($before === '' || $output === '') {
            $this->error('--before and --output are required.');
            return Command::FAILURE;
        }

        $cutoff     = CarbonImmutable::parse($before)->utc();
        $fromSeason = (int) $this->option('from-season');
        $coreSlugs  = array_values(config('api-football.core_leagues', []));

        $matches = FootballMatch::query()
            ->with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->where('status', self::TRAINING_STATUS)
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where('kickoff_at', '<', $cutoff)
            ->whereHas('competition', fn ($q) => $q->whereIn('slug', $coreSlugs))
            ->whereHas('season', fn ($q) => $q->where('year_start', '>=', $fromSeason))
            ->orderBy('kickoff_at')
            ->orderBy('id')
            ->get();

        $handle = fopen($output, 'w');
        if ($handle === false) {
            $this->error("Cannot open output file: {$output}");
            return Command::FAILURE;
        }

        fputcsv($handle, [
            'match_id', 'kickoff_at', 'competition_id', 'season_id',
            'home_team_id', 'away_team_id', 'home_team_name', 'away_team_name',
            'home_goals', 'away_goals',
        ]);

        $written = 0;
        $skipped = 0;
        foreach ($matches as $match) {
            try {
                MatchOutcomeLabelBuilder::build($match);
            } catch (InvalidArgumentException) {
                $skipped++;
                continue;
            }

            fputcsv($handle, [
                $match->id,
                $match->kickoff_at->copy()->utc()->toIso8601String(),
                $match->competition_id,
                $match->season_id,
                $match->home_team_id,
                $match->away_team_id,
                $match->homeTeam?->name,
                $match->awayTeam?->name,
                (int) $match->home_score_ft,
                (int) $match->away_score_ft,
            ]);
            $written++;
        }
        fclose($handle);

        $this->info("Exported {$written} matches (skipped {$skipped} invalid) before {$cutoff->toIso8601String()} to {$output}");

        return Command::SUCCESS;
    }
}
