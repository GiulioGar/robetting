<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the transfermarkt data_source and maps all 96 Transfermarkt team names
 * to canonical Robetting team IDs via team_external_ids.
 *
 * Safe to re-run: insertOrIgnore on data_sources + team_external_ids.
 *
 * After running:
 *   php artisan db:seed --class=TransfermarktMappingSeeder
 *
 * The admin /admin/structural "Genera & Analizza" button should produce:
 *   mapped: 96 / unmapped: 0 / new_snapshots: 96
 */
class TransfermarktMappingSeeder extends Seeder
{
    /**
     * Map of Transfermarkt external_name → Robetting canonical teams.name.
     * Organized by league for readability; order has no effect on the insert.
     */
    private const MAPPING = [
        // ── Serie A ───────────────────────────────────────────────────────────
        'Inter Milan'          => 'Inter',
        'Juventus FC'          => 'Juventus',
        'Como 1907'            => 'Como',
        'AS Roma'              => 'AS Roma',
        'AC Milan'             => 'AC Milan',
        'Atalanta BC'          => 'Atalanta',
        'SSC Napoli'           => 'Napoli',
        'ACF Fiorentina'       => 'Fiorentina',
        'SS Lazio'             => 'Lazio',
        'Bologna FC 1909'      => 'Bologna',
        'US Sassuolo'          => 'Sassuolo',
        'Torino FC'            => 'Torino',
        'Genoa CFC'            => 'Genoa',
        'Udinese Calcio'       => 'Udinese',
        'Parma Calcio 1913'    => 'Parma',
        'Cagliari Calcio'      => 'Cagliari',
        'Venezia FC'           => 'Venezia',
        'AC Monza'             => 'Monza',
        'Frosinone Calcio'     => 'Frosinone',
        'US Lecce'             => 'Lecce',

        // ── Premier League ────────────────────────────────────────────────────
        'Manchester City'           => 'Manchester City',
        'Arsenal FC'                => 'Arsenal',
        'Chelsea FC'                => 'Chelsea',
        'Liverpool FC'              => 'Liverpool',
        'Manchester United'         => 'Manchester United',
        'Tottenham Hotspur'         => 'Tottenham',
        'Newcastle United'          => 'Newcastle',
        'Brighton & Hove Albion'    => 'Brighton',
        'Brentford FC'              => 'Brentford',
        'Crystal Palace'            => 'Crystal Palace',
        'AFC Bournemouth'           => 'Bournemouth',
        'Aston Villa'               => 'Aston Villa',
        'Nottingham Forest'         => 'Nottingham Forest',
        'Sunderland AFC'            => 'Sunderland',
        'Leeds United'              => 'Leeds',
        'Fulham FC'                 => 'Fulham',
        'Everton FC'                => 'Everton',
        'Ipswich Town'              => 'Ipswich',
        'Coventry City'             => 'Coventry',
        'Hull City'                 => 'Hull City',

        // ── La Liga ───────────────────────────────────────────────────────────
        'Real Madrid'               => 'Real Madrid',
        'FC Barcelona'              => 'Barcelona',
        'Atlético de Madrid'        => 'Atletico Madrid',
        'Villarreal CF'             => 'Villarreal',
        'Real Sociedad'             => 'Real Sociedad',
        'Real Betis Balompié'       => 'Real Betis',
        'Athletic Bilbao'           => 'Athletic Club',
        'Celta de Vigo'             => 'Celta Vigo',
        'Sevilla FC'                => 'Sevilla',
        'Valencia CF'               => 'Valencia',
        'RCD Espanyol Barcelona'    => 'Espanyol',
        'Deportivo A Coruña'        => 'Deportivo La Coruna',
        'Racing Santander'          => 'Racing Santander',
        'Getafe CF'                 => 'Getafe',
        'Levante UD'                => 'Levante',
        'Rayo Vallecano'            => 'Rayo Vallecano',
        'CA Osasuna'                => 'Osasuna',
        'Elche CF'                  => 'Elche',
        'Deportivo Alavés'          => 'Alaves',
        'Málaga CF'                 => 'Malaga',

        // ── Bundesliga ────────────────────────────────────────────────────────
        'Bayern Munich'             => 'Bayern München',
        'Borussia Dortmund'         => 'Borussia Dortmund',
        'RB Leipzig'                => 'RB Leipzig',
        'Bayer 04 Leverkusen'       => 'Bayer Leverkusen',
        'VfB Stuttgart'             => 'VfB Stuttgart',
        'Eintracht Frankfurt'       => 'Eintracht Frankfurt',
        'TSG 1899 Hoffenheim'       => '1899 Hoffenheim',
        'SC Freiburg'               => 'SC Freiburg',
        'FC Augsburg'               => 'FC Augsburg',
        '1.FSV Mainz 05'            => 'FSV Mainz 05',
        '1.FC Köln'                 => '1. FC Köln',
        'Hamburger SV'              => 'Hamburger SV',
        'Borussia Mönchengladbach'  => 'Borussia Mönchengladbach',
        'SV Werder Bremen'          => 'Werder Bremen',
        '1.FC Union Berlin'         => 'Union Berlin',
        'FC Schalke 04'             => 'FC Schalke 04',
        'SV 07 Elversberg'          => 'SV Elversberg',
        'SC Paderborn 07'           => 'SC Paderborn 07',

        // ── Ligue 1 ───────────────────────────────────────────────────────────
        'Paris Saint-Germain'       => 'Paris Saint Germain',
        'AS Monaco'                 => 'Monaco',
        'RC Strasbourg Alsace'      => 'Strasbourg',
        'Olympique Lyon'            => 'Lyon',
        'LOSC Lille'                => 'Lille',
        'Stade Rennais FC'          => 'Rennes',
        'RC Lens'                   => 'Lens',
        'Olympique Marseille'       => 'Marseille',
        'Paris FC'                  => 'Paris FC',
        'OGC Nice'                  => 'Nice',
        'FC Toulouse'               => 'Toulouse',
        'AJ Auxerre'                => 'Auxerre',
        'FC Lorient'                => 'Lorient',
        'Stade Brestois 29'         => 'Stade Brestois 29',
        'Angers SCO'                => 'Angers',
        'Le Havre AC'               => 'Le Havre',
        'ESTAC Troyes'              => 'Estac Troyes',
        'Le Mans FC'                => 'Le Mans',
    ];

