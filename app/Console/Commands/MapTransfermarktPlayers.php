<?php

namespace App\Console\Commands;

use App\Services\DataSources\Transfermarkt\TransfermarktPlayerMappingService;
use Illuminate\Console\Command;

class MapTransfermarktPlayers extends Command
{
    protected $signature = 'robetting:map-transfermarkt-players {--apply : Persist the safe mappings. Without this flag, the command only reports what it would do.}';

    protected $description = 'Map Transfermarkt player IDs (structural_players_current.json) to canonical players.id via the API-Football current squad endpoint. Dry-run by default.';

    public function handle(TransfermarktPlayerMappingService $service): int
    {
        $apply = (bool) $this->option('apply');

        $this->info($apply
            ? 'Applying Transfermarkt player mapping (writes to player_external_ids) …'
            : 'Dry-run: Transfermarkt player mapping (no writes) …');

        $result = $service->run($apply);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Teams processed',              $result['teams_processed']],
                ['API calls',                    $result['api_calls']],
                ['Safe (unique candidate)',      $result['safe']],
                ['Ambiguous (skipped)',          $result['ambiguous']],
                ['Unmatched (skipped)',          $result['unmatched']],
                ['No canonical player (skipped)',$result['no_canonical_player']],
                ['Already mapped',               $result['already_mapped']],
                [$apply ? 'Created' : 'Would create', $result['created']],
                ['Conflicts (skipped)',          $result['conflicts']],
                ['Warnings',                     count($result['warnings'])],
            ],
        );

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        foreach ($result['conflict_examples'] as $conflict) {
            $this->warn('conflict: ' . json_encode($conflict, JSON_UNESCAPED_UNICODE));
        }

        return Command::SUCCESS;
    }
}
