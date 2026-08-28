<?php

namespace App\Services\DataSources\GoalApi;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoalApiClient
{
    private const MAX_RETRIES = 3;

    private ?int $lastRemainingQuota = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.goal-api.com/v1',
    ) {}

    /** Returns the X-RateLimit-Remaining value from the last successful response, or null. */
    public function getLastRemainingQuota(): ?int
    {
        return $this->lastRemainingQuota;
    }

    /**
     * GET /fixtures/{fixtureId} — full fixture detail including statistics.
     * Response: {"success":true,"data":{...fixture with statistics...}}
     */
    public function getFixture(string $fixtureId): array
    {
        return $this->get("fixtures/{$fixtureId}");
    }

    /**
     * Fetches all fixtures for a league, handling pagination automatically.
     * Returns a flat array of fixture objects (up to $maxFixtures total).
     */
    public function getAllLeagueFixtures(string $leagueId, int $maxFixtures = 2000): array
    {
        $all    = [];
        $offset = 0;
        $limit  = 100;

        do {
            $response = $this->get("leagues/{$leagueId}/fixtures", [
                'limit'  => $limit,
                'offset' => $offset,
            ]);

            // Response: {"success":true,"data":[...fixtures...],"pagination":{"total":N,"hasMore":bool}}
            $fixtures = $response['data'] ?? [];
            $all      = array_merge($all, $fixtures);

            $hasMore = $response['pagination']['hasMore'] ?? false;
            $offset += $limit;

        } while ($hasMore && count($all) < $maxFixtures);

        return $all;
    }

    private function get(string $path, array $query = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = Http::timeout(10)
                    ->withHeaders(['Authorization' => "Bearer {$this->apiKey}"])
                    ->get($url, $query);

                if ($response->successful()) {
                    $remaining = $response->header('X-RateLimit-Remaining');
                    if ($remaining !== null && $remaining !== '') {
                        $this->lastRemainingQuota = (int) $remaining;
                    }
                    return $response->json() ?? [];
                }

                if ($response->status() === 429) {
                    Log::warning('goal-api: rate limited, waiting 15s', ['path' => $path, 'attempt' => $attempt]);
                    sleep(15);
                    continue;
                }

                if ($response->status() === 502 && $attempt < self::MAX_RETRIES) {
                    Log::warning('goal-api: 502, retrying', ['path' => $path, 'attempt' => $attempt]);
                    sleep($attempt * 2);
                    continue;
                }

                Log::error('goal-api: HTTP error', [
                    'path'    => $path,
                    'status'  => $response->status(),
                    'attempt' => $attempt,
                ]);
                return [];

            } catch (\Throwable $e) {
                Log::warning('goal-api: request exception', [
                    'path'      => $path,
                    'attempt'   => $attempt,
                    'exception' => $e->getMessage(),
                ]);
                if ($attempt < self::MAX_RETRIES) {
                    sleep($attempt * 2);
                }
            }
        }

        return [];
    }
}
