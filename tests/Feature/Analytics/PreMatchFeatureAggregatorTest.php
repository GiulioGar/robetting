<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\PreMatchFeatureAggregator;
use App\Services\Analytics\TeamEloCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for PreMatchFeatureAggregator.
 *
 * Tests verify the aggregator's output contract and anti-leakage guarantee.
 * Numeric accuracy of individual analytics blocks is delegated to the
 * respective calculator tests.
 *
 * Tests:
 *  [A]  Identity block contains correct match/team/season/competition IDs
 *  [B]  kickoff_at null → throws InvalidArgumentException
 *  [C]  metadata.gaps contains 'experimental_xg_rolling_calculator_missing'
 *  [D]  experimental.xg is always null (calculator not yet implemented)
 *  [E]  metadata.cutoff === identity.kickoff_at (same ISO string)
 *  [F]  H2H with no prior history → total_h2h = 0, all H2H counts are 0
 *  [G]  Season start (no previous matches) → recent.home/away.matches_considered = 0
 *  [H]  At season start Elo = INITIAL_ELO for both teams
 *  [I]  Recent block reflects actual prior-match count and goal averages
 *  [J]  flatten() produces flat array: no nested associative arrays, correct key prefix
 *  [K]  Idempotency — two identical calls produce identical output
 *  [L]  metadata.leakage_audit contains an entry for every expected block
 */
class PreMatchFeatureAggregatorTest extends TestCase
{
    use RefreshDatabase;

    private Competition   $comp;
    private Season        $season;
    private Team          $teamHome;
    private Team          $teamAway;
    private Team          $teamOpp;
    private FootballMatch $targetMatch;

    private const TARGET_KICKOFF = '2026-10-01 20:45:00';

