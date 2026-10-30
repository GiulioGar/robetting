<?php

namespace App\Console\Commands;

use App\Models\Prediction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * B-P1 (knowledge/GOAL_RESEARCH_PROTOCOL.md §12): READ-ONLY export of the official predictions with their
 * match result/metadata, plus the denominator of core-league matches, in the CSV format read by
 * tools/scripts/goal_bp1_prospective_analysis.py. Only SELECT queries: no DB write, no API call,
 * no prediction or model execution. The analysis script applies every filter of §12.3 itself;
 * this command exports ALL official rows of the season (any model/version/sha) so that exclusions
 * can be counted by reason.
 *
 * is_playoff = 0 when matches.round matches 'Regular Season - N', 1 for any other non-null label,
 * empty when round is NULL (never guessed; the script then excludes the row as invalid_playoff_flag).
 * Checked on 2024/25 and 2025/26: the only non-"Regular Season" labels are the 2 playoff matches of
 * leagues 18 and 19 ('Relegation Round' / 'Final').
 */
class ExportBp1Sample extends Command
{
    /** Same core competitions as the analysis script. */
    private const CORE_COMPETITION_IDS = [15, 16, 17, 18, 19];

    protected $signature = 'robetting:export-bp1-sample
        {--out-dir= : REQUIRED. Existing or new directory for bp1_predictions.csv and bp1_eligible.csv}
        {--season-year=2026 : seasons.year_start of the season to export}
        {--force : Overwrite the output files if they exist}';

    protected $description = 'Read-only export (official predictions + results + denominator) for the B-P1 prospective analysis.';

    public const PREDICTION_COLUMNS = [
        'id', 'match_id', 'competition_id', 'purpose', 'model_key', 'model_version', 'artifact_sha256',
        'generated_at', 'kickoff_at', 'match_kickoff_at', 'match_status', 'home_score_ft', 'away_score_ft',
        'lambda_home', 'lambda_away', 'lambda3', 'latent_snapshot_generated_at', 'is_playoff',
        'structural_snapshot_version', 'has_availability',
    ];

    public const ELIGIBLE_COLUMNS = [
        'match_id', 'competition_id', 'kickoff_at', 'status', 'home_score_ft', 'away_score_ft', 'is_playoff',
    ];

    public function handle(): int
    {
        $dir = (string) $this->option('out-dir');
        if ($dir === '') {
            $this->error('--out-dir is required.');
            return self::INVALID;
        }
        $year = (int) $this->option('season-year');
        File::ensureDirectoryExists($dir);

        $predPath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'bp1_predictions.csv';
        $eligPath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'bp1_eligible.csv';
        foreach ([$predPath, $eligPath] as $path) {
            if (is_file($path) && ! $this->option('force')) {
                $this->error("Output exists: {$path} (use --force to overwrite).");
                return self::FAILURE;
            }
        }

        $predictions = DB::table('predictions as p')
            ->join('matches as m', 'm.id', '=', 'p.match_id')
            ->join('seasons as s', 's.id', '=', 'm.season_id')
            ->where('p.purpose', Prediction::PURPOSE_OFFICIAL)
            ->where('s.year_start', $year)
            ->orderBy('p.id')
            ->get([
                'p.id', 'p.match_id', 'm.competition_id', 'p.purpose', 'p.model_key', 'p.model_version',
                'p.artifact_sha256', 'p.generated_at', 'p.kickoff_at', 'm.kickoff_at as match_kickoff_at',
                'm.status as match_status', 'm.home_score_ft', 'm.away_score_ft', 'p.lambda_home', 'p.lambda_away',
                'p.lambda3', 'p.latent_snapshot_generated_at', 'm.round', 'p.structural_snapshot_version',
                DB::raw('(p.availability_snapshot_json IS NOT NULL) as has_availability'),
            ]);

        $fh = fopen($predPath, 'w');
        fputcsv($fh, self::PREDICTION_COLUMNS);
        foreach ($predictions as $r) {
            fputcsv($fh, [
                $r->id, $r->match_id, $r->competition_id, $r->purpose, $r->model_key, $r->model_version,
                $r->artifact_sha256, $r->generated_at, $r->kickoff_at, $r->match_kickoff_at, $r->match_status,
                $r->home_score_ft, $r->away_score_ft, $r->lambda_home, $r->lambda_away, $r->lambda3,
                $r->latent_snapshot_generated_at, self::playoffFlag($r->round), $r->structural_snapshot_version,
                (int) $r->has_availability,
            ]);
        }
        fclose($fh);

        $matches = DB::table('matches as m')
            ->join('seasons as s', 's.id', '=', 'm.season_id')
            ->whereIn('m.competition_id', self::CORE_COMPETITION_IDS)
            ->where('s.year_start', $year)
            ->orderBy('m.id')
            ->get(['m.id', 'm.competition_id', 'm.kickoff_at', 'm.status', 'm.home_score_ft', 'm.away_score_ft', 'm.round']);

        $fh = fopen($eligPath, 'w');
        fputcsv($fh, self::ELIGIBLE_COLUMNS);
        foreach ($matches as $m) {
            fputcsv($fh, [$m->id, $m->competition_id, $m->kickoff_at, $m->status, $m->home_score_ft, $m->away_score_ft, self::playoffFlag($m->round)]);
        }
        fclose($fh);

        $this->info("Wrote {$predPath} (" . count($predictions) . ' rows) and ' . $eligPath . ' (' . count($matches) . ' rows).');

        return self::SUCCESS;
    }

    /** 0 regular season, 1 any other label, null (empty cell) when the label is missing. */
    public static function playoffFlag(?string $round): ?int
    {
        if ($round === null || trim($round) === '') {
            return null;
        }

        return preg_match('/^Regular Season - [0-9]+$/', $round) === 1 ? 0 : 1;
    }
}
