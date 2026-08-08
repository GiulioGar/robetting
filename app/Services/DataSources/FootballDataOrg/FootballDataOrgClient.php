<?php

namespace App\Services\DataSources\FootballDataOrg;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FootballDataOrgClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    private function get(string $path, array $query = []): array
    {
        $response = Http::withHeaders(['X-Auth-Token' => $this->apiKey])
            ->timeout(30)
            ->get($this->baseUrl . $path, $query);

        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?: 60);
            Log::warning("football-data.org: rate limited on {$path}. Waiting {$retryAfter}s before retry.");
            sleep($retryAfter);

            $response = Http::withHeaders(['X-Auth-Token' => $this->apiKey])
                ->timeout(30)
                ->get($this->baseUrl . $path, $query);

            if ($response->status() === 429) {
                throw new RuntimeException(
                    "football-data.org: rate limit persists after retry on {$path}. Try again later."
                );
            }
        }

        $response->throw();

        $data = $response->json();

        if ($data === null) {
            throw new RuntimeException(
                "football-data.org: invalid JSON response for {$path}"
            );
        }

        return $data;
    }

    public function getCompetition(string $code): array
    {
        return $this->get("/competitions/{$code}");
    }

    public function getTeams(string $code, int $season): array
    {
        return $this->get("/competitions/{$code}/teams", ['season' => $season]);
    }

    public function getMatches(string $code, int $season): array
    {
        return $this->get("/competitions/{$code}/matches", ['season' => $season]);
    }
}
