<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\Prediction\OfficialPredictionCaptureService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * P27B — automatic capture of official Candidate V2 LOG predictions for
 * eligible future matches within a configurable window.
 *
 * No default policy is hardcoded: --within-hours is required, so the
 * operator decides the capture window explicitly on every run rather than
 * trusting an arbitrary built-in number.
 */
class CaptureOfficialPredictions extends Command
{
    protected $signature = 'robetting:capture-official-predictions
        {--within-hours= : REQUIRED. Capture matches with kickoff within this many hours from now.}
        {--safety-margin-minutes=30 : Minimum minutes before kickoff required to capture (configurable — see class docblock).}
        {--dry-run : Report what would happen without writing any prediction row.}';

    protected $description = 'Capture official Candidate V2 LOG predictions for eligible future matches (additive, idempotent, never falls back to FULL59).';

    public function handle(OfficialPredictionCaptureService $service): int
    {
        $rawWithinHours = $this->option('within-hours');
        if ($rawWithinHours === null || $rawWithinHours === '') {
            $this->error('--within-hours is required (e.g. --within-hours=24). No default capture window is hardcoded.');
            return Command::INVALID;
        }
        $withinHours = (int) $rawWithinHours;
        if ($withinHours <= 0) {
            $this->error('--within-hours must be a positive integer.');
            return Command::INVALID;
        }

        $safetyMarginMinutes = (int) $this->option('safety-margin-minutes');
        if ($safetyMarginMinutes < 0) {
            $this->error('--safety-margin-minutes cannot be negative.');
            return Command::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');

        $now = CarbonImmutable::now('UTC');
        $windowEnd = $now->addHours($withinHours);

        $this->info('window: ' . $now->toIso8601String() . ' -> ' . $windowEnd->toIso8601String() . " ({$withinHours}h)");
        $this->line("safety margin: {$safetyMarginMinutes} minutes before kickoff");
        $this->line($dryRun ? 'mode: DRY-RUN (no writes)' : 'mode: LIVE');
        $this->newLine();

        $matches = $service->findEligibleMatches($now, $withinHours);

        $counts = [
            'considered'  => 0,
            'captured'    => 0,
            'already'     => 0,
            'unavailable' => 0,
            'too_close'   => 0,
            'errors'      => 0,
        ];

        foreach ($matches as $match) {
            $counts['considered']++;

            $preClass = $service->preClassify($match, $now, $safetyMarginMinutes);
            if ($preClass === 'TOO_CLOSE') {
                $counts['too_close']++;
                $this->printRow($match, 'TOO_CLOSE');
                continue;
            }
            if ($preClass === 'ALREADY_CAPTURED') {
                $counts['already']++;
                $this->printRow($match, 'ALREADY_CAPTURED');
                continue;
            }

            $result = $service->capture($match, $dryRun);

            switch ($result['outcome']) {
                case 'CAPTURED':
                    $counts['captured']++;
                    $this->printRow($match, 'CAPTURED');
                    break;
                case 'WOULD_CAPTURE':
                    $counts['captured']++;
                    $this->printRow($match, 'WOULD_CAPTURE');
                    break;
                case 'MODEL_UNAVAILABLE':
                    $counts['unavailable']++;
                    $this->printRow($match, 'MODEL_UNAVAILABLE', $result['reason'] ?? null);
                    break;
                case 'ERROR':
                    $counts['errors']++;
                    $this->printRow($match, 'ERROR', $result['reason'] ?? null);
                    break;
            }
        }

        $this->newLine();
        $this->info('=== SUMMARY ===');
        $this->line("matches considered:    {$counts['considered']}");
        $this->line(($dryRun ? 'would capture:         ' : 'captured:              ') . $counts['captured']);
        $this->line("already captured:      {$counts['already']}");
        $this->line("model unavailable:     {$counts['unavailable']}");
        $this->line("too close to kickoff:  {$counts['too_close']}");
        $this->line("errors:                {$counts['errors']}");

        return Command::SUCCESS;
    }

    private function printRow(FootballMatch $match, string $status, ?string $reason = null): void
    {
        $home = $match->homeTeam?->name ?? "team#{$match->home_team_id}";
        $away = $match->awayTeam?->name ?? "team#{$match->away_team_id}";
        $kickoff = $match->kickoff_at?->toIso8601String() ?? 'n/a';

        $line = "[{$match->id}] {$home} - {$away} | {$kickoff} | {$status}";
        if ($reason !== null) {
            $line .= " ({$reason})";
        }
        $this->line($line);
    }
}
