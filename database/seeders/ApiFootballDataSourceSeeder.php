<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;

class ApiFootballDataSourceSeeder extends Seeder
{
    public function run(): void
    {
        DataSource::updateOrCreate(
            ['slug' => 'api-football'],
            [
                'name'                  => 'API-Football',
                'source_type'           => 'api',
                'base_url'              => 'https://v3.football.api-sports.io',
                'rate_limit_per_minute' => 300,
                'rate_limit_per_day'    => 7500,
                'is_active'             => true,
            ],
        );

        $this->command->info('data_sources: api-football registered (idempotent).');
    }
}
