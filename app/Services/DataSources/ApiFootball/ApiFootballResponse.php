<?php

namespace App\Services\DataSources\ApiFootball;

readonly class ApiFootballResponse
{
    public function __construct(
        public array  $response,
        public array  $paging,
        public int    $results,
        // Daily quota (x-ratelimit-requests-*)
        public ?int   $requestsLimit,
        public ?int   $requestsRemaining,
        // Per-minute quota (X-RateLimit-*)
        public ?int   $rateLimitLimit,
        public ?int   $rateLimitRemaining,
    ) {}
}
