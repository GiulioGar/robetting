<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\FootballMatch;
use App\Services\Analytics\TeamAvailabilityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Availability V1 — Step 6D. Read-only operational status: how much
 * prospective injury coverage exists so far, is it enough to start testing
 * Availability in a Challenger. Makes NO API calls and writes NOTHING to the
 * DB — pure reporting over data already collected by
 * ApiFootballInjurySyncService (wired into robetting:serve since Step 5D).
 *
 * The "readiness" threshold (>=5 covered finished matches per team) shown
 * below is a reporting indicator ONLY. It is not read by
 * TeamAvailabilityCalculator, not read by the Prediction Engine, and not
 * enforced anywhere — a human decides when/whether to start a Challenger.
 *
 * "Prospective collection start" is derived from the data itself (earliest
 * injuries_fetched_at across the 5 core leagues), never hardcoded — some
 * fixtures were fetched ad hoc before Step 5C's systematic 72h sync began
 * (Step 5A), so this is the true floor of whatever coverage exists, not a
 * claim about when the systematic sync specifically started.
 */
class AvailabilityStatus extends Command
{
    protected $signature = 'robetting:availability-status';

    protected $description = 'Read-only report on prospective injury-coverage volume for Availability V1 (no API calls, no DB writes).';

    private const READY_THRESHOLD = 5;

    public function handle(): int
    {
        $coreSlugs = array_values(config('api-football.core_leagues', []));

        $collectionStart = FootballMatch::whereNotNull('injuries_fetched_at')
            ->whereHas('competition', fn ($q) => $q->whereIn('slug', $coreSlugs))
            ->min('injuries_fetched_at');

        if ($collectionStart === null) {
            $this->warn('No injuries_fetched_at data found yet for the 5 core leagues — nothing to report.');
            return Command::SUCCESS;
        }

        $collectionStart = CarbonImmutable::parse($collectionStart);
        $this->info('Prospective collection start (earliest injuries_fetched_at): ' . $collectionStart->toIso8601String());
        $this->newLine();

        $totalReadyTeams = 0;
        $totalTeams = 0;

        foreach (Competition::whereIn('slug', $coreSlugs)->orderBy('name')->get() as $competition) {
            [$readyTeams, $teamCount] = $this->reportLeague($competition, $collectionStart);
            $totalReadyTeams += $readyTeams;
            $totalTeams += $teamCount;
            $this->newLine();
        }

        $this->info('=== GLOBAL ===');
        $this->line("teams with >= " . self::READY_THRESHOLD . " covered finished matches: {$totalReadyTeams}/{$totalTeams}");
        $this->newLine();

        $this->reportFutureCalculatorDistribution($coreSlugs);

        return Command::SUCCESS;
    }

    /** @return array{0: int, 1: int} [readyTeamCount, totalTeamCount] */
    private function reportLeague(Competition $competition, CarbonImmutable $collectionStart): array
    {
        $this->info("=== {$competition->name} ===");

        $finishedQuery = FootballMatch::where('competition_id', $competition->id)
            ->where('status', 'finished')
            ->where('kickoff_at', '>=', $collectionStart);

        $finishedSinceStart = (clone $finishedQuery)->count();
        $finishedCovered = (clone $finishedQuery)->whereNotNull('injuries_fetched_at')->count();

        $this->line("finished since collection start: {$finishedSinceStart}");
        $this->line("finished injury-covered:         {$finishedCovered}");

        $perTeam = $this->coveredMatchesPerTeam($competition->id, $collectionStart);
        $teamCount = count($perTeam);
        $readyTeams = count(array_filter($perTeam, fn ($n) => $n >= self::READY_THRESHOLD));

        $this->line("teams >= " . self::READY_THRESHOLD . " covered matches: {$readyTeams}/{$teamCount}");

        if ($teamCount > 0) {
            $sorted = $perTeam;
            asort($sorted);
            $worst = array_slice($sorted, 0, 3, true);
            $parts = [];
            foreach ($worst as $teamName => $n) {
                $parts[] = "{$teamName}={$n}";
            }
            $this->line('lowest-covered teams: ' . implode(', ', $parts));
        }

        return [$readyTeams, $teamCount];
    }

    /** @return array<string, int> team name => count of covered finished matches */
    private function coveredMatchesPerTeam(int $competitionId, CarbonImmutable $collectionStart): array
    {
        $matches = FootballMatch::where('competition_id', $competitionId)
            ->where('status', 'finished')
            ->where('kickoff_at', '>=', $collectionStart)
            ->whereNotNull('injuries_fetched_at')
            ->with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->get(['id', 'home_team_id', 'away_team_id']);

        $counts = [];
        foreach ($matches as $match) {
            foreach ([$match->homeTeam, $match->awayTeam] as $team) {
                if ($team === null) {
                    continue;
                }
                $counts[$team->name] = ($counts[$team->name] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function reportFutureCalculatorDistribution(array $coreSlugs): void
    {
        $this->info('=== TeamAvailabilityCalculator on future injury-covered fixtures ===');

        $futureMatches = FootballMatch::where('status', '!=', 'finished')
            ->whereNotNull('injuries_fetched_at')
            ->whereHas('competition', fn ($q) => $q->whereIn('slug', $coreSlugs))
            ->with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->get(['id', 'home_team_id', 'away_team_id', 'kickoff_at', 'injuries_fetched_at']);

        if ($futureMatches->isEmpty()) {
            $this->line('No future injury-covered fixtures yet.');
            return;
        }

        $complete = 0;
        $incomplete = 0;
        $zeroAbsence = 0;
        $causeCounts = [];

        foreach ($futureMatches as $match) {
            foreach ([$match->home_team_id, $match->away_team_id] as $teamId) {
                $result = TeamAvailabilityCalculator::calculateForMatch($match, (int) $teamId);

                if (!$result['injuries_covered']) {
                    continue;
                }

                if ($result['forced_absent_count'] === 0) {
                    $zeroAbsence++;
                    continue;
                }

                if ($result['is_complete']) {
                    $complete++;
                } else {
                    $incomplete++;
                    foreach ($result['absentees'] as $absentee) {
                        foreach (['u_null_cause', 'v_null_cause'] as $causeField) {
                            if ($absentee[$causeField] !== null) {
                                $causeCounts[$absentee[$causeField]] = ($causeCounts[$absentee[$causeField]] ?? 0) + 1;
                            }
                        }
                    }
                }
            }
        }

        $this->line("fixtures checked (home+away team slots): " . ($complete + $incomplete + $zeroAbsence));
        $this->line("complete:        {$complete}");
        $this->line("incomplete:      {$incomplete}");
        $this->line("zero absences:   {$zeroAbsence}");

        if ($causeCounts !== []) {
            arsort($causeCounts);
            $this->newLine();
            $this->line('incomplete causes:');
            foreach ($causeCounts as $cause => $n) {
                $this->line("  {$cause}: {$n}");
            }
        }
    }
}
