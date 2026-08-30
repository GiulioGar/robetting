<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballMatchUpdateService;
use Illuminate\Console\Command;

class UpdateApiFootballMatch extends Command
{
    protected $signature   = 'robetting:update-match {fixture_external_id : API-Football fixture ID}';
    protected $description = 'Manually update all available API-Football data for a single match (result, lineup, events, statistics)';

    public function handle(ApiFootballMatchUpdateService $service): int
    {
        $extId = (string) $this->argument('fixture_external_id');

        $ds = DataSource::where('slug', 'api-football')->first();
        if (!$ds) {
            $this->error('api-football data source not found.');
            return self::FAILURE;
        }

        $matchExternalId = MatchExternalId::where('data_source_id', $ds->id)
            ->where('external_id', $extId)
            ->first();

        if (!$matchExternalId) {
            $this->error("No match found with api-football external ID: {$extId}");
            return self::FAILURE;
        }

        $match = FootballMatch::find($matchExternalId->match_id);
        if (!$match) {
            $this->error("Match ID {$matchExternalId->match_id} not found in database.");
            return self::FAILURE;
        }

        $this->line("Updating match #{$match->id} (fixture {$extId}) — status: {$match->status}");

        $result = $service->update($match);

        $this->table(
            ['Component', 'Outcome', 'API calls'],
            [
                ['result',     $result['result']['outcome']     ?? '–', $result['result']['api_calls']     ?? 0],
                ['lineup',     $result['lineup']['outcome']     ?? '–', $result['lineup']['api_calls']     ?? 0],
                ['events',     $result['events']['outcome']     ?? '–', $result['events']['api_calls']     ?? 0],
                ['statistics', $result['statistics']['outcome'] ?? '–', $result['statistics']['api_calls'] ?? 0],
            ]
        );

        $this->line("Total API calls: {$result['api_calls']}");
        $this->line("Status: {$result['status']}");

        if (!empty($result['warnings'])) {
            $this->warn('Warnings:');
            foreach ($result['warnings'] as $w) {
                $this->warn("  · {$w}");
            }
        }

        return $result['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
