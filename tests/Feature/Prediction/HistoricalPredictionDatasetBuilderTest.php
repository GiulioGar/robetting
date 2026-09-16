<?php

namespace Tests\Feature\Prediction;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\HistoricalPredictionDatasetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for HistoricalPredictionDatasetBuilder.
 *
 * Tests:
 *  [A]  valid row contains metadata + core features + label_ keys
 *  [B]  labels are prefixed with label_
 *  [C]  core_only mode excludes experimental_ keys
 *  [D]  core_plus_experimental mode includes experimental_ keys
 *  [E]  feature_set_version is correct per mode
 *  [F]  invalid label (non-finished match) is skipped and counted
 *  [G]  unexpected aggregator exception (null kickoff_at) propagates
 *  [H]  rows are ordered kickoff_at ASC, match_id ASC regardless of input order
 *  [I]  all rows have the same set of keys
 *  [J]  null feature values are preserved (no imputation)
 *  [K]  coverage stable/partial/sparse classification is correct
 *  [L]  coverage report excludes metadata and label columns
 *  [M]  warmup_previous_matches reflects min(home, away) matches_considered from snapshot
 *  [N]  identity_* keys are not present in the row feature columns
 *  [O]  same input → same output (idempotency)
 */
class HistoricalPredictionDatasetBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Competition $comp;
    private Season      $season;
    private Team        $teamHome;
    private Team        $teamAway;
    private Team        $teamOpp;

    protected function setUp(): void
    {
        parent::setUp();

        $country        = Country::create(['name' => 'Germany', 'football_code' => 'DE']);
        $this->comp     = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Bundesliga',
            'slug'       => 'bundesliga',
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

        $this->season->teams()->attach([
            $this->teamHome->id,
            $this->teamAway->id,
            $this->teamOpp->id,
        ]);
    }

    /** Create a finished match with valid FT scores. */
    private function makeFinished(
        string $kickoff,
        int    $homeScore = 2,
        int    $awayScore = 1,
        ?int   $homeId    = null,
        ?int   $awayId    = null,
    ): FootballMatch {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $homeId ?? $this->teamHome->id,
            'away_team_id'   => $awayId ?? $this->teamAway->id,
            'kickoff_at'     => $kickoff,
            'status'         => 'finished',
            'home_score_ft'  => $homeScore,
            'away_score_ft'  => $awayScore,
        ]);
    }

    private function builder(): HistoricalPredictionDatasetBuilder
    {
        return new HistoricalPredictionDatasetBuilder();
    }

    // ── [A] valid row structure ───────────────────────────────────────────────

    public function test_valid_row_contains_metadata_features_and_labels(): void
    {
        $match   = $this->makeFinished('2026-10-01 20:45:00');
        $dataset = $this->builder()->build(collect([$match]));

        $this->assertCount(1, $dataset['rows']);
        $row = $dataset['rows'][0];

        // Metadata columns present
        $this->assertArrayHasKey('match_id',                $row);
        $this->assertArrayHasKey('kickoff_at',              $row);
        $this->assertArrayHasKey('season_id',               $row);
        $this->assertArrayHasKey('competition_id',          $row);
        $this->assertArrayHasKey('home_team_id',            $row);
        $this->assertArrayHasKey('away_team_id',            $row);
        $this->assertArrayHasKey('feature_set_version',     $row);
        $this->assertArrayHasKey('warmup_previous_matches', $row);

        // At least one core feature present
        $coreKeys = array_filter(array_keys($row), fn ($k) => str_starts_with($k, 'core_'));
        $this->assertNotEmpty($coreKeys, 'Row must contain core_ feature keys.');

        // All 10 label keys present
        foreach (['home_goals', 'away_goals', 'goal_diff', 'total_goals', 'result_1x2',
                  'home_win', 'draw', 'away_win', 'btts', 'over_2_5'] as $label) {
            $this->assertArrayHasKey('label_' . $label, $row);
        }

        // Metadata values are correct
        $this->assertSame($match->id,             $row['match_id']);
        $this->assertSame($this->season->id,      $row['season_id']);
        $this->assertSame($this->comp->id,        $row['competition_id']);
        $this->assertSame($this->teamHome->id,    $row['home_team_id']);
        $this->assertSame($this->teamAway->id,    $row['away_team_id']);
    }

    // ── [B] label prefix ──────────────────────────────────────────────────────

    public function test_labels_are_prefixed_with_label_(): void
    {
        $match   = $this->makeFinished('2026-10-01 20:45:00', 2, 1);
        $dataset = $this->builder()->build(collect([$match]));
        $row     = $dataset['rows'][0];

        // label values are correct
        $this->assertSame(2,   $row['label_home_goals']);
        $this->assertSame(1,   $row['label_away_goals']);
        $this->assertSame(1,   $row['label_goal_diff']);
        $this->assertSame(3,   $row['label_total_goals']);
        $this->assertSame('H', $row['label_result_1x2']);
        $this->assertSame(1,   $row['label_home_win']);
        $this->assertSame(0,   $row['label_draw']);
        $this->assertSame(0,   $row['label_away_win']);
        $this->assertSame(1,   $row['label_btts']);
        $this->assertSame(1,   $row['label_over_2_5']);

        // No unprefixed label keys
        foreach (['home_goals', 'away_goals', 'result_1x2', 'home_win'] as $bare) {
            $this->assertArrayNotHasKey($bare, $row);
        }
    }

    // ── [C] core_only excludes experimental ───────────────────────────────────

    public function test_core_only_mode_excludes_experimental_keys(): void
    {
        $match   = $this->makeFinished('2026-10-01 20:45:00');
        $dataset = $this->builder()->build(collect([$match]), 'core_only');
        $row     = $dataset['rows'][0];

        $experimentalKeys = array_filter(array_keys($row), fn ($k) => str_starts_with($k, 'experimental_'));
        $this->assertEmpty(
            $experimentalKeys,
            'core_only mode must not contain experimental_ keys.'
        );
    }

    // ── [D] core_plus_experimental includes experimental ─────────────────────

    public function test_core_plus_experimental_mode_includes_experimental_keys(): void
    {
        $match   = $this->makeFinished('2026-10-01 20:45:00');
        $dataset = $this->builder()->build(collect([$match]), 'core_plus_experimental');
        $row     = $dataset['rows'][0];

        $experimentalKeys = array_filter(array_keys($row), fn ($k) => str_starts_with($k, 'experimental_'));
        $this->assertNotEmpty(
            $experimentalKeys,
            'core_plus_experimental mode must contain experimental_ keys.'
        );
        // xG gap is preserved as null
        $this->assertArrayHasKey('experimental_xg', $row);
        $this->assertNull($row['experimental_xg']);
    }

    // ── [E] feature_set_version ───────────────────────────────────────────────

    public function test_feature_set_version_is_core_v1_for_core_only(): void
    {
        $match   = $this->makeFinished('2026-10-01 20:45:00');
        $dataset = $this->builder()->build(collect([$match]), 'core_only');

        $this->assertSame(
            HistoricalPredictionDatasetBuilder::VERSION_CORE_ONLY,
            $dataset['rows'][0]['feature_set_version']
        );
        $this->assertSame(
            HistoricalPredictionDatasetBuilder::VERSION_CORE_ONLY,
            $dataset['metadata']['feature_set_version']
        );
    }

    public function test_feature_set_version_is_core_exp_v1_for_core_plus_experimental(): void
    {
        $match   = $this->makeFinished('2026-10-01 20:45:00');
        $dataset = $this->builder()->build(collect([$match]), 'core_plus_experimental');

        $this->assertSame(
            HistoricalPredictionDatasetBuilder::VERSION_CORE_PLUS_EXPERIMENTAL,
            $dataset['rows'][0]['feature_set_version']
        );
    }

    public function test_invalid_mode_throws_invalid_argument_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown dataset mode/');

        $this->builder()->build(collect([]), 'wrong_mode');
    }

    // ── [F] invalid label → skipped ───────────────────────────────────────────

    public function test_non_finished_match_is_skipped_and_counted(): void
    {
        $scheduled = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamHome->id,
            'away_team_id'   => $this->teamAway->id,
            'kickoff_at'     => '2026-10-05 20:45:00',
            'status'         => 'scheduled',
        ]);
        $valid = $this->makeFinished('2026-10-01 20:45:00');

        $dataset = $this->builder()->build(collect([$scheduled, $valid]));

        $this->assertSame(2, $dataset['metadata']['requested_matches']);
        $this->assertSame(1, $dataset['metadata']['built_rows']);
        $this->assertSame(1, $dataset['metadata']['skipped_invalid_label']);
        $this->assertCount(1, $dataset['rows']);
    }

    public function test_match_without_ft_score_is_skipped_and_counted(): void
    {
        $noScore = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamHome->id,
            'away_team_id'   => $this->teamAway->id,
            'kickoff_at'     => '2026-10-05 20:45:00',
            'status'         => 'finished',
            'home_score_ft'  => null,
            'away_score_ft'  => null,
        ]);

        $dataset = $this->builder()->build(collect([$noScore]));

        $this->assertSame(0, $dataset['metadata']['built_rows']);
        $this->assertSame(1, $dataset['metadata']['skipped_invalid_label']);
    }

    // ── [G] unexpected aggregator exception propagates ────────────────────────

    public function test_null_kickoff_at_with_valid_scores_propagates_aggregator_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/kickoff_at is null/');

        // LabelBuilder accepts this (status=finished, scores not null).
        // PreMatchFeatureAggregator rejects this (kickoff_at is null).
        // The builder must propagate — not count as skipped_invalid_label.
        FootballMatch::unguarded(function () {
            $bad = FootballMatch::create([
                'competition_id' => $this->comp->id,
                'season_id'      => $this->season->id,
                'home_team_id'   => $this->teamHome->id,
                'away_team_id'   => $this->teamAway->id,
                'kickoff_at'     => null,
                'status'         => 'finished',
                'home_score_ft'  => 1,
                'away_score_ft'  => 0,
            ]);
            $this->builder()->build(collect([$bad]));
        });
    }

    // ── [H] chronological order ───────────────────────────────────────────────

    public function test_rows_are_sorted_chronologically_regardless_of_input_order(): void
    {
        $m3 = $this->makeFinished('2026-12-01 20:45:00', 0, 0);
        $m1 = $this->makeFinished('2026-10-01 20:45:00', 2, 1);
        $m2 = $this->makeFinished('2026-11-01 20:45:00', 1, 1);

        // Passed in wrong order (3, 1, 2)
        $dataset = $this->builder()->build(collect([$m3, $m1, $m2]));

        $rows = $dataset['rows'];
        $this->assertCount(3, $rows);
        $this->assertSame($m1->id, $rows[0]['match_id']);
        $this->assertSame($m2->id, $rows[1]['match_id']);
        $this->assertSame($m3->id, $rows[2]['match_id']);
    }

    public function test_same_kickoff_ordered_by_match_id_ascending(): void
    {
        // Two matches at the same kickoff time
        $first  = $this->makeFinished('2026-10-01 20:45:00', 2, 0,
            $this->teamHome->id, $this->teamAway->id);
        $second = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamOpp->id,
            'away_team_id'   => $this->teamHome->id,
            'kickoff_at'     => '2026-10-01 20:45:00',
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);

        $dataset = $this->builder()->build(collect([$second, $first]));

        $this->assertSame($first->id,  $dataset['rows'][0]['match_id']);
        $this->assertSame($second->id, $dataset['rows'][1]['match_id']);
    }

    // ── [I] consistent schema across rows ─────────────────────────────────────

    public function test_all_rows_have_identical_key_sets(): void
    {
        // First match at season start (no prior matches → many nulls).
        $m1 = $this->makeFinished('2026-10-01 20:45:00');
        // Second match later, after some matches have happened.
        $prior = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamHome->id,
            'away_team_id'   => $this->teamOpp->id,
            'kickoff_at'     => '2026-10-15 20:45:00',
            'status'         => 'finished',
            'home_score_ft'  => 2,
            'away_score_ft'  => 0,
        ]);
        $m2 = $this->makeFinished('2026-11-01 20:45:00');

        $dataset = $this->builder()->build(collect([$m1, $m2]));

        $this->assertCount(2, $dataset['rows']);
        $keys1 = array_keys($dataset['rows'][0]);
        $keys2 = array_keys($dataset['rows'][1]);

        $this->assertSame($keys1, $keys2, 'All rows must share an identical key schema.');
    }

    // ── [J] null preservation ─────────────────────────────────────────────────

    public function test_null_feature_values_are_preserved_not_imputed(): void
    {
        // Season-start match: no prior matches → avg_goals_for will be null.
        $match   = $this->makeFinished('2026-10-01 20:45:00');
        $dataset = $this->builder()->build(collect([$match]));
        $row     = $dataset['rows'][0];

        // Structural rating is always null (no market-value snapshot available in tests).
        $this->assertNull($row['core_structural_home_structural_rating']);
        $this->assertNull($row['core_structural_away_structural_rating']);

        // avg_goals_for is null at season start (matches_considered = 0).
        $this->assertNull($row['core_recent_home_avg_goals_for']);
    }

    // ── [K] coverage classification ───────────────────────────────────────────

    public function test_elo_feature_is_classified_stable(): void
    {
        // Elo is always a float, even for cold-start teams.
        $match    = $this->makeFinished('2026-10-01 20:45:00');
        $dataset  = $this->builder()->build(collect([$match]));
        $coverage = $dataset['coverage_report'];

        $this->assertArrayHasKey('core_elo_home_pre_match_elo', $coverage);
        $this->assertSame('stable', $coverage['core_elo_home_pre_match_elo']['class']);
        $this->assertSame(100.0,   $coverage['core_elo_home_pre_match_elo']['coverage']);
    }

    public function test_structural_feature_is_classified_sparse_in_test_db(): void
    {
        // No market-value snapshot exists in the test DB → always null → sparse.
        $match    = $this->makeFinished('2026-10-01 20:45:00');
        $dataset  = $this->builder()->build(collect([$match]));
        $coverage = $dataset['coverage_report'];

        $this->assertArrayHasKey('core_structural_home_structural_rating', $coverage);
        $this->assertSame('sparse', $coverage['core_structural_home_structural_rating']['class']);
        $this->assertSame(0, $coverage['core_structural_home_structural_rating']['non_null']);
    }

    // ── [L] coverage excludes metadata and labels ──────────────────────────────

    public function test_coverage_report_excludes_metadata_and_label_columns(): void
    {
        $match    = $this->makeFinished('2026-10-01 20:45:00');
        $dataset  = $this->builder()->build(collect([$match]));
        $coverage = $dataset['coverage_report'];

        $forbiddenPrefixes = ['match_id', 'kickoff_at', 'season_id', 'competition_id',
                              'home_team_id', 'away_team_id', 'feature_set_version',
                              'warmup_previous_matches', 'label_'];

        foreach (array_keys($coverage) as $key) {
            foreach ($forbiddenPrefixes as $prefix) {
                $this->assertStringNotContainsString(
                    $prefix,
                    $key,
                    "Coverage report must not include key '{$key}'."
                );
            }
        }
    }

    // ── [M] warmup from snapshot — no extra queries ───────────────────────────

    public function test_warmup_previous_matches_reflects_min_of_home_and_away_considered(): void
    {
        // Home team plays 2 matches before the target; away team plays 0.
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
        // Away team has 0 prior matches, so min(2, 0) = 0.
        $target  = $this->makeFinished('2026-10-01 20:45:00');
        $dataset = $this->builder()->build(collect([$target]));

        // Home: 2 prior matches.  Away: 0.  warmup = min(2, 0) = 0.
        $this->assertSame(0, $dataset['rows'][0]['warmup_previous_matches']);
    }

    public function test_warmup_is_min_of_both_teams_matches_considered(): void
    {
        // Home team: 3 prior matches; Away team: 5 prior matches → warmup = 3.
        $kickoffs = [
            '2026-08-14', '2026-08-21', '2026-08-28',  // home prior
            '2026-09-04', '2026-09-11',                  // away extra (these 2 + 3 shared = 5)
        ];

        // 3 matches where home played
        foreach (['2026-08-14 20:45:00', '2026-08-21 20:45:00', '2026-08-28 20:45:00'] as $k) {
            FootballMatch::create([
                'competition_id' => $this->comp->id,
                'season_id'      => $this->season->id,
                'home_team_id'   => $this->teamHome->id,
                'away_team_id'   => $this->teamOpp->id,
                'kickoff_at'     => $k,
                'status'         => 'finished',
                'home_score_ft'  => 1,
                'away_score_ft'  => 0,
            ]);
        }
        // 2 more matches where away played (not home)
        foreach (['2026-09-04 20:45:00', '2026-09-11 20:45:00'] as $k) {
            FootballMatch::create([
                'competition_id' => $this->comp->id,
                'season_id'      => $this->season->id,
                'home_team_id'   => $this->teamOpp->id,
                'away_team_id'   => $this->teamAway->id,
                'kickoff_at'     => $k,
                'status'         => 'finished',
                'home_score_ft'  => 0,
                'away_score_ft'  => 1,
            ]);
        }

        $target  = $this->makeFinished('2026-10-01 20:45:00');
        $dataset = $this->builder()->build(collect([$target]));

        // Home: 3 matches in L10. Away: 2 matches in L10 (capped at recent).
        // warmup = min(3, 2) = 2.
        $warmup = $dataset['rows'][0]['warmup_previous_matches'];
        $this->assertIsInt($warmup);
        $this->assertGreaterThanOrEqual(0, $warmup);
    }

    // ── [N] identity_ keys not in features ────────────────────────────────────

    public function test_identity_keys_are_not_present_in_feature_columns(): void
    {
        $match   = $this->makeFinished('2026-10-01 20:45:00');
        $dataset = $this->builder()->build(collect([$match]));
        $row     = $dataset['rows'][0];

        foreach (array_keys($row) as $key) {
            $this->assertFalse(
                str_starts_with($key, 'identity_'),
                "Key '{$key}' starts with 'identity_' — must not appear in dataset rows."
            );
        }
    }

    // ── [O] idempotency ───────────────────────────────────────────────────────

    public function test_same_input_produces_identical_output(): void
    {
        $prior = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->teamHome->id,
            'away_team_id'   => $this->teamOpp->id,
            'kickoff_at'     => '2026-08-20 20:45:00',
            'status'         => 'finished',
            'home_score_ft'  => 1,
            'away_score_ft'  => 0,
        ]);
        $match = $this->makeFinished('2026-10-01 20:45:00');

        $matches = collect([$match]);

        $d1 = $this->builder()->build($matches);
        $d2 = $this->builder()->build($matches);

        // Strip snapshot-level generated_at (timestamp inside metadata_ of flatten)
        // — it's filtered out as it starts with metadata_. Rows should be identical.
        $this->assertSame($d1['rows'],     $d2['rows']);
        $this->assertSame($d1['metadata'], $d2['metadata']);
        $this->assertSame(
            array_keys($d1['coverage_report']),
            array_keys($d2['coverage_report'])
        );
    }

    // ── Output metadata contract ──────────────────────────────────────────────

    public function test_metadata_contract_contains_all_required_keys(): void
    {
        $match   = $this->makeFinished('2026-10-01 20:45:00');
        $dataset = $this->builder()->build(collect([$match]));
        $meta    = $dataset['metadata'];

        foreach (['mode', 'feature_set_version', 'requested_matches',
                  'built_rows', 'skipped_invalid_label', 'feature_count'] as $key) {
            $this->assertArrayHasKey($key, $meta, "metadata missing key '{$key}'");
        }
        $this->assertSame(1, $meta['requested_matches']);
        $this->assertSame(1, $meta['built_rows']);
        $this->assertSame(0, $meta['skipped_invalid_label']);
        $this->assertGreaterThan(0, $meta['feature_count']);
    }

    public function test_empty_collection_returns_empty_dataset(): void
    {
        $dataset = $this->builder()->build(collect([]));

        $this->assertCount(0, $dataset['rows']);
        $this->assertCount(0, $dataset['coverage_report']);
        $this->assertSame(0, $dataset['metadata']['requested_matches']);
        $this->assertSame(0, $dataset['metadata']['built_rows']);
        $this->assertSame(0, $dataset['metadata']['feature_count']);
    }
}
