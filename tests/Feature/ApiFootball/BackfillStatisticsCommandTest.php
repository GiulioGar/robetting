<?php

namespace Tests\Feature\ApiFootball;

use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillStatisticsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function mockResult(int $candidates = 0, int $created = 0): array
    {
        return [
            'status'          => 'ok',
            'candidates'      => $candidates,
            'created'         => $created,
            'updated'         => 0,
            'unchanged'       => 0,
            'failed'          => 0,
            'api_calls'       => $created,
            'daily_remaining' => null,
        ];
    }

    public function test_command_delegates_to_service_without_season_option(): void
    {
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($mock) {
            $mock->shouldReceive('syncMissingHistorical')
                ->once()
                ->with(null)
                ->andReturn($this->mockResult());
        });

        $this->artisan('robetting:backfill-statistics')->assertExitCode(0);
    }

    public function test_command_delegates_to_service_with_season_year(): void
    {
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($mock) {
            $mock->shouldReceive('syncMissingHistorical')
                ->once()
                ->with(2025)
                ->andReturn($this->mockResult(candidates: 5, created: 5));
        });

        $this->artisan('robetting:backfill-statistics', ['--season' => '2025'])->assertExitCode(0);
    }

    public function test_command_output_contains_report_metrics(): void
    {
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($mock) {
            $mock->shouldReceive('syncMissingHistorical')
                ->once()
                ->with(null)
                ->andReturn($this->mockResult(candidates: 10, created: 8));
        });

        $this->artisan('robetting:backfill-statistics')
            ->expectsOutputToContain('10')
            ->expectsOutputToContain('8')
            ->assertExitCode(0);
    }
}
