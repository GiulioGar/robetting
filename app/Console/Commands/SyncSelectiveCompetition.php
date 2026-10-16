<?php

namespace App\Console\Commands;

use App\Models\CompetitionExternalId;
use App\Models\DataSource;
use App\Services\DataSources\ApiFootball\ApiFootballLeagueImporter;
use App\Services\DataSources\ApiFootball\ApiFootballSelectiveFixtureSyncService;
use Illuminate\Console\Command;

/**
 * Onboards one additional competition SELECTIVELY (today: the 6 national
 * cups) for a season: competition+season metadata, then only the fixtures
 * where a core team is involved — plus only the non-core opponent teams
 * those fixtures actually need (never the full participant list).
 *
 * Deliberately distinct from robetting:sync-additional-competition (UEFA,
 * full import for the Elo Network — see Step A5.1). See Step B1.
 *
 * Only league IDs listed in additional_competitions are accepted. Never
 * touches match statistics, xG, lineups, player statistics, events or
 * injuries.
 */
class SyncSelectiveCompetition extends Command
{
    protected $signature = 'robetting:sync-selective-competition
                            {--league= : League external ID (must be in api-football.additional_competitions)}
                            {--season= : Season year to sync}';

    protected $description = 'Onboard one additional competition SELECTIVELY (e.g. national cups): competition+season, then only fixtures/teams involving a core team';

    public function handle(
        ApiFootballLeagueImporter $leagueImporter,
        ApiFootballSelectiveFixtureSyncService $selectiveSync,
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

        $this->info("[1/2] Importing competition/season metadata for league {$leagueId}, season {$season}…");
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

        $this->info('[2/2] Fetching fixtures and selecting only core-team matches…');
        $result = $selectiveSync->syncCompetition($cei, $season);

        $this->line("  fetched={$result['fetched']}  relevant={$result['relevant']}  teams_created={$result['teams_created']}");
        $this->line("  created={$result['created']}  updated={$result['updated']}  unchanged={$result['unchanged']}  skipped={$result['skipped']}  api_calls={$result['api_calls']}");

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->warn("    {$warning}");
        }

        $this->newLine();
        $this->info('Done. Only fixtures involving a core team were persisted. Match statistics / xG / lineups / player stats / events / injuries were NOT synced.');

        return self::SUCCESS;
    }
}