    protected function setUp(): void
    {
        parent::setUp();

        $country        = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->comp     = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $this->season   = Season::create([
            'competition_id' => $this->comp->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);
        $this->teamHome = Team::create(['name' => 'HomeFC', 'type' => 'club', 'is_active' => true]);
        $this->teamAway = Team::create(['name' => 'AwayFC', 'type' => 'club', 'is_active' => true]);
        $this->teamOpp  = Team::create(['name' => 'OppFC',  'type' => 'club', 'is_active' => true]);

        // Register all three teams in the season (required for league_mean_elo in E9/E10).
        $this->season->teams()->attach([
            $this->teamHome->id,
            $this->teamAway->id,
            $this->teamOpp->id,
        ]);

        $this->targetMatch = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamHome->id,
            'away_team_id'   => $this->teamAway->id,
            'kickoff_at'     => self::TARGET_KICKOFF,
            'status'         => 'scheduled',
        ]);
    }

    // ── [A] Identity ─────────────────────────────────────────────────────────

    public function test_identity_block_contains_correct_ids(): void
    {
        $snap     = PreMatchFeatureAggregator::aggregate($this->targetMatch);
        $identity = $snap['identity'];

        $this->assertSame($this->targetMatch->id, $identity['match_id']);
        $this->assertSame($this->season->id,      $identity['season_id']);
        $this->assertSame($this->comp->id,        $identity['competition_id']);
        $this->assertSame($this->teamHome->id,    $identity['home_team_id']);
        $this->assertSame($this->teamAway->id,    $identity['away_team_id']);
        $this->assertSame(
            Carbon::parse(self::TARGET_KICKOFF)->toIso8601String(),
            $identity['kickoff_at']
        );
    }

    // ── [B] null kickoff_at ───────────────────────────────────────────────────

    public function test_throws_when_kickoff_at_is_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/kickoff_at is null/');

        $match = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamHome->id,
            'away_team_id'   => $this->teamAway->id,
            'kickoff_at'     => null,
            'status'         => 'scheduled',
        ]);

        PreMatchFeatureAggregator::aggregate($match);
    }

    // ── [C] gaps ──────────────────────────────────────────────────────────────

    public function test_metadata_gaps_contains_xg_calculator_missing(): void
    {
        $snap = PreMatchFeatureAggregator::aggregate($this->targetMatch);

        $this->assertContains(
            'experimental_xg_rolling_calculator_missing',
            $snap['metadata']['gaps']
        );
    }

    // ── [D] experimental.xg always null ──────────────────────────────────────

    public function test_experimental_xg_is_always_null(): void
    {
        $snap = PreMatchFeatureAggregator::aggregate($this->targetMatch);

        $this->assertNull($snap['experimental']['xg']);
    }

    // ── [E] cutoff === kickoff_at ─────────────────────────────────────────────

    public function test_metadata_cutoff_equals_identity_kickoff_at(): void
    {
        $snap = PreMatchFeatureAggregator::aggregate($this->targetMatch);

        $this->assertSame($snap['identity']['kickoff_at'], $snap['metadata']['cutoff']);
        $this->assertSame('strictly_before', $snap['metadata']['cutoff_operator']);
    }

    // ── [F] H2H with no history ───────────────────────────────────────────────

    public function test_h2h_with_no_prior_history_returns_zero_counts(): void
    {
        $snap = PreMatchFeatureAggregator::aggregate($this->targetMatch);
        $h2h  = $snap['experimental']['h2h'];

        $this->assertSame(0, $h2h['total_h2h']);
        $this->assertSame(0, $h2h['target_home_team_wins']);
        $this->assertSame(0, $h2h['draws']);
        $this->assertSame(0, $h2h['target_away_team_wins']);
        $this->assertNull($h2h['avg_total_goals']);
    }

    // ── [G] Season start — no previous matches ────────────────────────────────

    public function test_at_season_start_recent_block_has_zero_matches_considered(): void
    {
        $snap = PreMatchFeatureAggregator::aggregate($this->targetMatch);

        $this->assertSame(0, $snap['core']['recent']['home']['matches_considered']);
        $this->assertSame(0, $snap['core']['recent']['away']['matches_considered']);
        $this->assertNull($snap['core']['recent']['home']['avg_goals_for']);
        $this->assertNull($snap['core']['recent']['away']['avg_goals_for']);
    }

    // ── [H] At season start Elo = INITIAL_ELO ────────────────────────────────

    public function test_at_season_start_elo_is_initial_for_both_teams(): void
    {
        $snap = PreMatchFeatureAggregator::aggregate($this->targetMatch);
        $elo  = $snap['core']['elo'];

        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO, $elo['home_pre_match_elo'], 0.001);
        $this->assertEqualsWithDelta(TeamEloCalculator::INITIAL_ELO, $elo['away_pre_match_elo'], 0.001);
        $this->assertEqualsWithDelta(0.0, $elo['elo_diff'], 0.001);
        $this->assertIsFloat($elo['home_pre_match_elo']);
    }

    // ── [I] Recent block reflects actual prior data ───────────────────────────

    public function test_recent_block_reflects_prior_matches_and_goals(): void
    {
        // Home team played 2 matches before the target: won 2-0 and drew 1-1.
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamHome->id,
            'away_team_id'   => $this->teamOpp->id,
            'kickoff_at'     => '2026-08-20 20:45:00',
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 0,
        ]);
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamOpp->id,
            'away_team_id'   => $this->teamHome->id,
            'kickoff_at'     => '2026-09-03 20:45:00',
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 1,
        ]);

        $snap       = PreMatchFeatureAggregator::aggregate($this->targetMatch);
        $homeRecent = $snap['core']['recent']['home'];

        $this->assertSame(2, $homeRecent['matches_considered']);
        // goals_for: 2 (home win) + 1 (draw, played as away) = 3 total, avg = 1.5
        $this->assertEqualsWithDelta(1.5, $homeRecent['avg_goals_for'], 0.01);
        // goals_against: 0 + 1 = 1 total, avg = 0.5
        $this->assertEqualsWithDelta(0.5, $homeRecent['avg_goals_against'], 0.01);

        // Away team had no prior matches.
        $this->assertSame(0, $snap['core']['recent']['away']['matches_considered']);
    }

    // ── [J] flatten() ─────────────────────────────────────────────────────────

    public function test_flatten_produces_flat_array_with_correct_key_prefixes(): void
    {
        $snap = PreMatchFeatureAggregator::aggregate($this->targetMatch);
        $flat = PreMatchFeatureAggregator::flatten($snap);

        // No value should be an associative array — all should be scalars/null/sequential.
        foreach ($flat as $key => $value) {
            if (is_array($value)) {
                $this->assertEquals(
                    range(0, count($value) - 1),
                    array_keys($value),
                    "Flat key '$key' still contains an associative array."
                );
            }
        }

        // Spot-check well-known keys exist.
        $this->assertArrayHasKey('identity_match_id',                      $flat);
        $this->assertArrayHasKey('core_elo_home_pre_match_elo',            $flat);
        $this->assertArrayHasKey('core_elo_elo_diff',                      $flat);
        $this->assertArrayHasKey('core_recent_home_avg_goals_for',         $flat);
        $this->assertArrayHasKey('core_structural_home_structural_rating', $flat);
        $this->assertArrayHasKey('core_structural_home_market_value',      $flat);
        $this->assertArrayHasKey('experimental_xg',                        $flat);
        $this->assertArrayHasKey('metadata_gaps',                          $flat);

        // Identity values survive flattening intact.
        $this->assertSame($this->targetMatch->id, $flat['identity_match_id']);
        $this->assertNull($flat['experimental_xg']);
    }

    // ── [K] Idempotency ───────────────────────────────────────────────────────

    public function test_aggregate_is_idempotent_for_the_same_match(): void
    {
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamHome->id,
            'away_team_id'   => $this->teamOpp->id,
            'kickoff_at'     => '2026-09-14 20:45:00',
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);

        $snap1 = PreMatchFeatureAggregator::aggregate($this->targetMatch);
        $snap2 = PreMatchFeatureAggregator::aggregate($this->targetMatch);

        // Strip generated_at (timestamp) before comparison.
        unset($snap1['metadata']['generated_at'], $snap2['metadata']['generated_at']);

        $this->assertSame($snap1, $snap2);
    }

    // ── [L] leakage_audit keys ────────────────────────────────────────────────

    public function test_metadata_leakage_audit_contains_entries_for_all_expected_blocks(): void
    {
        $snap  = PreMatchFeatureAggregator::aggregate($this->targetMatch);
        $audit = $snap['metadata']['leakage_audit'];

        $expectedBlocks = [
            'elo', 'recent', 'structural', 'schedule',
            'absence', 'continuity', 'age_profile',
            'league_context', 'opponent_quality', 'opponent_adjusted',
            'xg', 'h2h', 'time_decay',
        ];

        foreach ($expectedBlocks as $block) {
            $this->assertArrayHasKey($block, $audit, "leakage_audit missing key '$block'");
        }

        // All non-xG blocks claim CLEAN status.
        foreach ($expectedBlocks as $block) {
            if ($block === 'xg') {
                $this->assertStringContainsStringIgnoringCase('N/A', $audit[$block]);
            } else {
                $this->assertStringContainsStringIgnoringCase('CLEAN', $audit[$block],
                    "leakage_audit['$block'] should state CLEAN"
                );
            }
        }
    }
}
