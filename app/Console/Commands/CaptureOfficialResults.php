<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\Prediction\OfficialPredictionResultCaptureService;
use Illuminate\Console\Command;

/**
 * P27C — automatic result capture for official predictions.
 *
 * Works exclusively on data already in the DB (football_matches,
 * predictions) — never calls API-Football, never couples to the fixture/
 * result sync services beyond sharing their DEFINITIVE_STATUSES policy
 * constant. Original prediction fields are never touched; only the result
 * columns already defined by the P15 schema are ever written.
 */
class CaptureOfficialResults extends Command
{
    protected $signature = 'robetting:capture-official-results
        {--dry-run : Report what would be recorded without writing anything.}';

    protected $description = 'Link the real match result to official predictions once the match becomes definitive (additive, idempotent, no API calls).';

    public function handle(OfficialPredictionResultCaptureService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->line($dryRun ? 'mode: DRY-RUN (no writes)' : 'mode: LIVE');
        $this->newLine();

        $matchIds = $service->matchIdsWithPredictions();

        $counts = [
            'considered'  => 0,
            'captured'    => 0,
            'already'     => 0,
            'waiting'     => 0,
            'invalid'     => 0,
            'errors'      => 0,
        ];

        foreach ($matchIds as $matchId) {
            $counts['considered']++;
            $result = $service->processMatch($matchId, $dryRun);

            switch ($result['outcome']) {
                case 'RECORDED':
                    $counts['captured']++;
                    $this->printRow($matchId, 'RECORDED', $result['predictions_affected']);
                    break;
                case 'WOULD_RECORD':
                    $counts['captured']++;
                    $this->printRow($matchId, 'WOULD_RECORD', $result['predictions_affected']);
                    break;
                case 'ALREADY_RECORDED':
                    $counts['already']++;
                    $this->printRow($matchId, 'ALREADY_RECORDED', $result['predictions_affected']);
                    break;
                case 'WAITING_RESULT':
                    $counts['waiting']++;
                    $this->printRow($matchId, 'WAITING_RESULT', $result['predictions_affected']);
                    break;
                case 'INVALID_RESULT':
                    $counts['invalid']++;
                    $this->printRow($matchId, 'INVALID_RESULT', $result['predictions_affected'], $result['reason'] ?? null);
                    break;
                case 'ERROR':
                    $counts['errors']++;
                    $this->printRow($matchId, 'ERROR', $result['predictions_affected'], $result['reason'] ?? null);
                    break;
            }
        }

        $this->newLine();
        $this->info('=== SUMMARY ===');
        $this->line("predictions considered (distinct matches): {$counts['considered']}");
        $this->line(($dryRun ? 'would record:          ' : 'recorded:              ') . $counts['captured']);
        $this->line("already recorded:      {$counts['already']}");
        $this->line("waiting result:        {$counts['waiting']}");
        $this->line("invalid result:        {$counts['invalid']}");
        $this->line("errors:                {$counts['errors']}");

        return Command::SUCCESS;
    }

    private function printRow(int $matchId, string $status, int $predictionsAffected, ?string $reason = null): void
    {
        $match = FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])->find($matchId);
        $label = $match
            ? ($match->homeTeam?->name ?? "team#{$match->home_team_id}") . ' - ' . ($match->awayTeam?->name ?? "team#{$match->away_team_id}")
            : '(match not found)';
        $matchStatus = $match->status ?? 'n/a';

        $line = "[{$matchId}] {$label} | status={$matchStatus} | predictions={$predictionsAffected} | {$status}";
        if ($reason !== null) {
            $line .= " ({$reason})";
        }
        $this->line($line);
    }
}
