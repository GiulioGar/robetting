<?php

namespace App\Services\DataSources\Highlightly;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HighlightlyClient
{
    private const API_HOST    = 'football-highlights-api.p.rapidapi.com';
    private const MAX_RETRIES = 2;

    private ?int $lastRemainingQuota = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://soccer.highlightly.net',
    ) {}

    public function getLastRemainingQuota(): ?int
    {
        return $this->lastRemainingQuota;
    }

    /**
     * GET /matches?leagueId={id}&date={YYYY-MM-DD}&season={year}
     * Returns the "data" array from the response, or [] on failure.
     */
    public function getMatches(int $leagueId, string $date, int $season): array
    {
        $response = $this->get('matches', [
            'leagueId' => $leagueId,
            'date'     => $date,
            'season'   => $season,
        ]);

        return $response['data'] ?? [];
    }

    /**
     * GET /statistics/{matchId}
     * Returns a 2-element array [homeTeamStats, awayTeamStats], or [] on failure.
     */
    public function getStatistics(string|int $matchId): array
    {
        return $this->get("statistics/{$matchId}");
    }

    private function get(string $path, array $query = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders([
                        'x-rapidapi-key'  => $this->apiKey,
                        'x-rapidapi-host' => self::API_HOST,
                        'Accept'          => 'application/json',
                    ])
                    ->get($url, $query);

                $remaining = $response->header('x-ratelimit-requests-remaining');
                if ($remaining !== null && $remaining !== '') {
                    $this->lastRemainingQuota = (int) $remaining;
                }

                if ($response->successful()) {
                    return $response->json() ?? [];
                }

                if ($response->status() === 429) {
                    Log::warning('highlightly: rate limited', ['path' => $path, 'attempt' => $attempt]);
                    sleep(30);
                    continue;
                }

                Log::error('highlightly: HTTP error', [
                    'path'    => $path,
                    'status'  => $response->status(),
                    'attempt' => $attempt,
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    sleep($attempt * 3);
                    continue;
                }

                return [];

            } catch (\Throwable $e) {
                Log::warning('highlightly: request exception', [
                    'path'      => $path,
                    'attempt'   => $attempt,
                    'exception' => $e->getMessage(),
                ]);
                if ($attempt < self::MAX_RETRIES) {
                    sleep($attempt * 3);
                }
            }
        }

        return [];
    }
}
