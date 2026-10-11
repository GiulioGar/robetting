<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchPlayerStatistic;
use App\Models\Player;
use App\Models\PlayerAbsence;
use App\Models\PlayerExternalId;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\PlayerMarketValueShareCalculator;
use App\Services\Analytics\TeamAvailabilityCalculator;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for TeamAvailabilityCalculator (Availability V1, Step 6B).
 * Rules under test:
 *  [A] fixture not injury-covered → null, not covered
 *  [B] fixture covered, zero forced-absentees → weighted_absence = 0.0
 *  [C] one fully-weighable absentee → correct U*V weight
 *  [D] multiple absentees → correct sum
 *  [E] Questionable absence ignored (never enters the sum)
 *  [F] EXCLUDE reason ignored
 *  [G] absentee with U null → excluded from sum, counted unweighted
 *  [H] absentee with V null → excluded from sum, counted unweighted
 *  [I] market value artifact generated after cutoff → V null → incomplete
 *  [J] strict cutoff is honoured for the minutes-share ingredient
 */
class TeamAvailabilityCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $apiDs;
    private DataSource $tmDs;
    private Competition $comp;
    private Season $season;
    private Team $teamA;
    private Team $teamOpponent;
    private string $artifactDir;

    private const KICKOFF = '2026-09-10 20:45:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->apiDs = DataSource::where('slug', 'api-football')->firstOrFail();
        $this->tmDs  = DataSource::create(['slug' => 'transfermarkt', 'name' => 'Transfermarkt', 'source_type' => 'scraper']);

        $country    = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->comp = Competition::create([
            'country_id' => $country->id, 'name' => 'Serie A', 'slug' => 'serie-a',
            'format' => 'league', 'is_active' => true,
        ]);
        $this->season = Season::create([
            'competition_id' => $this->comp->id, 'name' => '2026/27',
            'year_start' => 2026, 'year_end' => 2027, 'is_current' => true,
        ]);
        $this->teamA        = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->teamOpponent = Team::create(['name' => 'Milan', 'type' => 'club', 'is_active' => true]);

        $this->artifactDir = sys_get_temp_dir() . '/tac_test_' . uniqid();
        mkdir($this->artifactDir);
        PlayerMarketValueShareCalculator::setArtifactDir($this->artifactDir);
    }

    protected function tearDown(): void
    {
        PlayerMarketValueShareCalculator::reset();
        array_map('unlink', glob($this->artifactDir . '/*'));
        @rmdir($this->artifactDir);
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [A] Not injury-covered → null
    // ─────────────────────────────────────────────────────────────────────────

    public function test_not_injury_covered_returns_null(): void
    {
        $target = FootballMatch::create($this->matchAttrs(Carbon::parse(self::KICKOFF)));
        // injuries_fetched_at intentionally left null.

        $result = TeamAvailabilityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertNull($result['weighted_absence']);
        $this->assertNull($result['known_weighted_absence']);
        $this->assertFalse($result['injuries_covered']);
        $this->assertNull($result['forced_absent_count']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] Covered, zero forced-absentees → 0.0
    // ─────────────────────────────────────────────────────────────────────────

    public function test_covered_with_zero_absentees_returns_zero(): void
    {
        $target = $this->coveredTargetMatch();

        $result = TeamAvailabilityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(0.0, $result['weighted_absence']);
        $this->assertTrue($result['injuries_covered']);
        $this->assertSame(0, $result['forced_absent_count']);
        $this->assertTrue($result['is_complete']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] One fully-weighable absentee
    // ─────────────────────────────────────────────────────────────────────────

    public function test_single_fully_weighable_absentee(): void
    {
        $target = $this->coveredTargetMatch();
        $player = $this->makeFullyWeighablePlayer('Injured', tmId: 111001, minutes: 90, marketValue: 10000000, top25: 100000000);
        $this->addAbsence($target, $player, 'Missing Fixture', 'Knee Injury');

        $result = TeamAvailabilityCalculator::calculateForMatch($target, $this->teamA->id);

        // U = 1.0 (90/90 in the prior covered match), V = 0.1 → weight = 0.1
        $this->assertSame(1, $result['forced_absent_count']);
        $this->assertSame(1, $result['weighted_player_count']);
        $this->assertSame(0, $result['unweighted_player_count']);
        $this->assertTrue($result['is_complete']);
        $this->assertEqualsWithDelta(0.1, $result['weighted_absence'], 1e-9);
        $this->assertSame('Knee Injury', $result['absentees'][0]['reason']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] Multiple absentees → correct sum
    // ─────────────────────────────────────────────────────────────────────────

    public function test_multiple_absentees_sum_correctly(): void
    {
        $target = $this->coveredTargetMatch();

        $p1 = $this->makeFullyWeighablePlayer('P1', tmId: 200001, minutes: 90, marketValue: 10000000, top25: 100000000); // U=1.0 V=0.1
        $p2 = $this->makeFullyWeighablePlayer('P2', tmId: 200002, minutes: 45, marketValue: 20000000, top25: 100000000); // U=0.5 V=0.2
        $this->addAbsence($target, $p1, 'Missing Fixture', 'Knee Injury');
        $this->addAbsence($target, $p2, 'Missing Fixture', 'Suspended');

        $result = TeamAvailabilityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(2, $result['forced_absent_count']);
        $this->assertSame(2, $result['weighted_player_count']);
        // 1.0*0.1 + 0.5*0.2 = 0.1 + 0.1 = 0.2
        $this->assertEqualsWithDelta(0.2, $result['weighted_absence'], 1e-9);
        $this->assertEqualsWithDelta(0.2, $result['known_weighted_absence'], 1e-9);
        $this->assertTrue($result['is_complete']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D2] Step 6C — partially weighable: weighted_absence=null,
    // known_weighted_absence holds only the known part.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_partially_weighable_absentees_weighted_absence_is_null(): void
    {
        $target = $this->coveredTargetMatch();

        $p1 = $this->makeFullyWeighablePlayer('P1', tmId: 210001, minutes: 90, marketValue: 10000000, top25: 100000000); // U=1.0 V=0.1 → weight 0.1
        $p2 = Player::create(['name' => 'P2Unweighable']); // no history, no mapping at all → fully unweighable
        $this->addAbsence($target, $p1, 'Missing Fixture', 'Knee Injury');
        $this->addAbsence($target, $p2, 'Missing Fixture', 'Suspended');

        $result = TeamAvailabilityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(2, $result['forced_absent_count']);
        $this->assertSame(1, $result['weighted_player_count']);
        $this->assertSame(1, $result['unweighted_player_count']);
        $this->assertFalse($result['is_complete']);
        $this->assertNull($result['weighted_absence']); // genuinely unknown overall
        $this->assertEqualsWithDelta(0.1, $result['known_weighted_absence'], 1e-9); // the known part only
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] Questionable ignored
    // ─────────────────────────────────────────────────────────────────────────

    public function test_questionable_absence_ignored(): void
    {
        $target = $this->coveredTargetMatch();
        $player = $this->makeFullyWeighablePlayer('Doubtful', tmId: 300001, minutes: 90, marketValue: 10000000, top25: 100000000);
        $this->addAbsence($target, $player, 'Questionable', 'Knee Injury');

        $result = TeamAvailabilityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(0, $result['forced_absent_count']);
        $this->assertSame(0.0, $result['weighted_absence']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] EXCLUDE reason ignored
    // ─────────────────────────────────────────────────────────────────────────

    public function test_exclude_reason_ignored(): void
    {
        $target = $this->coveredTargetMatch();
        $player = $this->makeFullyWeighablePlayer('Inactive', tmId: 300002, minutes: 90, marketValue: 10000000, top25: 100000000);
        $this->addAbsence($target, $player, 'Missing Fixture', 'Inactive');

        $result = TeamAvailabilityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(0, $result['forced_absent_count']);
        $this->assertSame(0.0, $result['weighted_absence']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] Absentee with U null → unweighted
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absentee_with_null_u_is_unweighted(): void
    {
        $target = $this->coveredTargetMatch();
        // No prior covered match at all → U will be null (no evidence of membership).
        $player = Player::create(['name' => 'NoHistory']);
        PlayerExternalId::create(['player_id' => $player->id, 'data_source_id' => $this->tmDs->id, 'external_id' => '400001']);
        $this->writePlayersArtifact([(string) $this->teamA->id => ['players' => [
            ['tm_player_id' => 400001, 'name' => 'No History', 'market_value' => 10000000],
        ]]]);
        $this->writeStrengthArtifact([(string) $this->teamA->id => ['top25_market_value' => 100000000]]);

        $this->addAbsence($target, $player, 'Missing Fixture', 'Knee Injury');

        $result = TeamAvailabilityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(1, $result['forced_absent_count']);
        $this->assertSame(0, $result['weighted_player_count']);
        $this->assertSame(1, $result['unweighted_player_count']);
        $this->assertFalse($result['is_complete']);
        $this->assertNull($result['weighted_absence']); // genuinely unknown, never a fake 0
        $this->assertSame(0.0, $result['known_weighted_absence']); // transparency aid: 0 known weights
        $this->assertNull($result['absentees'][0]['player_absence_weight']);
        $this->assertSame('U_NULL_NO_MEMBERSHIP_EVIDENCE', $result['absentees'][0]['u_null_cause']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] Absentee with V null → unweighted
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absentee_with_null_v_is_unweighted(): void
    {
        $target = $this->coveredTargetMatch();
        $player = Player::create(['name' => 'NoMarketValue']);
        // Prior covered match giving a valid U, but NO Transfermarkt mapping at all → V null.
        $prior = $this->coveredMatchAt(Carbon::parse(self::KICKOFF)->subDays(5));
        MatchPlayerStatistic::create([
            'match_id' => $prior->id, 'player_id' => $player->id, 'team_id' => $this->teamA->id,
            'data_source_id' => $this->apiDs->id, 'games_minutes' => 90, 'games_substitute' => false,
        ]);

        $this->addAbsence($target, $player, 'Missing Fixture', 'Knee Injury');

        $result = TeamAvailabilityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertSame(0, $result['weighted_player_count']);
        $this->assertSame(1, $result['unweighted_player_count']);
        $this->assertFalse($result['is_complete']);
        $this->assertNotNull($result['absentees'][0]['available_minutes_share']);
        $this->assertNull($result['absentees'][0]['market_value_share_top25']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] Market value artifact generated AFTER cutoff → V null
    // ─────────────────────────────────────────────────────────────────────────

    public function test_market_value_artifact_after_cutoff_makes_v_null(): void
    {
        $target = $this->coveredTargetMatch();
        $player = $this->makeFullyWeighablePlayer('StaleCheck', tmId: 500001, minutes: 90, marketValue: 10000000, top25: 100000000);

        // Overwrite with an artifact generated AFTER the target kickoff.
        $this->writePlayersArtifact([(string) $this->teamA->id => ['players' => [
            ['tm_player_id' => 500001, 'name' => 'Stale Check', 'market_value' => 10000000],
        ]]], Carbon::parse(self::KICKOFF)->addDay());

        $this->addAbsence($target, $player, 'Missing Fixture', 'Knee Injury');

        $result = TeamAvailabilityCalculator::calculateForMatch($target, $this->teamA->id);

        $this->assertNull($result['absentees'][0]['market_value_share_top25']);
        $this->assertNull($result['absentees'][0]['player_absence_weight']);
        $this->assertFalse($result['is_complete']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J] Strict cutoff on the minutes-share ingredient
    // ─────────────────────────────────────────────────────────────────────────

    public function test_strict_cutoff_on_minutes_share(): void
    {
        $target = $this->coveredTargetMatch();
        $player = Player::create(['name' => 'FutureStats']);
        PlayerExternalId::create(['player_id' => $player->id, 'data_source_id' => $this->tmDs->id, 'external_id' => '600001']);
        $this->writePlayersArtifact([(string) $this->teamA->id => ['players' => [
            ['tm_player_id' => 600001, 'name' => 'Future Stats', 'market_value' => 10000000],
        ]]]);
        $this->writeStrengthArtifact([(string) $this->teamA->id => ['top25_market_value' => 100000000]]);

        // Only a match AT/AFTER kickoff provides a stat row → must not leak into U.
        $future = $this->coveredMatchAt(Carbon::parse(self::KICKOFF)->addDay());
        MatchPlayerStatistic::create([
            'match_id' => $future->id, 'player_id' => $player->id, 'team_id' => $this->teamA->id,
            'data_source_id' => $this->apiDs->id, 'games_minutes' => 90, 'games_substitute' => false,
        ]);

        $this->addAbsence($target, $player, 'Missing Fixture', 'Knee Injury');

        $result = TeamAvailabilityCalculator::calculateForMatch($target, $this->teamA->id);

        // No membership evidence before cutoff → U null → unweighted.
        $this->assertNull($result['absentees'][0]['available_minutes_share']);
        $this->assertSame(0, $result['weighted_player_count']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function matchAttrs(Carbon $kickoff, string $status = 'finished'): array
    {
        return [
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamA->id,
            'away_team_id'   => $this->teamOpponent->id,
            'kickoff_at'     => $kickoff,
            'status'         => $status,
            'home_score_ft'  => $status === 'finished' ? 1 : null,
            'away_score_ft'  => $status === 'finished' ? 0 : null,
        ];
    }

    /** The target match itself, with injuries_fetched_at set. */
    private function coveredTargetMatch(): FootballMatch
    {
        return $this->coveredMatchAt(Carbon::parse(self::KICKOFF));
    }

    private function coveredMatchAt(Carbon $kickoff): FootballMatch
    {
        $m = FootballMatch::create($this->matchAttrs($kickoff));
        $m->forceFill(['injuries_fetched_at' => $kickoff->copy()->subDay()])->save();
        return $m;
    }

    private function addAbsence(FootballMatch $match, Player $player, string $absenceType, string $reason): PlayerAbsence
    {
        return PlayerAbsence::create([
            'match_id'       => $match->id,
            'player_id'      => $player->id,
            'team_id'        => $this->teamA->id,
            'data_source_id' => $this->apiDs->id,
            'absence_type'   => $absenceType,
            'reason'         => $reason,
        ]);
    }

    /**
     * One shared prior covered match (kickoff - 5 days) reused across all
     * players in a test. U is computed over ALL of teamA's covered matches,
     * not per-player — a separate "private" prior match per player would
     * make it count for every OTHER player's U too (it is still a teamA
     * fixture), silently diluting their shares. A single shared match with
     * one stat row per player keeps each U exactly minutes/90 as intended.
     */
    private ?FootballMatch $sharedPriorMatch = null;

    private function sharedPriorMatch(): FootballMatch
    {
        return $this->sharedPriorMatch ??= $this->coveredMatchAt(Carbon::parse(self::KICKOFF)->subDays(5));
    }

    /**
     * Creates a player with a stat row in the shared prior match giving a
     * clean U = minutes/90, a Transfermarkt mapping, and both artifacts
     * (generated before the target kickoff) giving V = marketValue/top25.
     */
    private function makeFullyWeighablePlayer(string $name, int $tmId, int $minutes, int $marketValue, int $top25): Player
    {
        $player = Player::create(['name' => $name]);

        MatchPlayerStatistic::create([
            'match_id' => $this->sharedPriorMatch()->id, 'player_id' => $player->id, 'team_id' => $this->teamA->id,
            'data_source_id' => $this->apiDs->id, 'games_minutes' => $minutes, 'games_substitute' => false,
        ]);

        PlayerExternalId::create(['player_id' => $player->id, 'data_source_id' => $this->tmDs->id, 'external_id' => (string) $tmId]);

        $this->mergePlayersArtifact((string) $this->teamA->id, ['tm_player_id' => $tmId, 'name' => $name, 'market_value' => $marketValue]);
        $this->writeStrengthArtifact([(string) $this->teamA->id => ['top25_market_value' => $top25]]);

        return $player;
    }

    private array $playersArtifactState = [];

    private function mergePlayersArtifact(string $teamKey, array $playerRow): void
    {
        $this->playersArtifactState[$teamKey]['players'][] = $playerRow;
        $this->writePlayersArtifact($this->playersArtifactState);
    }

    private function writePlayersArtifact(array $teams, ?Carbon $generatedAt = null): void
    {
        $generatedAt ??= Carbon::parse(self::KICKOFF)->subDay();
        file_put_contents(
            $this->artifactDir . '/structural_players_current.json',
            json_encode(['generated_at' => $generatedAt->toIso8601String(), 'definition' => 'squad_players_market_value', 'teams' => $teams])
        );
    }

    private function writeStrengthArtifact(array $teams, ?Carbon $generatedAt = null): void
    {
        $generatedAt ??= Carbon::parse(self::KICKOFF)->subDay();
        file_put_contents(
            $this->artifactDir . '/structural_strength_current.json',
            json_encode(['generated_at' => $generatedAt->toIso8601String(), 'definition' => 'top25_market_value', 'teams' => $teams])
        );
    }
}
