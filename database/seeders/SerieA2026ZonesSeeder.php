<?php

namespace Database\Seeders;

use App\Models\Competition;
use App\Models\CompetitionSeasonZone;
use App\Models\Season;
use Illuminate\Database\Seeder;

class SerieA2026ZonesSeeder extends Seeder
{
    public function run(): void
    {
        $competition = Competition::where('slug', 'serie-a')->firstOrFail();
        $season      = Season::where('competition_id', $competition->id)
            ->where('year_start', 2026)
            ->firstOrFail();

        // Idempotent: remove existing zones for this season before re-seeding.
        CompetitionSeasonZone::where('season_id', $season->id)->delete();

        $now   = now();
        $zones = [
            [
                'season_id'     => $season->id,
                'from_position' => 1,
                'to_position'   => 1,
                'type'          => 'champion',
                'label'         => "Campione d'Italia",
                'css_class'     => 'zone-champion',
                'color'         => '#f59e0b',
                'status'        => 'confirmed',
                'sort_order'    => 0,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'season_id'     => $season->id,
                'from_position' => 1,
                'to_position'   => 4,
                'type'          => 'champions_league',
                'label'         => 'Champions League',
                'css_class'     => 'zone-ucl',
                'color'         => '#1d4ed8',
                'status'        => 'provisional',
                'sort_order'    => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'season_id'     => $season->id,
                'from_position' => 5,
                'to_position'   => 5,
                'type'          => 'europa_league',
                'label'         => 'Europa League',
                'css_class'     => 'zone-uel',
                'color'         => '#ea580c',
                'status'        => 'provisional',
                'sort_order'    => 2,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'season_id'     => $season->id,
                'from_position' => 6,
                'to_position'   => 6,
                'type'          => 'conference_league',
                'label'         => 'Conference League',
                'css_class'     => 'zone-uecl',
                'color'         => '#16a34a',
                'status'        => 'provisional',
                'sort_order'    => 3,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'season_id'     => $season->id,
                'from_position' => 18,
                'to_position'   => 20,
                'type'          => 'relegation',
                'label'         => 'Retrocessione',
                'css_class'     => 'zone-relegation',
                'color'         => '#dc2626',
                'status'        => 'confirmed',
                'sort_order'    => 4,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
        ];

        CompetitionSeasonZone::insert($zones);

        $this->command->info("Inserted " . count($zones) . " zones for Serie A 2026/27 (season_id={$season->id}).");
    }
}