    public function run(): void
    {
        // ── 1. Ensure transfermarkt data_source exists ─────────────────────────

        $existing = DB::table('data_sources')->where('slug', 'transfermarkt')->first();

        if ($existing) {
            $dataSourceId = (int) $existing->id;
            $this->command->info("data_source 'transfermarkt' already exists (id={$dataSourceId}).");
        } else {
            $dataSourceId = (int) DB::table('data_sources')->insertGetId([
                'slug'        => 'transfermarkt',
                'name'        => 'Transfermarkt',
                'source_type' => 'scraper',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            $this->command->info("data_source 'transfermarkt' created (id={$dataSourceId}).");
        }

        // ── 2. Resolve canonical team IDs & insert external_ids ───────────────

        $inserted  = 0;
        $skipped   = 0;
        $errors    = [];

        foreach (self::MAPPING as $externalName => $canonicalName) {
            $teamId = DB::table('teams')->where('name', $canonicalName)->value('id');

            if ($teamId === null) {
                $errors[] = "Canonical name not found in teams: '{$canonicalName}' (external: '{$externalName}')";
                continue;
            }

            $affected = DB::table('team_external_ids')->insertOrIgnore([
                'team_id'        => (int) $teamId,
                'data_source_id' => $dataSourceId,
                'external_id'    => $externalName,   // Transfermarkt has no numeric ID; the name is the identifier
                'external_name'  => $externalName,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            if ($affected > 0) {
                $inserted++;
            } else {
                $skipped++;
            }
        }

        // ── 3. Report ─────────────────────────────────────────────────────────

        $this->command->info("team_external_ids: {$inserted} inserted, {$skipped} already existed.");

        if (!empty($errors)) {
            $this->command->error('ERRORS — the following canonical names were not found:');
            foreach ($errors as $err) {
                $this->command->error("  {$err}");
            }
            $this->command->error('Fix the canonical names in MAPPING before running the admin import.');
        } else {
            $this->command->info('All ' . count(self::MAPPING) . ' mappings resolved successfully.');
        }
    }
}
