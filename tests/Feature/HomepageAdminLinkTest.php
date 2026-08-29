<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageAdminLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
    }

    public function test_admin_link_visible_in_local_env(): void
    {
        $this->app['env'] = 'local';

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Admin API-Football')
            ->assertSee(route('admin.api-football.dashboard'));
    }

    public function test_admin_link_absent_in_non_local_env(): void
    {
        // Default test env is 'testing'
        $this->assertFalse(app()->isLocal());

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Admin API-Football');
    }
}
