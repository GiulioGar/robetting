<?php

namespace App\Services\DataSources\FootballDataCoUk;

use Illuminate\Support\Facades\DB;

final class ResolveResult
{
    public function __construct(
        public readonly bool    $resolved,
        public readonly ?int    $teamId,
        public readonly string  $csvName,
        public readonly string  $reason,   // 'external_id' | 'alias' | 'exact_name' | 'unresolved'
        public readonly ?string $detail = null,
    ) {}
}

class TeamResolver
{
    public function __construct(
        private readonly array  $aliases,
        private readonly string $sourceSlug,
    ) {}

    /**
     * Resolves all unique CSV team names to canonical DB team IDs.
     * Read-only: never writes to the database.
     *
     * @param  string[]  $csvNames  Already trimmed, unique team names
     * @return array<string, ResolveResult>  Keyed by csvName
     */
    public function resolveAll(array $csvNames): array
    {
        $source   = DB::table('data_sources')->where('slug', $this->sourceSlug)->first();
        $sourceId = $source?->id;

        $results = [];
        foreach ($csvNames as $csvName) {
            $results[$csvName] = $this->resolve($csvName, $sourceId);
        }

        return $results;
    }

    private function resolve(string $csvName, ?int $sourceId): ResolveResult
    {
        // Step 1: existing team_external_ids mapping (skipped if source not yet in DB)
        if ($sourceId !== null) {
            $extId = DB::table('team_external_ids')
                ->where('data_source_id', $sourceId)
                ->where('external_id', $csvName)
                ->first();

            if ($extId) {
                return new ResolveResult(true, $extId->team_id, $csvName, 'external_id');
            }
        }

        // Step 2: alias file → canonical teams.name lookup
        $canonicalName = $this->aliases[$csvName] ?? null;

        if ($canonicalName !== null) {
            $team = DB::table('teams')->where('name', $canonicalName)->first();

            if ($team) {
                return new ResolveResult(true, $team->id, $csvName, 'alias');
            }

            return new ResolveResult(
                false, null, $csvName, 'unresolved',
                "alias '{$csvName}' → '{$canonicalName}' but team not found in DB",
            );
        }

        // Step 3: exact match on teams.name
        $team = DB::table('teams')->where('name', $csvName)->first();

        if ($team) {
            return new ResolveResult(true, $team->id, $csvName, 'exact_name');
        }

        return new ResolveResult(
            false, null, $csvName, 'unresolved',
            "no alias and no exact DB match for '{$csvName}'",
        );
    }
}
