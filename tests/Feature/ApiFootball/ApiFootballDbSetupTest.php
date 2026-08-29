<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\Season;
use App\Models\SeasonExternalId;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiFootballDbSetupTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Migration: coverage column + cast
    // -------------------------------------------------------------------------

    public function test_coverage_column_accepts_and_casts_array(): void
    {
        $ds          = DataSource::create(['slug' => 'test-src', 'name' => 'T', 'source_type' => 'api']);
        $country     = Country::create(['name' => 'Italy', 'iso_code_alpha2' => 'IT']);
        $competition = Competition::create(['name' => 'Serie A', 'slug' => 'serie-a', 'country_id' => $country->id]);
        $season      = Season::create([
            'competition_id' => $competition->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
        ]);

        $coverage = [
            'fixtures'  => ['events' => true, 'lineups' => true, 'statistics_fixtures' => true],
            'standings' => true,
            'players'   => true,
        ];

        SeasonExternalId::create([
            'season_id'      => $season->id,
            'competition_id' => $competition->id,
            'data_source_id' => $ds->id,
            'external_id'    => '2026',
            'coverage'       => $coverage,
        ]);

        $fresh = SeasonExternalId::first();

        $this->assertIsArray($fresh->coverage);
        $this->assertTrue($fresh->coverage['standings']);
        $this->assertTrue($fresh->coverage['fixtures']['events']);
    }

    public function test_coverage_nullable(): void
    {
        $ds          = DataSource::create(['slug' => 'test-src', 'name' => 'T', 'source_type' => 'api']);
        $country     = Country::create(['name' => 'Italy', 'iso_code_alpha2' => 'IT']);
        $competition = Competition::create(['name' => 'Serie A', 'slug' => 'serie-a', 'country_id' => $country->id]);
        $season      = Season::create([
            'competition_id' => $competition->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
        ]);

        SeasonExternalId::create([
            'season_id'      => $season->id,
            'competition_id' => $competition->id,
            'data_source_id' => $ds->id,
            'external_id'    => '2026',
        ]);

        $this->assertNull(SeasonExternalId::first()->coverage);
    }

    // -------------------------------------------------------------------------
    // Seeder: data_source created + idempotent
    // -------------------------------------------------------------------------

    public function test_seeder_creates_api_football_data_source(): void
    {
        $this->seed(ApiFootballDataSourceSeeder::class);

        $ds = DataSource::where('slug', 'api-football')->first();

        $this->assertNotNull($ds);
        $this->assertSame('API-Football', $ds->name);
        $this->assertSame('api', $ds->source_type);
        $this->assertSame('https://v3.football.api-sports.io', $ds->base_url);
        $this->assertSame(300, $ds->rate_limit_per_minute);
        $this->assertSame(7500, $ds->rate_limit_per_day);
        $this->assertTrue($ds->is_active);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->seed(ApiFootballDataSourceSeeder::class);

        $this->assertSame(1, DataSource::where('slug', 'api-football')->count());
    }
}
