<?php

namespace Tests\Unit\Services\DataSources\ApiFootball;

use App\Services\DataSources\ApiFootball\ApiFootballClient;
use App\Services\DataSources\ApiFootball\ApiFootballException;
use App\Services\DataSources\ApiFootball\ApiFootballResponse;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiFootballClientTest extends TestCase
{
    private const BASE = 'https://v3.football.api-sports.io';
    private const KEY  = 'test-api-key-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['api-football.api_key'  => self::KEY]);
        config(['api-football.base_url' => self::BASE]);
    }

    // -------------------------------------------------------------------------
    // 1. URL + authentication
    // -------------------------------------------------------------------------

    public function test_request_goes_to_correct_url_with_auth_header(): void
    {
        Http::fake([
            self::BASE . '/status' => Http::response($this->okBody(), 200),
        ]);

        (new ApiFootballClient())->get('status');

        Http::assertSent(function ($request) {
            return $request->url()                        === self::BASE . '/status'
                && $request->header('x-apisports-key')[0] === self::KEY;
        });
    }

    public function test_query_params_are_forwarded(): void
    {
        Http::fake([
            self::BASE . '/fixtures*' => Http::response($this->okBody(), 200),
        ]);

        (new ApiFootballClient())->get('fixtures', ['league' => 135, 'season' => 2026]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'league=135')
                && str_contains($request->url(), 'season=2026');
        });
    }

    // -------------------------------------------------------------------------
    // 2. Valid response parsed correctly
    // -------------------------------------------------------------------------

    public function test_valid_response_is_parsed_into_dto(): void
    {
        $body = [
            'get'      => 'status',
            'errors'   => [],
            'results'  => 1,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [['account' => ['firstname' => 'Test']]],
        ];

        Http::fake([self::BASE . '/status' => Http::response($body, 200)]);

        $result = (new ApiFootballClient())->get('status');

        $this->assertInstanceOf(ApiFootballResponse::class, $result);
        $this->assertSame(1, $result->results);
        $this->assertSame(['current' => 1, 'total' => 1], $result->paging);
        $this->assertCount(1, $result->response);
        $this->assertSame('Test', $result->response[0]['account']['firstname']);
    }

    // -------------------------------------------------------------------------
    // 3. Rate-limit headers acquired
    // -------------------------------------------------------------------------

    public function test_all_rate_limit_headers_are_captured(): void
    {
        Http::fake([
            self::BASE . '/status' => Http::response($this->okBody(), 200, [
                'x-ratelimit-requests-limit'     => '100',
                'x-ratelimit-requests-remaining' => '87',
                'X-RateLimit-Limit'              => '30',
                'X-RateLimit-Remaining'          => '29',
            ]),
        ]);

        $result = (new ApiFootballClient())->get('status');

        $this->assertSame(100, $result->requestsLimit);
        $this->assertSame(87,  $result->requestsRemaining);
        $this->assertSame(30,  $result->rateLimitLimit);
        $this->assertSame(29,  $result->rateLimitRemaining);
    }

    public function test_missing_rate_limit_headers_are_null(): void
    {
        Http::fake([self::BASE . '/status' => Http::response($this->okBody(), 200)]);

        $result = (new ApiFootballClient())->get('status');

        $this->assertNull($result->requestsLimit);
        $this->assertNull($result->requestsRemaining);
        $this->assertNull($result->rateLimitLimit);
        $this->assertNull($result->rateLimitRemaining);
    }

    // -------------------------------------------------------------------------
    // 4. API-level errors
    // -------------------------------------------------------------------------

    public function test_api_errors_field_throws_exception(): void
    {
        $body = [
            'errors'   => ['plan' => 'Free Plan does not support this endpoint'],
            'results'  => 0,
            'paging'   => [],
            'response' => [],
        ];

        Http::fake([self::BASE . '/fixtures' => Http::response($body, 200)]);

        $this->expectException(ApiFootballException::class);
        $this->expectExceptionMessageMatches('/Free Plan/');

        (new ApiFootballClient())->get('fixtures');
    }

    // -------------------------------------------------------------------------
    // 5. HTTP failure
    // -------------------------------------------------------------------------

    public function test_http_server_error_throws_exception(): void
    {
        Http::fake([self::BASE . '/status' => Http::response('', 500)]);

        $this->expectException(ApiFootballException::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        (new ApiFootballClient())->get('status');
    }

    public function test_http_unauthorized_throws_exception(): void
    {
        Http::fake([self::BASE . '/status' => Http::response('', 401)]);

        $this->expectException(ApiFootballException::class);
        $this->expectExceptionMessageMatches('/HTTP 401/');

        (new ApiFootballClient())->get('status');
    }

    // -------------------------------------------------------------------------
    // 6. Missing API key
    // -------------------------------------------------------------------------

    public function test_missing_key_throws_before_any_http_request(): void
    {
        config(['api-football.api_key' => '']);
        Http::fake();

        $this->expectException(ApiFootballException::class);
        $this->expectExceptionMessageMatches('/API_FOOTBALL_KEY/');

        (new ApiFootballClient())->get('status');

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function okBody(): array
    {
        return [
            'errors'   => [],
            'results'  => 0,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [],
        ];
    }
}
