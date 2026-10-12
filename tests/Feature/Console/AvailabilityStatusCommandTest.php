<?php

namespace Tests\Feature\Console;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Minimal tests for `robetting:availability-status` (Availability V1, Step 6D).
 * Pure read-only reporting command: no API calls, no DB writes — these tests
 * only assert the command runs successfully and reflects basic DB state.
 */
class AvailabilityStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    private Team $home;
    private Team $away;
    private Season $season;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::create(['name' => 'Italy', 'code' => 'IT']);
        $this->home = Team::create(['name' => 'Napoli', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Frosinone', 'country_id' => $country->id]);

        $competition = Competition::create([
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'country_id' => $country->id,
        ]);

        $this->season = Season::create([
            'competition_id' => $competition->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
        ]);
    }

    private function match(string $kickoff, string $status, ?string $injuriesFetchedAt): FootballMatch
    {
        return FootballMatch::create([
            'competition_id'      => $this->season->competition_id,
            'season_id'           => $this->season->id,
            'home_team_id'        => $this->home->id,
            'away_team_id'        => $this->away->id,
            'kickoff_at'          => $kickoff,
            'status'              => $status,
            'home_score_ft'       => $status === 'finished' ? 1 : null,
            'away_score_ft'       => $status === 'finished' ? 0 : null,
            'injuries_fetched_at' => $injuriesFetchedAt,
        ]);
    }

    public function test_reports_no_data_when_nothing_covered_yet(): void
    {
        $this->match('2026-09-01 18:00:00', 'finished', null);

        $this->artisan('robetting:availability-status')
            ->expectsOutputToContain('No injuries_fetched_at data found yet')
            ->assertExitCode(0);
    }

    public function test_reports_coverage_and_global_summary(): void
    {
        $this->match('2026-10-08 18:00:00', 'finished', '2026-10-08 10:00:00');
        $this->match('2026-10-09 18:00:00', 'finished', null);

        $this->artisan('robetting:availability-status')
            ->expectsOutputToContain('Serie A')
            ->expectsOutputToContain('finished since collection start: 2')
            ->expectsOutputToContain('finished injury-covered:         1')
            ->expectsOutputToContain('GLOBAL')
            ->assertExitCode(0);
    }

    public function test_reports_future_fixture_calculator_distribution(): void
    {
        $this->match(now()->addDays(2)->toDateTimeString(), 'scheduled', now()->toDateTimeString());

        $this->artisan('robetting:availability-status')
            ->expectsOutputToContain('TeamAvailabilityCalculator on future injury-covered fixtures')
            ->expectsOutputToContain('zero absences:   2')
            ->assertExitCode(0);
    }
}
