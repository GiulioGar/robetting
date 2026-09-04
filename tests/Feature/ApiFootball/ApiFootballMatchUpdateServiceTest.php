<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\Season;
use App\Models\Team;
use App\Services\DataSources\ApiFootball\ApiFootballException;
use App\Services\DataSources\ApiFootball\ApiFootballMatchEventSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchLineupSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchUpdateService;
use App\Services\DataSources\ApiFootball\ApiFootballResultRefreshService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies ApiFootballMatchUpdateService orchestration logic.
 *
 * Rules under test:
 *  - External ID resolved once; missing ID → immediate failure, zero API calls.
 *  - scheduled match → result + lineup attempted; events + stats skipped.
 *  - live match → result + lineup + events (live) + stats (live) attempted.
 *  - finished match → result + lineup + events (post) + stats (post) attempted.
 *  - An error in one component does not prevent other components from running.
 *  - Each component strictly delegates to the existing atomic service (no logic duplication).
 *  - result error captured in warnings; status becomes 'partial'.
 *  - api_calls total is the sum of all component calls.
 */
class ApiFootballMatchUpdateServiceTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_EXT_ID = '98765';

    private DataSource $ds;
    private Team $homeTeam;
    private Team $awayTeam;
    private int $compId;
    private int $seasonId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);

        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $comp    = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $season  = Season::create([
            'competition_id' => $comp->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);

        $this->compId   = $comp->id;
        $this->seasonId = $season->id;

        $this->homeTeam = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->awayTeam = Team::create(['name' => 'Milan',  'type' => 'club', 'is_active' => true]);
    }

    // -------------------------------------------------------------------------
    // 1. External ID absent → immediate failure, zero API calls, no delegates
    // -------------------------------------------------------------------------

    public function test_no_external_id_returns_error_with_zero_api_calls(): void
    {
        $match = $this->makeMatch('scheduled');

        // No MatchExternalId row created — services must NOT be called.
        $this->mock(ApiFootballResultRefreshService::class,
            fn($m) => $m->shouldNotReceive('refreshSingle'));
        $this->mock(ApiFootballMatchLineupSyncService::class,
            fn($m) => $m->shouldNotReceive('syncSingle'));
        $this->mock(ApiFootballMatchEventSyncService::class,
            fn($m) => $m->shouldNotReceive('syncSingle')
                        ->shouldNotReceive('syncLiveSingle')
                        ->shouldNotReceive('forceSyncSingle'));
        $this->mock(ApiFootballMatchStatisticsSyncService::class,
            fn($m) => $m->shouldNotReceive('syncSingle')->shouldNotReceive('syncLiveSingle'));

        $service = app(ApiFootballMatchUpdateService::class);
        $result  = $service->update($match);

        $this->assertSame('error', $result['status']);
        $this->assertSame('no_external_id', $result['reason']);
        $this->assertSame(0, $result['api_calls']);
        $this->assertNull($result['result']);
        $this->assertNull($result['lineup']);
        $this->assertNull($result['events']);
        $this->assertNull($result['statistics']);
    }

    // -------------------------------------------------------------------------
    // 2. Scheduled match → result + lineup attempted; events + stats skipped
    // -------------------------------------------------------------------------

    public function test_scheduled_match_attempts_result_and_lineup_skips_events_and_stats(): void
    {
        $match = $this->makeMatch('scheduled');
        $this->attachExtId($match);

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('refreshSingle')->once()
              ->andReturn(['outcome' => 'updated', 'api_calls' => 1])
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1])
        );
        $this->mock(ApiFootballMatchEventSyncService::class,
            fn($m) => $m->shouldNotReceive('syncSingle')
                        ->shouldNotReceive('syncLiveSingle')
                        ->shouldNotReceive('forceSyncSingle'));
        $this->mock(ApiFootballMatchStatisticsSyncService::class,
            fn($m) => $m->shouldNotReceive('syncSingle')->shouldNotReceive('syncLiveSingle'));

        $service = app(ApiFootballMatchUpdateService::class);
        $result  = $service->update($match);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['api_calls']);
        $this->assertSame('updated', $result['result']['outcome']);
        $this->assertSame('synced',  $result['lineup']['outcome']);
        $this->assertSame('skipped_scheduled', $result['events']['outcome']);
        $this->assertSame('skipped_scheduled', $result['statistics']['outcome']);
        $this->assertEmpty($result['warnings']);
    }

    // -------------------------------------------------------------------------
    // 3. Live match → result + lineup + events (live) + stats (live)
    // -------------------------------------------------------------------------

    public function test_live_match_calls_live_variants_for_events_and_stats(): void
    {
        $match = $this->makeMatch('live');
        $this->attachExtId($match);

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('refreshSingle')->once()
              ->andReturn(['outcome' => 'updated', 'api_calls' => 1])
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1])
        );
        $this->mock(ApiFootballMatchEventSyncService::class, function ($m) {
            $m->shouldNotReceive('syncSingle');
            $m->shouldNotReceive('forceSyncSingle');
            $m->shouldReceive('syncLiveSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1, 'events_count' => 3]);
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($m) {
            $m->shouldNotReceive('syncSingle');
            $m->shouldReceive('syncLiveSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1]);
        });

        $service = app(ApiFootballMatchUpdateService::class);
        $result  = $service->update($match);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(4, $result['api_calls']);
        $this->assertSame('synced', $result['events']['outcome']);
        $this->assertSame('synced', $result['statistics']['outcome']);
        $this->assertEmpty($result['warnings']);
    }

    // -------------------------------------------------------------------------
    // 4. Finished match → result + lineup + events (post) + stats (post)
    // -------------------------------------------------------------------------

    public function test_finished_match_calls_post_match_variants_for_events_and_stats(): void
    {
        $match = $this->makeMatch('finished');
        $this->attachExtId($match);

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('refreshSingle')->once()
              ->andReturn(['outcome' => 'unchanged', 'api_calls' => 1])
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1])
        );
        $this->mock(ApiFootballMatchEventSyncService::class, function ($m) {
            $m->shouldNotReceive('syncSingle');
            $m->shouldNotReceive('syncLiveSingle');
            $m->shouldReceive('forceSyncSingle')->once()
              ->andReturn(['outcome' => 'skipped_complete', 'api_calls' => 0, 'events_count' => 0]);
        });
        $this->mock(ApiFootballMatchStatisticsSyncService::class, function ($m) {
            $m->shouldNotReceive('syncLiveSingle');
            $m->shouldReceive('syncSingle')->once()
              ->andReturn(['outcome' => 'skipped_complete', 'api_calls' => 0]);
        });

        $service = app(ApiFootballMatchUpdateService::class);
        $result  = $service->update($match);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['api_calls']); // result(1) + lineup(1), events+stats skip API
        $this->assertSame('skipped_complete', $result['events']['outcome']);
        $this->assertSame('skipped_complete', $result['statistics']['outcome']);
        $this->assertEmpty($result['warnings']);
    }

    // -------------------------------------------------------------------------
    // 5. Events HTTP error does not block statistics
    // -------------------------------------------------------------------------

    public function test_events_error_does_not_block_statistics(): void
    {
        $match = $this->makeMatch('finished');
        $this->attachExtId($match);

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('refreshSingle')->once()
              ->andReturn(['outcome' => 'updated', 'api_calls' => 1])
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1])
        );
        $this->mock(ApiFootballMatchEventSyncService::class, fn($m) =>
            $m->shouldReceive('forceSyncSingle')->once()
              ->andThrow(new ApiFootballException('HTTP 429'))
        );
        $this->mock(ApiFootballMatchStatisticsSyncService::class, fn($m) =>
            // Must still be called despite events failure
            $m->shouldReceive('syncSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1])
        );

        $service = app(ApiFootballMatchUpdateService::class);
        $result  = $service->update($match);

        $this->assertSame('partial', $result['status']);
        $this->assertSame('error', $result['events']['outcome']);
        $this->assertSame('synced', $result['statistics']['outcome']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('events', $result['warnings'][0]);
    }

    // -------------------------------------------------------------------------
    // 6. Result HTTP error does not block lineup/events/stats
    // -------------------------------------------------------------------------

    public function test_result_error_does_not_block_other_components(): void
    {
        $match = $this->makeMatch('live');
        $this->attachExtId($match);

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('refreshSingle')->once()
              ->andThrow(new ApiFootballException('timeout'))
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1])
        );
        $this->mock(ApiFootballMatchEventSyncService::class, fn($m) =>
            $m->shouldReceive('syncLiveSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1, 'events_count' => 2])
        );
        $this->mock(ApiFootballMatchStatisticsSyncService::class, fn($m) =>
            $m->shouldReceive('syncLiveSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1])
        );

        $service = app(ApiFootballMatchUpdateService::class);
        $result  = $service->update($match);

        $this->assertSame('partial', $result['status']);
        $this->assertSame('error', $result['result']['outcome']);
        $this->assertSame('synced', $result['lineup']['outcome']);
        $this->assertSame('synced', $result['events']['outcome']);
        $this->assertSame('synced', $result['statistics']['outcome']);
        $this->assertStringContainsString('result', $result['warnings'][0]);
    }

    // -------------------------------------------------------------------------
    // 7. api_calls total is the sum of all component calls
    // -------------------------------------------------------------------------

    public function test_api_calls_total_is_summed_across_components(): void
    {
        $match = $this->makeMatch('finished');
        $this->attachExtId($match);

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('refreshSingle')->once()
              ->andReturn(['outcome' => 'updated', 'api_calls' => 1])
        );
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1])
        );
        $this->mock(ApiFootballMatchEventSyncService::class, fn($m) =>
            $m->shouldReceive('forceSyncSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1, 'events_count' => 5])
        );
        $this->mock(ApiFootballMatchStatisticsSyncService::class, fn($m) =>
            $m->shouldReceive('syncSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1])
        );

        $service = app(ApiFootballMatchUpdateService::class);
        $result  = $service->update($match);

        $this->assertSame(4, $result['api_calls']);
    }

    // -------------------------------------------------------------------------
    // 8. Lineup http_error captured in warnings
    // -------------------------------------------------------------------------

    public function test_lineup_http_error_captured_in_warnings(): void
    {
        $match = $this->makeMatch('finished');
        $this->attachExtId($match);

        $this->mock(ApiFootballResultRefreshService::class, fn($m) =>
            $m->shouldReceive('refreshSingle')->once()
              ->andReturn(['outcome' => 'updated', 'api_calls' => 1])
        );
        // Lineup service catches internally and returns http_error outcome
        $this->mock(ApiFootballMatchLineupSyncService::class, fn($m) =>
            $m->shouldReceive('syncSingle')->once()
              ->andReturn(['outcome' => 'http_error', 'api_calls' => 0])
        );
        $this->mock(ApiFootballMatchEventSyncService::class, fn($m) =>
            $m->shouldReceive('forceSyncSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1, 'events_count' => 0])
        );
        $this->mock(ApiFootballMatchStatisticsSyncService::class, fn($m) =>
            $m->shouldReceive('syncSingle')->once()
              ->andReturn(['outcome' => 'synced', 'api_calls' => 1])
        );

        $service = app(ApiFootballMatchUpdateService::class);
        $result  = $service->update($match);

        $this->assertSame('partial', $result['status']);
        $this->assertStringContainsString('lineup', $result['warnings'][0]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeMatch(string $status): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->compId,
            'season_id'      => $this->seasonId,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => now()->subHours(2),
            'status'         => $status,
            'home_score_ft'  => $status === 'finished' ? 1 : null,
            'away_score_ft'  => $status === 'finished' ? 0 : null,
            'definitive_at'  => $status === 'finished' ? now()->subMinutes(30) : null,
        ]);
    }

    private function attachExtId(FootballMatch $match): void
    {
        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => self::FIXTURE_EXT_ID,
        ]);
    }
}
