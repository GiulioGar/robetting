<?php

namespace Tests\Feature\ApiFootball;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\MatchEvent;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\DataSources\ApiFootball\ApiFootballInjurySyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchEventSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchLineupSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchPlayerStatisticsSyncService;
use App\Services\DataSources\ApiFootball\ApiFootballMatchStatisticsSyncService;
use App\Services\DataSources\ApiFootball\MatchDataScope;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step A5.5/A5.6 — MatchDataScope classification + the resulting sync
 * eligibility per advanced-data type (CORE / TEAM_HISTORY / ELO_ONLY / UNKNOWN).
 */
class MatchDataScopeTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $serieA;
    private Competition $championsLeague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        config(['api-football.api_key'  => 'test-key']);
        config(['api-football.base_url' => 'https://v3.football.api-sports.io']);
        config(['api-football.core_leagues'             => [135 => 'serie-a']]);
        config(['api-football.additional_competitions'  => [2 => 'uefa-champions-league']]);

        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);

        $this->serieA = Competition::create([
            'country_id' => $country->id, 'name' => 'Serie A', 'slug' => 'serie-a', 'format' => 'league', 'is_active' => true,
        ]);
        $this->championsLeague = Competition::create([
            'country_id' => $country->id, 'name' => 'UEFA Champions League', 'slug' => 'uefa-champions-league', 'format' => 'cup', 'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Classification
    // -------------------------------------------------------------------------

    public function test_core_league_match_is_core(): void
    {
        $season = $this->domesticSeason($this->serieA, 2026);
        $home   = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $away   = Team::create(['name' => 'Inter',  'type' => 'club', 'is_active' => true]);

        $match = $this->makeMatch($this->serieA, $season, $home, $away);

        $this->assertSame(MatchDataScope::CORE, MatchDataScope::classify($match));
    }

    public function test_champions_core_vs_noncore_is_team_history(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $nonCore      = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);

        $clSeason = $this->uefaSeason(2026);
        $match    = $this->makeMatch($this->championsLeague, $clSeason, $core, $nonCore);

        $this->assertSame(MatchDataScope::TEAM_HISTORY, MatchDataScope::classify($match));
    }

    public function test_champions_core_vs_core_is_team_history(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $coreA        = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $coreB        = Team::create(['name' => 'Inter',  'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($coreA, $serieASeason);
        $this->makeCoreMembership($coreB, $serieASeason);

        $clSeason = $this->uefaSeason(2026);
        $match    = $this->makeMatch($this->championsLeague, $clSeason, $coreA, $coreB);

        $this->assertSame(MatchDataScope::TEAM_HISTORY, MatchDataScope::classify($match));
    }

    public function test_champions_noncore_vs_noncore_is_elo_only(): void
    {
        $nonCoreA = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $nonCoreB = Team::create(['name' => 'PSV',      'type' => 'club', 'is_active' => true]);

        $clSeason = $this->uefaSeason(2026);
        $match    = $this->makeMatch($this->championsLeague, $clSeason, $nonCoreA, $nonCoreB);

        $this->assertSame(MatchDataScope::ELO_ONLY, MatchDataScope::classify($match));
    }

    /**
     * A5.6 — a competition in neither core_leagues nor additional_competitions
     * must classify as UNKNOWN, never implicitly as CORE.
     */
    public function test_unconfigured_competition_is_unknown(): void
    {
        $country    = \App\Models\Country::create(['name' => 'Nowhere', 'football_code' => 'XX']);
        $unknownComp = Competition::create(['country_id' => $country->id, 'name' => 'Random Cup', 'slug' => 'random-cup', 'format' => 'cup', 'is_active' => true]);
        $season     = $this->domesticSeason($unknownComp, 2026);
        $home       = Team::create(['name' => 'Foo FC', 'type' => 'club', 'is_active' => true]);
        $away       = Team::create(['name' => 'Bar FC', 'type' => 'club', 'is_active' => true]);

        $match = $this->makeMatch($unknownComp, $season, $home, $away);

        $this->assertSame(MatchDataScope::UNKNOWN, MatchDataScope::classify($match));
    }

    /**
     * Cross-season membership: the SAME team is core in year_start=2026 but
     * NOT in year_start=2025 — classification must follow season_team of the
     * correct year_start, never a flat "is this team core today" check.
     */
    public function test_cross_season_membership_follows_correct_year_start(): void
    {
        $team2026 = $this->domesticSeason($this->serieA, 2026);
        $team     = Team::create(['name' => 'Como', 'type' => 'club', 'is_active' => true]);
        $opponent = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($team, $team2026); // core only in 2026, no season_team row for 2025

        $cl2026 = $this->uefaSeason(2026);
        $cl2025 = $this->uefaSeason(2025);

        $matchIn2026 = $this->makeMatch($this->championsLeague, $cl2026, $team, $opponent);
        $matchIn2025 = $this->makeMatch($this->championsLeague, $cl2025, $team, $opponent);

        $this->assertSame(MatchDataScope::TEAM_HISTORY, MatchDataScope::classify($matchIn2026));
        $this->assertSame(MatchDataScope::ELO_ONLY, MatchDataScope::classify($matchIn2025));
    }

    // -------------------------------------------------------------------------
    // Batch / no N+1
    // -------------------------------------------------------------------------

    public function test_classify_many_does_not_issue_one_query_per_match(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);

        $clSeason = $this->uefaSeason(2026);
        $matches  = collect();
        for ($i = 0; $i < 8; $i++) {
            $opponent  = Team::create(['name' => "Opponent {$i}", 'type' => 'club', 'is_active' => true]);
            $matches[] = $this->makeMatch($this->championsLeague, $clSeason, $core, $opponent);
        }

        DB::enableQueryLog();
        MatchDataScope::classifyMany($matches);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // At most: 1 (core_leagues ids) + 1 (additional_competitions ids)
        // + 1 (season_id -> year_start) + 1 (season_team core-team warm-up) = 4,
        // regardless of match count (8 here).
        $this->assertLessThanOrEqual(4, $queryCount, "classifyMany() must batch, not query per match (got {$queryCount} queries for 8 matches)");
    }

    // -------------------------------------------------------------------------
    // Sync eligibility — statistics: CORE yes, TEAM_HISTORY yes, ELO_ONLY no
    // -------------------------------------------------------------------------

    public function test_statistics_sync_pending_includes_core_and_team_history_excludes_elo_only(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $otherCore    = Team::create(['name' => 'Inter',  'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);

        $nonCoreA = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $nonCoreB = Team::create(['name' => 'PSV',      'type' => 'club', 'is_active' => true]);

        $coreMatch = $this->makeFinishedMatch($this->serieA, $serieASeason, $core, $otherCore, 1001);

        $clSeason        = $this->uefaSeason(2026);
        $teamHistoryMatch = $this->makeFinishedMatch($this->championsLeague, $clSeason, $core, $nonCoreA, 1002);
        $eloOnlyMatch     = $this->makeFinishedMatch($this->championsLeague, $clSeason, $nonCoreA, $nonCoreB, 1003);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($this->minimalStatsResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncPending();

        // Only 2 candidates reach the API: CORE + TEAM_HISTORY. ELO_ONLY excluded BEFORE the call.
        $this->assertSame(2, $result['candidates']);
        Http::assertSentCount(2);

        $this->assertNotNull(MatchStatistic::where('match_id', $coreMatch->id)->first());
        $this->assertNotNull(MatchStatistic::where('match_id', $teamHistoryMatch->id)->first());
        $this->assertNull(MatchStatistic::where('match_id', $eloOnlyMatch->id)->first());
    }

    // -------------------------------------------------------------------------
    // Sync eligibility — events: CORE only
    // -------------------------------------------------------------------------

    public function test_events_sync_pending_only_core(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $otherCore    = Team::create(['name' => 'Inter',  'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);

        $nonCoreA = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $nonCoreB = Team::create(['name' => 'PSV',      'type' => 'club', 'is_active' => true]);

        $coreMatch = $this->makeFinishedMatch($this->serieA, $serieASeason, $core, $otherCore, 2001);

        $clSeason         = $this->uefaSeason(2026);
        $teamHistoryMatch = $this->makeFinishedMatch($this->championsLeague, $clSeason, $core, $nonCoreA, 2002);
        $eloOnlyMatch     = $this->makeFinishedMatch($this->championsLeague, $clSeason, $nonCoreA, $nonCoreB, 2003);

        Http::fake(['v3.football.api-sports.io/fixtures/events*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200)]);

        $result = app(ApiFootballMatchEventSyncService::class)->syncPending();

        // Events: ONLY core — team_history is excluded here too (stricter than statistics).
        $this->assertSame(1, $result['candidates']);
        Http::assertSentCount(1);

        $coreMatch->refresh();
        $teamHistoryMatch->refresh();
        $eloOnlyMatch->refresh();
        $this->assertNotNull($coreMatch->events_fetched_at);
        $this->assertNull($teamHistoryMatch->events_fetched_at);
        $this->assertNull($eloOnlyMatch->events_fetched_at);
    }

    // -------------------------------------------------------------------------
    // Sync eligibility — lineups: CORE yes, TEAM_HISTORY yes, ELO_ONLY no
    // -------------------------------------------------------------------------

    public function test_lineups_sync_pending_includes_core_and_team_history_excludes_elo_only(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $otherCore    = Team::create(['name' => 'Inter',  'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);

        $nonCoreA = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $nonCoreB = Team::create(['name' => 'PSV',      'type' => 'club', 'is_active' => true]);

        // Lineups window: scheduled match with kickoff within [-30m, +75m].
        $kickoff = now()->addMinutes(10);
        $coreMatch        = $this->makeScheduledMatch($this->serieA, $serieASeason, $core, $otherCore, 3001, $kickoff);
        $clSeason         = $this->uefaSeason(2026);
        $teamHistoryMatch = $this->makeScheduledMatch($this->championsLeague, $clSeason, $core, $nonCoreA, 3002, $kickoff);
        $eloOnlyMatch     = $this->makeScheduledMatch($this->championsLeague, $clSeason, $nonCoreA, $nonCoreB, 3003, $kickoff);

        Http::fake(['v3.football.api-sports.io/fixtures/lineups*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200)]);

        $result = app(ApiFootballMatchLineupSyncService::class)->syncPending();

        $this->assertSame(2, $result['candidates']);
        Http::assertSentCount(2);

        $coreMatch->refresh();
        $teamHistoryMatch->refresh();
        $eloOnlyMatch->refresh();
        $this->assertNotNull($coreMatch->lineups_last_attempt_at);
        $this->assertNotNull($teamHistoryMatch->lineups_last_attempt_at);
        $this->assertNull($eloOnlyMatch->lineups_last_attempt_at);
    }

    // -------------------------------------------------------------------------
    // Sync eligibility — player statistics: CORE yes, TEAM_HISTORY yes, ELO_ONLY no
    // -------------------------------------------------------------------------

    public function test_player_statistics_sync_pending_includes_core_and_team_history_excludes_elo_only(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $otherCore    = Team::create(['name' => 'Inter',  'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);

        $nonCoreA = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $nonCoreB = Team::create(['name' => 'PSV',      'type' => 'club', 'is_active' => true]);

        $coreMatch        = $this->makeFinishedMatch($this->serieA, $serieASeason, $core, $otherCore, 4001);
        $clSeason         = $this->uefaSeason(2026);
        $teamHistoryMatch = $this->makeFinishedMatch($this->championsLeague, $clSeason, $core, $nonCoreA, 4002);
        $eloOnlyMatch     = $this->makeFinishedMatch($this->championsLeague, $clSeason, $nonCoreA, $nonCoreB, 4003);

        Http::fake(['v3.football.api-sports.io/fixtures/players*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200)]);

        $result = app(ApiFootballMatchPlayerStatisticsSyncService::class)->syncPending();

        $this->assertSame(2, $result['candidates']);
        Http::assertSentCount(2);

        $coreMatch->refresh();
        $teamHistoryMatch->refresh();
        $eloOnlyMatch->refresh();
        $this->assertNotNull($coreMatch->player_stats_fetched_at);
        $this->assertNotNull($teamHistoryMatch->player_stats_fetched_at);
        $this->assertNull($eloOnlyMatch->player_stats_fetched_at);
    }

    // -------------------------------------------------------------------------
    // Sync eligibility — injuries: CORE only (via existing $leagueSlug param)
    // -------------------------------------------------------------------------

    public function test_injuries_sync_pending_scoped_to_core_leagues_only(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $otherCore    = Team::create(['name' => 'Inter',  'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);

        $nonCoreA = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);

        $kickoff   = now()->addHours(20);
        $coreMatch = $this->makeScheduledMatch($this->serieA, $serieASeason, $core, $otherCore, 5001, $kickoff);
        $clSeason  = $this->uefaSeason(2026);
        $uefaMatch = $this->makeScheduledMatch($this->championsLeague, $clSeason, $core, $nonCoreA, 5002, $kickoff);

        Http::fake(['v3.football.api-sports.io/injuries*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200)]);

        $result = app(ApiFootballInjurySyncService::class)->syncPending(3, ['serie-a']);

        $this->assertSame(1, $result['candidates']);
        Http::assertSentCount(1);

        $coreMatch->refresh();
        $uefaMatch->refresh();
        $this->assertNotNull($coreMatch->injuries_last_attempt_at);
        $this->assertNull($uefaMatch->injuries_last_attempt_at);
    }

    // -------------------------------------------------------------------------
    // A5.6 — UNKNOWN competition: no advanced-data eligibility anywhere
    // -------------------------------------------------------------------------

    private function makeUnknownCompetition(): Competition
    {
        $country = \App\Models\Country::create(['name' => 'Nowhere ' . uniqid(), 'football_code' => null]);
        return Competition::create(['country_id' => $country->id, 'name' => 'Random Cup', 'slug' => 'random-cup-' . uniqid(), 'format' => 'cup', 'is_active' => true]);
    }

    public function test_statistics_sync_pending_excludes_unknown_competition(): void
    {
        $unknown = $this->makeUnknownCompetition();
        $season  = $this->domesticSeason($unknown, 2026);
        $home    = Team::create(['name' => 'Foo FC', 'type' => 'club', 'is_active' => true]);
        $away    = Team::create(['name' => 'Bar FC', 'type' => 'club', 'is_active' => true]);
        $match   = $this->makeFinishedMatch($unknown, $season, $home, $away, 6001);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($this->minimalStatsResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)->syncPending();

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
        $this->assertNull(MatchStatistic::where('match_id', $match->id)->first());
    }

    public function test_events_sync_pending_excludes_unknown_competition(): void
    {
        $unknown = $this->makeUnknownCompetition();
        $season  = $this->domesticSeason($unknown, 2026);
        $home    = Team::create(['name' => 'Foo FC', 'type' => 'club', 'is_active' => true]);
        $away    = Team::create(['name' => 'Bar FC', 'type' => 'club', 'is_active' => true]);
        $match   = $this->makeFinishedMatch($unknown, $season, $home, $away, 6002);

        Http::fake(['v3.football.api-sports.io/fixtures/events*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200)]);

        $result = app(ApiFootballMatchEventSyncService::class)->syncPending();

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
        $match->refresh();
        $this->assertNull($match->events_fetched_at);
    }

    public function test_lineups_sync_pending_excludes_unknown_competition(): void
    {
        $unknown = $this->makeUnknownCompetition();
        $season  = $this->domesticSeason($unknown, 2026);
        $home    = Team::create(['name' => 'Foo FC', 'type' => 'club', 'is_active' => true]);
        $away    = Team::create(['name' => 'Bar FC', 'type' => 'club', 'is_active' => true]);
        $match   = $this->makeScheduledMatch($unknown, $season, $home, $away, 6003, now()->addMinutes(10));

        Http::fake(['v3.football.api-sports.io/fixtures/lineups*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200)]);

        $result = app(ApiFootballMatchLineupSyncService::class)->syncPending();

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
        $match->refresh();
        $this->assertNull($match->lineups_last_attempt_at);
    }

    public function test_player_statistics_sync_pending_excludes_unknown_competition(): void
    {
        $unknown = $this->makeUnknownCompetition();
        $season  = $this->domesticSeason($unknown, 2026);
        $home    = Team::create(['name' => 'Foo FC', 'type' => 'club', 'is_active' => true]);
        $away    = Team::create(['name' => 'Bar FC', 'type' => 'club', 'is_active' => true]);
        $match   = $this->makeFinishedMatch($unknown, $season, $home, $away, 6004);

        Http::fake(['v3.football.api-sports.io/fixtures/players*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200)]);

        $result = app(ApiFootballMatchPlayerStatisticsSyncService::class)->syncPending();

        $this->assertSame(0, $result['candidates']);
        Http::assertNothingSent();
        $match->refresh();
        $this->assertNull($match->player_stats_fetched_at);
    }

    // -------------------------------------------------------------------------
    // B4 — syncMissingHistorical(scope=team-history): backfill safety
    // -------------------------------------------------------------------------

    public function test_historical_player_stats_team_history_scope_excludes_core(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $otherCore    = Team::create(['name' => 'Inter',  'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);
        $coreMatch = $this->makeFinishedMatch($this->serieA, $serieASeason, $core, $otherCore, 7001);

        Http::fake(['v3.football.api-sports.io/fixtures/players*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200)]);

        $result = app(ApiFootballMatchPlayerStatisticsSyncService::class)
            ->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);

        $this->assertSame(0, $result['candidates'], 'CORE must be excluded under scope=team-history');
        Http::assertNothingSent();
        $coreMatch->refresh();
        $this->assertNull($coreMatch->player_stats_fetched_at);
    }

    public function test_historical_player_stats_team_history_scope_includes_team_history(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);
        $nonCore  = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $clSeason = $this->uefaSeason(2026);
        $teamHistoryMatch = $this->makeFinishedMatch($this->championsLeague, $clSeason, $core, $nonCore, 7002);

        Http::fake(['v3.football.api-sports.io/fixtures/players*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200)]);

        $result = app(ApiFootballMatchPlayerStatisticsSyncService::class)
            ->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);

        $this->assertSame(1, $result['candidates']);
        Http::assertSentCount(1);
        $teamHistoryMatch->refresh();
        $this->assertNotNull($teamHistoryMatch->player_stats_fetched_at);
    }

    public function test_historical_player_stats_team_history_scope_excludes_elo_only(): void
    {
        $nonCoreA = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $nonCoreB = Team::create(['name' => 'PSV',      'type' => 'club', 'is_active' => true]);
        $clSeason = $this->uefaSeason(2026);
        $eloOnlyMatch = $this->makeFinishedMatch($this->championsLeague, $clSeason, $nonCoreA, $nonCoreB, 7003);

        Http::fake(['v3.football.api-sports.io/fixtures/players*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200)]);

        $result = app(ApiFootballMatchPlayerStatisticsSyncService::class)
            ->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);

        $this->assertSame(0, $result['candidates'], 'ELO_ONLY must be excluded under scope=team-history');
        Http::assertNothingSent();
        $eloOnlyMatch->refresh();
        $this->assertNull($eloOnlyMatch->player_stats_fetched_at);
    }

    public function test_historical_player_stats_team_history_scope_excludes_unknown(): void
    {
        $unknown = $this->makeUnknownCompetition();
        $season  = $this->domesticSeason($unknown, 2026);
        $home    = Team::create(['name' => 'Foo FC', 'type' => 'club', 'is_active' => true]);
        $away    = Team::create(['name' => 'Bar FC', 'type' => 'club', 'is_active' => true]);
        $match   = $this->makeFinishedMatch($unknown, $season, $home, $away, 7004);

        Http::fake(['v3.football.api-sports.io/fixtures/players*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200)]);

        $result = app(ApiFootballMatchPlayerStatisticsSyncService::class)
            ->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);

        $this->assertSame(0, $result['candidates'], 'UNKNOWN must be excluded under scope=team-history');
        Http::assertNothingSent();
        $match->refresh();
        $this->assertNull($match->player_stats_fetched_at);
    }

    public function test_historical_player_stats_team_history_scope_excludes_already_covered(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);
        $nonCore  = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $clSeason = $this->uefaSeason(2026);
        $alreadyCovered = $this->makeFinishedMatch($this->championsLeague, $clSeason, $core, $nonCore, 7005);
        $alreadyCovered->update(['player_stats_fetched_at' => now()->subDay()]);

        Http::fake(['v3.football.api-sports.io/fixtures/players*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200)]);

        $result = app(ApiFootballMatchPlayerStatisticsSyncService::class)
            ->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);

        $this->assertSame(0, $result['candidates'], 'already-covered match must be excluded');
        Http::assertNothingSent();
    }

    public function test_historical_player_stats_team_history_scope_excludes_scheduled(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);
        $nonCore  = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $clSeason = $this->uefaSeason(2026);
        $scheduledMatch = $this->makeScheduledMatch($this->championsLeague, $clSeason, $core, $nonCore, 7006, now()->addMonth());

        Http::fake(['v3.football.api-sports.io/fixtures/players*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200)]);

        $result = app(ApiFootballMatchPlayerStatisticsSyncService::class)
            ->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);

        $this->assertSame(0, $result['candidates'], 'non-definitive (scheduled) must be excluded');
        Http::assertNothingSent();
        $scheduledMatch->refresh();
        $this->assertNull($scheduledMatch->player_stats_fetched_at);
    }

    public function test_historical_player_stats_team_history_second_run_fetches_nothing_new(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);
        $nonCore  = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $clSeason = $this->uefaSeason(2026);
        $this->makeFinishedMatch($this->championsLeague, $clSeason, $core, $nonCore, 7007);

        Http::fake(['v3.football.api-sports.io/fixtures/players*' => Http::response(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []], 200)]);

        $service = app(ApiFootballMatchPlayerStatisticsSyncService::class);
        $first   = $service->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);
        $this->assertSame(1, $first['candidates']);
        Http::assertSentCount(1);

        $second = $service->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);
        $this->assertSame(0, $second['candidates'], 'second run must fetch nothing new for an already-covered match');
        Http::assertSentCount(1); // still just the one call from the first run
    }

    // -------------------------------------------------------------------------
    // B6 — syncMissingHistorical(scope=team-history) for MATCH STATISTICS
    // -------------------------------------------------------------------------

    public function test_historical_statistics_team_history_scope_excludes_core(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $otherCore    = Team::create(['name' => 'Inter',  'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);
        $coreMatch = $this->makeFinishedMatch($this->serieA, $serieASeason, $core, $otherCore, 8001);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($this->minimalStatsResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)
            ->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);

        $this->assertSame(0, $result['candidates'], 'CORE must be excluded under scope=team-history');
        Http::assertNothingSent();
        $this->assertNull(MatchStatistic::where('match_id', $coreMatch->id)->first());
    }

    public function test_historical_statistics_team_history_scope_includes_team_history(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);
        $nonCore  = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $clSeason = $this->uefaSeason(2026);
        $teamHistoryMatch = $this->makeFinishedMatch($this->championsLeague, $clSeason, $core, $nonCore, 8002);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($this->minimalStatsResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)
            ->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);

        $this->assertSame(1, $result['candidates']);
        Http::assertSentCount(1);
        $this->assertNotNull(MatchStatistic::where('match_id', $teamHistoryMatch->id)->first());
    }

    public function test_historical_statistics_team_history_scope_excludes_elo_only(): void
    {
        $nonCoreA = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $nonCoreB = Team::create(['name' => 'PSV',      'type' => 'club', 'is_active' => true]);
        $clSeason = $this->uefaSeason(2026);
        $eloOnlyMatch = $this->makeFinishedMatch($this->championsLeague, $clSeason, $nonCoreA, $nonCoreB, 8003);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($this->minimalStatsResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)
            ->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);

        $this->assertSame(0, $result['candidates'], 'ELO_ONLY must be excluded under scope=team-history');
        Http::assertNothingSent();
        $this->assertNull(MatchStatistic::where('match_id', $eloOnlyMatch->id)->first());
    }

    public function test_historical_statistics_team_history_scope_excludes_unknown(): void
    {
        $unknown = $this->makeUnknownCompetition();
        $season  = $this->domesticSeason($unknown, 2026);
        $home    = Team::create(['name' => 'Foo FC', 'type' => 'club', 'is_active' => true]);
        $away    = Team::create(['name' => 'Bar FC', 'type' => 'club', 'is_active' => true]);
        $match   = $this->makeFinishedMatch($unknown, $season, $home, $away, 8004);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($this->minimalStatsResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)
            ->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);

        $this->assertSame(0, $result['candidates'], 'UNKNOWN must be excluded under scope=team-history');
        Http::assertNothingSent();
        $this->assertNull(MatchStatistic::where('match_id', $match->id)->first());
    }

    public function test_historical_statistics_team_history_scope_excludes_already_covered(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);
        $nonCore  = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $clSeason = $this->uefaSeason(2026);
        $alreadyCovered = $this->makeFinishedMatch($this->championsLeague, $clSeason, $core, $nonCore, 8005);
        MatchStatistic::create([
            'match_id'             => $alreadyCovered->id,
            'data_source_id'       => $this->ds->id,
            'fetched_at'           => now()->subDay(),
            'stats_schema_version' => 2,
        ]);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($this->minimalStatsResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)
            ->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);

        $this->assertSame(0, $result['candidates'], 'already-covered (v2) match must be excluded');
        Http::assertNothingSent();
    }

    public function test_historical_statistics_team_history_scope_excludes_scheduled(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);
        $nonCore  = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $clSeason = $this->uefaSeason(2026);
        $scheduledMatch = $this->makeScheduledMatch($this->championsLeague, $clSeason, $core, $nonCore, 8006, now()->addMonth());

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($this->minimalStatsResponse(), 200)]);

        $result = app(ApiFootballMatchStatisticsSyncService::class)
            ->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);

        $this->assertSame(0, $result['candidates'], 'non-definitive (scheduled) must be excluded');
        Http::assertNothingSent();
        $this->assertNull(MatchStatistic::where('match_id', $scheduledMatch->id)->first());
    }

    public function test_historical_statistics_team_history_second_run_fetches_nothing_new(): void
    {
        $serieASeason = $this->domesticSeason($this->serieA, 2026);
        $core         = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->makeCoreMembership($core, $serieASeason);
        $nonCore  = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);
        $clSeason = $this->uefaSeason(2026);
        $this->makeFinishedMatch($this->championsLeague, $clSeason, $core, $nonCore, 8007);

        Http::fake(['v3.football.api-sports.io/fixtures/statistics*' => Http::response($this->minimalStatsResponse(), 200)]);

        $service = app(ApiFootballMatchStatisticsSyncService::class);
        $first   = $service->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);
        $this->assertSame(1, $first['candidates']);
        Http::assertSentCount(1);

        $second = $service->syncMissingHistorical(2026, [MatchDataScope::TEAM_HISTORY]);
        $this->assertSame(0, $second['candidates'], 'second run must fetch nothing new for an already-covered match');
        Http::assertSentCount(1);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function domesticSeason(Competition $competition, int $yearStart): Season
    {
        return Season::create([
            'competition_id' => $competition->id,
            'name'           => "{$yearStart}/" . substr((string) ($yearStart + 1), -2),
            'year_start'     => $yearStart,
            'year_end'       => $yearStart + 1,
            'is_current'     => true,
        ]);
    }

    private function uefaSeason(int $yearStart): Season
    {
        return $this->domesticSeason($this->championsLeague, $yearStart);
    }

    private function makeCoreMembership(Team $team, Season $domesticSeason): void
    {
        $domesticSeason->teams()->attach($team->id);
    }

    private function makeMatch(Competition $competition, Season $season, Team $home, Team $away): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $competition->id,
            'season_id'      => $season->id,
            'home_team_id'   => $home->id,
            'away_team_id'   => $away->id,
            'kickoff_at'     => now()->subDay(),
            'status'         => 'finished',
        ]);
    }

    private function makeFinishedMatch(Competition $competition, Season $season, Team $home, Team $away, int $extId): FootballMatch
    {
        $match = $this->makeMatch($competition, $season, $home, $away);
        $this->mapTeamsAndMatch($home, $away, $match, $extId);
        return $match;
    }

    private function makeScheduledMatch(Competition $competition, Season $season, Team $home, Team $away, int $extId, \Illuminate\Support\Carbon $kickoff): FootballMatch
    {
        $match = FootballMatch::create([
            'competition_id' => $competition->id,
            'season_id'      => $season->id,
            'home_team_id'   => $home->id,
            'away_team_id'   => $away->id,
            'kickoff_at'     => $kickoff,
            'status'         => 'scheduled',
        ]);
        $this->mapTeamsAndMatch($home, $away, $match, $extId);
        return $match;
    }

    private function mapTeamsAndMatch(Team $home, Team $away, FootballMatch $match, int $extId): void
    {
        foreach ([$home, $away] as $team) {
            TeamExternalId::firstOrCreate(
                ['team_id' => $team->id, 'data_source_id' => $this->ds->id],
                ['external_id' => (string) (10000 + $team->id), 'external_name' => $team->name],
            );
        }

        MatchExternalId::create([
            'match_id'       => $match->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => (string) $extId,
            'external_name'  => null,
        ]);
    }

    private function minimalStatsResponse(): array
    {
        return [
            'errors'   => [],
            'results'  => 2,
            'paging'   => ['current' => 1, 'total' => 1],
            'response' => [
                ['team' => ['id' => 1, 'name' => 'A'], 'statistics' => [['type' => 'Total Shots', 'value' => 5]]],
                ['team' => ['id' => 2, 'name' => 'B'], 'statistics' => [['type' => 'Total Shots', 'value' => 3]]],
            ],
        ];
    }
}
