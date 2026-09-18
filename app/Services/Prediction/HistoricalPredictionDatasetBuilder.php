<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use App\Services\Analytics\PreMatchFeatureAggregator;
use App\Services\Analytics\TeamEloCalculator;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class HistoricalPredictionDatasetBuilder
{
    /**
     * Feature-set version identifiers.
     * Bump when the feature contract changes semantically (not just parametrically).
     */
    public const VERSION_CORE_ONLY              = 'core_v1';
    public const VERSION_CORE_PLUS_EXPERIMENTAL = 'core_exp_v1';

    private const MODE_VERSION_MAP = [
        'core_only'              => self::VERSION_CORE_ONLY,
        'core_plus_experimental' => self::VERSION_CORE_PLUS_EXPERIMENTAL,
    ];

    /**
     * Build a historical prediction dataset from a collection of finished matches.
     *
     * Each output row contains:
     *   - 8 metadata columns (match_id, kickoff_at, season_id, competition_id,
     *     home_team_id, away_team_id, feature_set_version, warmup_previous_matches)
     *   - N feature columns (core_ prefix; experimental_ prefix in core_plus_experimental mode)
     *   - 10 label columns (label_ prefix)
     *
     * Features and labels are generated independently — the aggregator never sees
     * the outcome; the label builder never sees the feature snapshot.
     *
     * Column schema is normalised via union across all rows so every row carries
     * the same keys. Missing values are null (no imputation).
     *
     * Rows are sorted kickoff_at ASC, match_id ASC.
     *
     * @param  Collection<FootballMatch>  $matches
     * @param  string                     $mode  'core_only'|'core_plus_experimental'
     * @return array{
     *   rows: array<int, array>,
     *   coverage_report: array<string, array{non_null: int, null: int, coverage: float, class: string}>,
     *   metadata: array{mode: string, feature_set_version: string,
     *     requested_matches: int, built_rows: int,
     *     skipped_invalid_label: int, feature_count: int},
     * }
     *
     * @throws InvalidArgumentException on unknown mode or unexpected aggregator failure
     */
    public function build(Collection $matches, string $mode = 'core_only'): array
    {
        if (! array_key_exists($mode, self::MODE_VERSION_MAP)) {
            throw new InvalidArgumentException(
                "Unknown dataset mode '{$mode}'. Valid: " . implode(', ', array_keys(self::MODE_VERSION_MAP))
            );
        }

        $featureSetVersion = self::MODE_VERSION_MAP[$mode];
        $skipped           = 0;
        $rawRows           = [];

        $sorted = $this->sortMatches($matches);

        // OPT-1C: one Elo replay per (competition_id, season_id) group for the entire
        // batch, instead of one replay per match. Passed to aggregate() as external
        // context; the internal per-match replay is then skipped.
        $bulkEloContext = $this->buildBulkEloContext($sorted);

        foreach ($sorted as $match) {
            // ── Step 1: labels — skip row if match is not a valid finished result ──
            try {
                $labels = MatchOutcomeLabelBuilder::build($match);
            } catch (InvalidArgumentException) {
                $skipped++;
                continue;
            }

            // ── Step 2: feature snapshot — unexpected exceptions propagate ────────
            $snapshot = PreMatchFeatureAggregator::aggregate($match, $bulkEloContext);
            $flat     = PreMatchFeatureAggregator::flatten($snapshot);

            // ── Step 3: warmup — read from snapshot, zero extra queries ──────────
            $warmup = min(
                (int) ($flat['core_recent_home_matches_considered'] ?? 0),
                (int) ($flat['core_recent_away_matches_considered'] ?? 0),
            );

            // ── Step 4: filter features by mode ──────────────────────────────────
            $features = $this->extractFeatures($flat, $mode);

            // ── Step 5: prefix labels ─────────────────────────────────────────────
            $prefixedLabels = [];
            foreach ($labels as $key => $value) {
                $prefixedLabels['label_' . $key] = $value;
            }

            // ── Step 6: assemble row ──────────────────────────────────────────────
            $rawRows[] = [
                'match_id'                => $match->id,
                'kickoff_at'              => $flat['identity_kickoff_at'],
                'season_id'               => $match->season_id,
                'competition_id'          => $match->competition_id,
                'home_team_id'            => $match->home_team_id,
                'away_team_id'            => $match->away_team_id,
                'feature_set_version'     => $featureSetVersion,
                'warmup_previous_matches' => $warmup,
            ] + $features + $prefixedLabels;
        }

        $rows     = $this->normalizeSchema($rawRows);
        $coverage = $this->buildCoverageReport($rows);

        $featureCount = empty($rows) ? 0 : count(array_filter(
            array_keys($rows[0]),
            static fn (string $k): bool =>
                str_starts_with($k, 'core_') || str_starts_with($k, 'experimental_')
        ));

        return [
            'rows'            => $rows,
            'coverage_report' => $coverage,
            'metadata'        => [
                'mode'                  => $mode,
                'feature_set_version'   => $featureSetVersion,
                'requested_matches'     => $matches->count(),
                'built_rows'            => count($rows),
                'skipped_invalid_label' => $skipped,
                'feature_count'         => $featureCount,
            ],
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /** @param  Collection<FootballMatch>  $matches */
    private function sortMatches(Collection $matches): Collection
    {
        $items = $matches->all();
        usort($items, static function (FootballMatch $a, FootballMatch $b): int {
            if ($a->kickoff_at === null && $b->kickoff_at === null) {
                return ($a->id ?? 0) <=> ($b->id ?? 0);
            }
            if ($a->kickoff_at === null) return -1;
            if ($b->kickoff_at === null) return 1;
            $cmp = $a->kickoff_at->timestamp <=> $b->kickoff_at->timestamp;
            return $cmp !== 0 ? $cmp : ($a->id ?? 0) <=> ($b->id ?? 0);
        });
        return collect($items);
    }

    /**
     * Pre-compute Elo context for a batch of target matches.
     *
     * Groups by (competition_id, season_id) so league_mean_elo stays correct per season.
     * Fetches all finished season matches once per group — covers every possible window
     * match that aggregate() will look up in E6/E9/E10/E12 — then runs one Elo replay
     * per group.
     *
     * For a single-season batch: 2 extra queries + 1 replay total (vs N replays before).
     *
     * Matches with null kickoff_at are excluded: they will either be skipped as invalid
     * labels or trigger an exception inside aggregate() — no Elo snapshot needed.
     */
    private function buildBulkEloContext(Collection $targetMatches): array
    {
        $valid = $targetMatches->filter(fn (FootballMatch $m) => $m->kickoff_at !== null);

        if ($valid->isEmpty()) {
            return [];
        }

        $groups  = $valid->groupBy(fn (FootballMatch $m) => $m->competition_id . '|' . $m->season_id);
        $context = [];

        foreach ($groups as $groupTargets) {
            /** @var FootballMatch $rep */
            $rep = $groupTargets->first();
            $rep->loadMissing(['season']);

            // All teams registered for this season — required for correct league_mean_elo.
            $leagueTeamIds = $rep->season->teams()->pluck('teams.id');

            // All finished matches in this competition+season.
            // The full season covers every possible window match across all targets in the group.
            $seasonMatches = FootballMatch::where('competition_id', $rep->competition_id)
                ->where('season_id', $rep->season_id)
                ->where('status', 'finished')
                ->whereNotNull('home_score_ft')
                ->whereNotNull('away_score_ft')
                ->get(['id', 'kickoff_at']);

            // Include target matches (may be 'scheduled') so their timestamps are captured.
            $all = $seasonMatches->merge($groupTargets)->unique('id');

            // Single Elo replay for this season group.
            $groupContext = TeamEloCalculator::calculateRatingsBeforeMatchesWithLeagueMean(
                $all,
                $leagueTeamIds
            );

            // + preserves integer keys (match IDs); array_merge would re-index them.
            $context = $context + $groupContext;
        }

        return $context;
    }

    private function extractFeatures(array $flat, string $mode): array
    {
        $features = [];
        foreach ($flat as $key => $value) {
            if (str_starts_with($key, 'core_')) {
                $features[$key] = $value;
                continue;
            }
            if ($mode === 'core_plus_experimental' && str_starts_with($key, 'experimental_')) {
                $features[$key] = $value;
            }
        }
        return $features;
    }

    /**
     * Normalise rows to a consistent, stable-ordered schema:
     * 1. Collect all keys across all rows (union).
     * 2. Order: fixed metadata columns, then feature keys (alpha), then label keys (alpha).
     * 3. Fill missing values with null.
     */
    private function normalizeSchema(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $allKeys = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $allKeys[$key] = true;
            }
        }

        $metaOrder   = [
            'match_id', 'kickoff_at', 'season_id', 'competition_id',
            'home_team_id', 'away_team_id', 'feature_set_version', 'warmup_previous_matches',
        ];
        $featureKeys = array_values(array_filter(
            array_keys($allKeys),
            static fn (string $k): bool =>
                str_starts_with($k, 'core_') || str_starts_with($k, 'experimental_')
        ));
        $labelKeys   = array_values(array_filter(
            array_keys($allKeys),
            static fn (string $k): bool => str_starts_with($k, 'label_')
        ));

        sort($featureKeys);
        sort($labelKeys);

        $nullRow = array_fill_keys(array_merge($metaOrder, $featureKeys, $labelKeys), null);

        return array_map(
            static fn (array $row): array => array_merge($nullRow, $row),
            $rows
        );
    }

    /**
     * Per-feature coverage statistics.
     * Only core_ and experimental_ columns are included.
     * Metadata and label columns are excluded.
     *
     * Stable: coverage >= 90%  |  Partial: 50-89%  |  Sparse: < 50%
     */
    private function buildCoverageReport(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $total   = count($rows);
        $report  = [];

        foreach (array_keys($rows[0]) as $key) {
            if (! str_starts_with($key, 'core_') && ! str_starts_with($key, 'experimental_')) {
                continue;
            }

            $nonNull = 0;
            foreach ($rows as $row) {
                if ($row[$key] !== null) {
                    $nonNull++;
                }
            }
            $null     = $total - $nonNull;
            $coverage = round($nonNull / $total * 100, 1);

            $report[$key] = [
                'non_null' => $nonNull,
                'null'     => $null,
                'coverage' => $coverage,
                'class'    => match (true) {
                    $coverage >= 90.0 => 'stable',
                    $coverage >= 50.0 => 'partial',
                    default           => 'sparse',
                },
            ];
        }

        return $report;
    }
}
