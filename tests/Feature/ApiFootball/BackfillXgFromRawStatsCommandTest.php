<?php

namespace Tests\Feature\ApiFootball;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchStatistic;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillXgFromRawStatsCommandTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private \App\Models\Competition $competition;
    private \App\Models\Season $season;
    private int $matchSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        $country = \App\Models\Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->competition = \App\Models\Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $this->season = \App\Models\Season::create([
            'competition_id' => $this->competition->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // B1: populates xG from raw_stats
    // -------------------------------------------------------------------------

    public function test_populates_xg_from_raw_stats(): void
    {
        $stat = $this->makeStatWithRawStats([
            'home' => ['expected_goals' => '1.08', 'goals_prevented' => '-1.34'],
            'away' => ['expected_goals' => 2.79,   'goals_prevented' => 0.89],
        ]);

        $this->artisan('robetting:backfill-xg-from-raw-stats')->assertExitCode(0);

        $stat->refresh();
        $this->assertEqualsWithDelta(1.08,  $stat->home_expected_goals,  0.001);
        $this->assertEqualsWithDelta(2.79,  $stat->away_expected_goals,  0.001);
        $this->assertEqualsWithDelta(-1.34, $stat->home_goals_prevented, 0.001);
        $this->assertEqualsWithDelta(0.89,  $stat->away_goals_prevented, 0.001);
    }

    // -------------------------------------------------------------------------
    // B2: row without raw_stats (NULL) is not touched
    // -------------------------------------------------------------------------

    public function test_skips_row_without_raw_stats(): void
    {
        $stat = MatchStatistic::create([
            'match_id'       => $this->makeMatchId(),
            'data_source_id' => $this->ds->id,
            'fetched_at'     => now(),
            'home_shots'     => 5,
            'raw_stats'      => null,
        ]);

        $this->artisan('robetting:backfill-xg-from-raw-stats')->assertExitCode(0);

        $stat->refresh();
        $this->assertNull($stat->home_expected_goals);
        $this->assertNull($stat->away_expected_goals);
    }

    // -------------------------------------------------------------------------
    // B3: existing xG not overwritten without --force
    // -------------------------------------------------------------------------

    public function test_skips_existing_xg_without_force(): void
    {
        $stat = $this->makeStatWithRawStats(
            rawStats: ['home' => ['expected_goals' => '3.00'], 'away' => ['expected_goals' => '1.50']],
            existingXg: 9.99,
        );

        $this->artisan('robetting:backfill-xg-from-raw-stats')->assertExitCode(0);

        $stat->refresh();
        $this->assertEqualsWithDelta(9.99, $stat->home_expected_goals, 0.001, 'Existing value must not be overwritten');
    }

    // -------------------------------------------------------------------------
    // B4: --force overwrites existing xG
    // -------------------------------------------------------------------------

    public function test_overwrites_existing_xg_with_force(): void
    {
        $stat = $this->makeStatWithRawStats(
            rawStats: ['home' => ['expected_goals' => '3.00'], 'away' => ['expected_goals' => '1.50']],
            existingXg: 9.99,
        );

        $this->artisan('robetting:backfill-xg-from-raw-stats --force')->assertExitCode(0);

        $stat->refresh();
        $this->assertEqualsWithDelta(3.00, $stat->home_expected_goals, 0.001, '--force must overwrite');
        $this->assertEqualsWithDelta(1.50, $stat->away_expected_goals, 0.001);
    }

    // -------------------------------------------------------------------------
    // B5: second run without --force → unchanged = 1, updated = 0
    // -------------------------------------------------------------------------

    public function test_idempotent_second_run(): void
    {
        $this->makeStatWithRawStats([
            'home' => ['expected_goals' => '1.08'],
            'away' => ['expected_goals' => 2.79],
        ]);

        $this->artisan('robetting:backfill-xg-from-raw-stats')->assertExitCode(0);

        // Second run: xG already populated → unchanged
        $this->artisan('robetting:backfill-xg-from-raw-stats')
            ->assertExitCode(0)
            ->expectsOutputToContain('0'); // updated = 0 in the table
    }

    // -------------------------------------------------------------------------
    // B6: raw_stats without xG (Bundesliga) → missing_xg counter incremented
    // -------------------------------------------------------------------------

    public function test_missing_xg_in_raw_stats_counted(): void
    {
        // Bundesliga-style raw_stats: no expected_goals at all
        $this->makeStatWithRawStats([
            'home' => ['Total Shots' => 5, 'Free Kicks' => 8],
            'away' => ['Total Shots' => 12, 'Free Kicks' => 9],
        ]);

        $result = app(\App\Services\DataSources\ApiFootball\XgRawStatsBackfillService::class)->backfill();

        $this->assertSame(1, $result['missing_xg']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['unchanged']); // nothing to write
    }

    // -------------------------------------------------------------------------
    // B7: raw_stats has xG but no goals_prevented → missing_goals_prevented counted
    // -------------------------------------------------------------------------

    public function test_missing_goals_prevented_counted(): void
    {
        $this->makeStatWithRawStats([
            'home' => ['expected_goals' => '1.08'],
            'away' => ['expected_goals' => 2.79],
            // no goals_prevented key
        ]);

        $result = app(\App\Services\DataSources\ApiFootball\XgRawStatsBackfillService::class)->backfill();

        $this->assertSame(0, $result['missing_xg']);
        $this->assertSame(1, $result['missing_goals_prevented']);
        $this->assertSame(1, $result['updated']);
    }

    // -------------------------------------------------------------------------
    // B8: counters correct for mixed batch (2 rows: one with xG, one without)
    // -------------------------------------------------------------------------

    public function test_counters_correct_for_mixed_batch(): void
    {
        // Row 1: has xG
        $this->makeStatWithRawStats([
            'home' => ['expected_goals' => '1.08', 'goals_prevented' => '-1.34'],
            'away' => ['expected_goals' => 2.79,   'goals_prevented' => 0.89],
        ]);
        // Row 2: no xG (Bundesliga)
        $this->makeStatWithRawStats([
            'home' => ['Total Shots' => 5],
            'away' => ['Total Shots' => 12],
        ]);

        $result = app(\App\Services\DataSources\ApiFootball\XgRawStatsBackfillService::class)->backfill();

        $this->assertSame(2, $result['scanned']);
        $this->assertSame(1, $result['updated']);        // only row1 got xG written
        $this->assertSame(1, $result['unchanged']);      // row2: nothing to write
        $this->assertSame(1, $result['missing_xg']);
        $this->assertSame(1, $result['missing_goals_prevented']); // row2 has no goals_prevented either
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeMatchId(): int
    {
        $home = \App\Models\Team::create(['name' => 'Home ' . (++$this->matchSeq), 'type' => 'club', 'is_active' => true]);
        $away = \App\Models\Team::create(['name' => 'Away ' . $this->matchSeq, 'type' => 'club', 'is_active' => true]);

        return FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $home->id,
            'away_team_id'   => $away->id,
            'kickoff_at'     => now()->subDay(),
            'status'         => 'finished',
        ])->id;
    }

    /** Create a MatchStatistic with raw_stats JSON and optional pre-existing xG. */
    private function makeStatWithRawStats(array $rawStats, ?float $existingXg = null): MatchStatistic
    {
        return MatchStatistic::create([
            'match_id'            => $this->makeMatchId(),
            'data_source_id'      => $this->ds->id,
            'fetched_at'          => now(),
            'raw_stats'           => $rawStats,
            'home_expected_goals' => $existingXg,
            'away_expected_goals' => $existingXg,
        ]);
    }
}
