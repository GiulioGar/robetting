<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\CompetitionExternalId;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\SeasonExternalId;
use App\Models\Team;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballResultRefreshService;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for definitive_at tracking and its role as the grace-period anchor in syncPending().
 *
 * Problem with kickoff_at + 90 as the sole criterion:
 *   A match with ET/penalties may last 120+ minutes. If syncPending() used kickoff_at + 90,
 *   it would treat the match as past the grace window while the stats API hasn't processed
 *   the result yet, leading to a permanent [] response being stored as fetched_at.
 *
 * Solution:
 *   definitive_at = the moment Robetting first detects the non-definitive → definitive transition.
 *   syncPending() waits gracePeriodMinutes from definitive_at, NOT from kickoff_at.
 *   This guarantees the grace period is measured from the real end of the match, regardless
 *   of whether it went to ET or penalties.
 *
 * Legacy policy (definitive_at IS NULL):
 *   Matches that became definitive before this column existed have definitive_at = NULL.
 *   syncPending() falls back to kickoff_at + 90 + grace as an approximation.
 *   These matches are past their kickoff by many hours/days, so the approximation is safe.
 *   New matches always get definitive_at set on first detection and are handled precisely.
 */
class ApiFootballDefinitiveAtStatsTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $competition;
    private Season $season;
    private Team $homeTeam;
    private Team $awayTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);

        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);

        $this->competition = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
            'is_active'  => true,
        ]);

        $this->season = Season::create([
            'competition_id' => $this->competition->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);

        CompetitionExternalId::create([
            'competition_id' => $this->competition->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '135',
            'external_name'  => 'Serie A',
        ]);

        SeasonExternalId::create([
            'season_id'      => $this->season->id,
            'competition_id' => $this->competition->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '2026',
        ]);

        $this->homeTeam = Team::create(['name' => 'Home FC', 'type' => 'club', 'is_active' => true]);
        $this->awayTeam = Team::create(['name' => 'Away FC', 'type' => 'club', 'is_active' => true]);
    }

    // -------------------------------------------------------------------------
    // 1. live → FT: ResultRefreshService imposta definitive_at
    // -------------------------------------------------------------------------

    public function test_live_to_ft_sets_definitive_at(): void
    {
        $match = $this->makeMatchWithExtId(now()->subMinutes(95), 'live', 9701);

        Http::fake([
            '*fixtures*' => Http::response($this->ftFixtureResponse(9701), 200),
        ]);

        app(ApiFootballResultRefreshService::class)->refresh();

        $match->refresh();
        $this->assertSame('finished', $match->status);
        $this->assertNotNull($match->definitive_at, 'definitive_at deve essere valorizzato alla prima transizione definitiva');
    }

    // -------------------------------------------------------------------------
    // 2. Refresh successivo FT: definitive_at NON viene sovrascritto
    // -------------------------------------------------------------------------

    public function test_subsequent_refresh_does_not_overwrite_definitive_at(): void
    {
        // Unusual state: live match that already has definitive_at (edge case / re-processing).
        $existingDefinitiveAt = now()->subMinutes(5)->startOfSecond();
        $match = $this->makeMatchWithExtId(now()->subMinutes(95), 'live', 9702);
        $match->update(['definitive_at' => $existingDefinitiveAt]);

        Http::fake([
            '*fixtures*' => Http::response($this->ftFixtureResponse(9702), 200),
        ]);

        app(ApiFootballResultRefreshService::class)->refresh();

        $match->refresh();
        $this->assertSame('finished', $match->status);
        $this->assertSame(
            $existingDefinitiveAt->format('Y-m-d H:i:s'),
            $match->definitive_at->format('Y-m-d H:i:s'),
            'definitive_at non deve essere sovrascritto una volta valorizzato',
        );
    }

    // -------------------------------------------------------------------------
    // 3. FT appena rilevato (definitive_at = now()) → stats NON candidate
    // -------------------------------------------------------------------------

    public function test_ft_just_detected_stats_not_candidate(): void
    {
        // definitive_at = now(): too recent, not yet past the grace period.
        $this->makeDefinitiveWithExtId(
            kickoff:       now()->subMinutes(95),
            definitiveAt:  now(),
            extId:         9703,
        );

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncPending(gracePeriodMinutes: 10);

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 4. Dopo 10 minuti da definitive_at → candidate per stats
    // -------------------------------------------------------------------------

    public function test_after_grace_period_from_definitive_at_match_is_candidate(): void
    {
        // definitive_at 11 minutes ago → past the 10-minute grace window.
        $match = $this->makeDefinitiveWithExtId(
            kickoff:      now()->subMinutes(106),
            definitiveAt: now()->subMinutes(11),
            extId:        9704,
        );

        Http::fake([
            '*fixtures/statistics*' => Http::response($this->statsResponse(), 200),
        ]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncPending(gracePeriodMinutes: 10);

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['synced']);

        $stat = MatchStatistic::where('match_id', $match->id)
            ->where('data_source_id', $this->ds->id)
            ->first();

        $this->assertNotNull($stat);
        $this->assertNotNull($stat->fetched_at);
    }

    // -------------------------------------------------------------------------
    // 5. ET/rigori appena terminati → NON candidate anche con kickoff vecchio
    //    Prova che il criterio è definitive_at, NON kickoff_at + 90.
    // -------------------------------------------------------------------------

    public function test_et_penalties_match_just_detected_not_candidate_despite_old_kickoff(): void
    {
        // kickoff 155 min ago (typical ET/penalties match): with the old criterion
        // kickoff_at + 90 + grace = 155 - 100 = 55 min in the past → would be a candidate.
        // With the new criterion (definitive_at = now()), it must NOT be a candidate.
        $this->makeDefinitiveWithExtId(
            kickoff:      now()->subMinutes(155),
            definitiveAt: now(),
            extId:        9705,
        );

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncPending(gracePeriodMinutes: 10);

        $this->assertSame(0, $result['candidates'], 'Un match ET/rigori appena terminato non deve essere candidato');
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 6. HTTP stats failure → fetched_at non impostato → resta candidato
    // -------------------------------------------------------------------------

    public function test_http_stats_failure_match_retryable_on_next_cycle(): void
    {
        $match = $this->makeDefinitiveWithExtId(
            kickoff:      now()->subMinutes(106),
            definitiveAt: now()->subMinutes(11),
            extId:        9706,
        );

        Http::fake([
            '*fixtures/statistics*' => Http::sequence()
                ->push([], 500)
                ->push($this->statsResponse(), 200),
        ]);

        $service = app(ApiFootballMatchStatisticsSyncService::class);

        // First cycle: 500 → failed, fetched_at null.
        $result1 = $service->syncPending(gracePeriodMinutes: 10);
        $this->assertSame(1, $result1['failed']);
        $this->assertSame(0, $result1['synced']);
        $this->assertNull(
            MatchStatistic::where('match_id', $match->id)->value('fetched_at'),
            'HTTP failure non deve impostare fetched_at',
        );

        // Second cycle: 200 → synced.
        $result2 = $service->syncPending(gracePeriodMinutes: 10);
        $this->assertSame(1, $result2['candidates']);
        $this->assertSame(1, $result2['synced']);
        $this->assertNotNull(
            MatchStatistic::where('match_id', $match->id)->value('fetched_at'),
        );
    }

    // -------------------------------------------------------------------------
    // 7. fetched_at già presente → zero API call
    // -------------------------------------------------------------------------

    public function test_fetched_at_present_zero_api_calls(): void
    {
        $match = $this->makeDefinitiveWithExtId(
            kickoff:      now()->subMinutes(106),
            definitiveAt: now()->subMinutes(11),
            extId:        9707,
        );

        MatchStatistic::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'fetched_at'     => now()->subMinutes(5),
        ]);

        Http::fake();

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncPending(gracePeriodMinutes: 10);

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Non-definitive match (for ResultRefreshService tests). */
    private function makeMatchWithExtId(mixed $kickoffAt, string $status, int $extId): FootballMatch
    {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => $kickoffAt,
            'status'         => $status,
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => (string) $extId,
            'external_name'  => null,
        ]);

        return $match;
    }

    /** Definitive match with explicit definitive_at (for syncPending tests). */
    private function makeDefinitiveWithExtId(
        mixed $kickoff,
        mixed $definitiveAt,
        int   $extId,
    ): FootballMatch {
        $match = FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->homeTeam->id,
            'away_team_id'   => $this->awayTeam->id,
            'kickoff_at'     => $kickoff,
            'status'         => 'finished',
            'definitive_at'  => $definitiveAt,
        ]);

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => (string) $extId,
            'external_name'  => null,
        ]);

        return $match;
    }

    private function ftFixtureResponse(int $extId): array
    {
        return [
            'errors'   => [],
            'results'  => 1,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [[
                'fixture' => [
                    'id'       => $extId,
                    'timezone' => 'UTC',
                    'date'     => now()->subHours(2)->toIso8601String(),
                    'status'   => ['short' => 'FT', 'elapsed' => 90],
                    'venue'    => ['name' => 'Test Stadium'],
                ],
                'league' => [
                    'id'     => 135,
                    'name'   => 'Serie A',
                    'season' => 2026,
                    'round'  => 'Regular Season - 1',
                ],
                'teams' => [
                    'home' => ['id' => 505, 'name' => 'Home FC', 'winner' => true],
                    'away' => ['id' => 489, 'name' => 'Away FC', 'winner' => false],
                ],
                'goals' => ['home' => 2, 'away' => 1],
                'score' => [
                    'halftime'  => ['home' => 1, 'away' => 0],
                    'fulltime'  => ['home' => 2, 'away' => 1],
                    'extratime' => ['home' => null, 'away' => null],
                    'penalty'   => ['home' => null, 'away' => null],
                ],
            ]],
        ];
    }

    private function statsResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 1,
            'response' => [
                [
                    'team'       => ['id' => 505, 'name' => 'Home FC'],
                    'statistics' => [
                        ['type' => 'Total Shots',   'value' => 8],
                        ['type' => 'Shots on Goal', 'value' => 4],
                        ['type' => 'Fouls',         'value' => 12],
                        ['type' => 'Corner Kicks',  'value' => 5],
                        ['type' => 'Yellow Cards',  'value' => 2],
                        ['type' => 'Red Cards',     'value' => 0],
                    ],
                ],
                [
                    'team'       => ['id' => 489, 'name' => 'Away FC'],
                    'statistics' => [
                        ['type' => 'Total Shots',   'value' => 6],
                        ['type' => 'Shots on Goal', 'value' => 2],
                        ['type' => 'Fouls',         'value' => 14],
                        ['type' => 'Corner Kicks',  'value' => 3],
                        ['type' => 'Yellow Cards',  'value' => 1],
                        ['type' => 'Red Cards',     'value' => 0],
                    ],
                ],
            ],
        ];
    }
}
