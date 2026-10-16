<?php

namespace App\Console\Commands;

use App\Models\CompetitionExternalId;
use App\Models\DataSource;
use App\Services\DataSources\ApiFootball\ApiFootballFixtureSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballLeagueImporter;
use App\Services\DataSources\ApiFootball\ApiFootballTeamSyncService;
use Illuminate\Console\Command;

/**
 * Onboards one additional (non-core) competition — currently the 3 UEFA club
 * competitions, see config('api-football.additional_competitions') — for a
 * single season: competition/season metadata, then ALL teams, then ALL
 * fixtures, strictly in this order (ApiFootballFixtureSyncService requires
 * teams already mapped — see robetting-portale/A1-A2 audit).
 *
 * Only league IDs listed in additional_competitions are accepted — this is
 * not a generic "sync any league" tool. Never touches match statistics, xG,
 * lineups, player statistics, events or injuries.
 */
class SyncAdditionalCompetition extends Command
{
    protected $signature = 'robetting:sync-additional-competition
                            {--league= : League external ID (must be in api-football.additional_competitions)}
                            {--season= : Season year to sync}';

    protected $description = 'Onboard one additional competition (e.g. UEFA) for a season: competition+season, teams, fixtures only — no advanced data';

    public function handle(
        ApiFootballLeagueImporter $leagueImporter,
        ApiFootballTeamSyncService $teamSync,
        ApiFootballFixtureSyncService $fixtureSync,
    ): int {
        $leagueId = (int) $this->option('league');
        $season   = (int) $this->option('season');

        if ($leagueId === 0 || $season === 0) {
            $this->error('Both --league and --season are required.');
            return self::FAILURE;
        }

        $additional = (array) config('api-football.additional_competitions', []);
        if (!isset($additional[$leagueId])) {
            $this->error("League {$leagueId} is not configured in api-football.additional_competitions — refusing arbitrary league ID.");
            return self::FAILURE;
        }

        // 1. Competition + season mapping
        $this->info("[1/3] Importing competition/season metadata for league {$leagueId}, season {$season}…");
        $leagueReport = $leagueImporter->importLeague($leagueId, $season);
        if ($leagueReport['status'] !== 'ok') {
            $this->error('League import failed/skipped: ' . ($leagueReport['message'] ?? $leagueReport['status']));
            return self::FAILURE;
        }
        $this->line("  slug={$leagueReport['slug']}  season={$leagueReport['season']}");

        $ds  = DataSource::where('slug', 'api-football')->firstOrFail();
        $cei = CompetitionExternalId::where('data_source_id', $ds->id)
            ->where('external_id', (string) $leagueId)
            ->first();

        if (!$cei) {
            $this->error('competition_external_id not found after import — aborting.');
            return self::FAILURE;
        }

        // 2. Teams — ALL teams in the competition+season, required before fixture sync.
        $this->info('[2/3] Syncing teams…');
        $teamReport = $teamSync->syncCompetition($cei, $season);
        $this->line("  created={$teamReport['created']}  updated={$teamReport['updated']}  unchanged={$teamReport['unchanged']}");

        // 3. Fixtures — ALL fixtures, needed for the Elo Network (and future Team History).
        $this->info('[3/3] Syncing fixtures…');
        $fixtureReport = $fixtureSync->syncCompetition($cei, $season, ApiFootballFixtureSyncService::MODE_FULL);
        $this->line("  created={$fixtureReport['created']}  updated={$fixtureReport['updated']}  unchanged={$fixtureReport['unchanged']}  skipped={$fixtureReport['skipped']}");

        foreach ($fixtureReport['warnings'] ?? [] as $warning) {
            $this->warn("    {$warning}");
        }

        $this->newLine();
        $this->info('Done. Match statistics / xG / lineups / player stats / events / injuries were NOT synced (out of scope for this step).');

        return self::SUCCESS;
    }
}
