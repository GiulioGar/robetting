<?php

namespace Tests\Feature\ApiFootball;

use App\Services\DataSources\ApiFootball\ApiFootballMatchEventSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function mockResult(int $candidates = 0, int $synced = 0): array
    {
        return [
            'status'          => 'ok',
            'candidates'      => $candidates,
            'synced'          => $synced,
            'empty'           => 0,
            'failed'          => 0,
            'api_calls'       => $synced,
            'daily_remaining' => null,
        ];
    }

    public function test_command_delegates_to_service_without_season_option(): void
    {
        $this->mock(ApiFootballMatchEventSyncService::class, function ($mock) {
            $mock->shouldReceive('syncMissingHistorical')
                ->once()
                ->with(null)
                ->andReturn($this->mockResult());
        });

        $this->artisan('robetting:backfill-events')->assertExitCode(0);
    }

    public function test_command_delegates_to_service_with_season_year(): void
    {
        $this->mock(ApiFootballMatchEventSyncService::class, function ($mock) {
            $mock->shouldReceive('syncMissingHistorical')
                ->once()
                ->with(2025)
                ->andReturn($this->mockResult(candidates: 10, synced: 10));
        });

        $this->artisan('robetting:backfill-events', ['--season' => '2025'])->assertExitCode(0);
    }

    public function test_command_output_contains_report_metrics(): void
    {
        $this->mock(ApiFootballMatchEventSyncService::class, function ($mock) {
            $mock->shouldReceive('syncMissingHistorical')
                ->once()
                ->with(null)
                ->andReturn($this->mockResult(candidates: 15, synced: 12));
        });

        $this->artisan('robetting:backfill-events')
            ->expectsOutputToContain('15')
            ->expectsOutputToContain('12')
            ->assertExitCode(0);
    }
}
