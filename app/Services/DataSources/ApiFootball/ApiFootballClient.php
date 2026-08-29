<?php

namespace App\Services\DataSources\ApiFootball;

use Illuminate\Support\Facades\Http;

class ApiFootballClient
{
    private function baseUrl(): string
    {
        return rtrim((string) config('api-football.base_url'), '/');
    }

    private function apiKey(): string
    {
        return (string) config('api-football.api_key');
    }

    /**
     * @param  array<string, mixed>  $params
     * @throws ApiFootballException  on missing key, HTTP failure, or API-level error
     */
    public function get(string $endpoint, array $params = []): ApiFootballResponse
    {
        if ($this->apiKey() === '') {
            throw new ApiFootballException('API_FOOTBALL_KEY is not configured');
        }

        $url = $this->baseUrl() . '/' . ltrim($endpoint, '/');

        $httpResponse = Http::withHeaders(['x-apisports-key' => $this->apiKey()])
            ->get($url, $params);

        if ($httpResponse->failed()) {
            throw new ApiFootballException(
                "HTTP {$httpResponse->status()} from API-Football: {$endpoint}"
            );
        }

        $body = $httpResponse->json();

        if (!is_array($body)) {
            throw new ApiFootballException("Non-JSON response from API-Football: {$endpoint}");
        }

        // API-Football signals errors as a non-empty associative array in 'errors'.
        $errors = $body['errors'] ?? [];
        if (!empty($errors)) {
            $detail = is_array($errors)
                ? implode('; ', array_map(
                    static fn($k, $v) => "{$k}: {$v}",
                    array_keys($errors),
                    $errors,
                ))
                : (string) $errors;

            throw new ApiFootballException("API-Football error — {$detail}");
        }

        return new ApiFootballResponse(
            response:          $body['response'] ?? [],
            paging:            $body['paging']   ?? [],
            results:           (int) ($body['results'] ?? 0),
            requestsLimit:     $this->intHeader($httpResponse, 'x-ratelimit-requests-limit'),
            requestsRemaining: $this->intHeader($httpResponse, 'x-ratelimit-requests-remaining'),
            rateLimitLimit:    $this->intHeader($httpResponse, 'X-RateLimit-Limit'),
            rateLimitRemaining:$this->intHeader($httpResponse, 'X-RateLimit-Remaining'),
        );
    }

    private function intHeader(\Illuminate\Http\Client\Response $response, string $name): ?int
    {
        $value = $response->header($name);
        return ($value !== null && $value !== '') ? (int) $value : null;
    }
}
