<?php

namespace Tests\Feature\ApiFootball;

use App\Services\DataSources\ApiFootball\ApiFootballMatchLineupSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillLineupsCommandTest extends TestCase
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
        $this->mock(ApiFootballMatchLineupSyncService::class, function ($mock) {
            $mock->shouldReceive('syncMissingHistorical')
                ->once()
                ->with(null)
                ->andReturn($this->mockResult());
        });

        $this->artisan('robetting:backfill-lineups')->assertExitCode(0);
    }

    public function test_command_delegates_to_service_with_season_year(): void
    {
        $this->mock(ApiFootballMatchLineupSyncService::class, function ($mock) {
            $mock->shouldReceive('syncMissingHistorical')
                ->once()
                ->with(2026)
                ->andReturn($this->mockResult(candidates: 3, synced: 3));
        });

        $this->artisan('robetting:backfill-lineups', ['--season' => '2026'])->assertExitCode(0);
    }

    public function test_command_output_contains_report_metrics(): void
    {
        $this->mock(ApiFootballMatchLineupSyncService::class, function ($mock) {
            $mock->shouldReceive('syncMissingHistorical')
                ->once()
                ->with(null)
                ->andReturn($this->mockResult(candidates: 7, synced: 5));
        });

        $this->artisan('robetting:backfill-lineups')
            ->expectsOutputToContain('7')
            ->expectsOutputToContain('5')
            ->assertExitCode(0);
    }
}
