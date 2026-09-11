<?php

namespace Tests\Feature\Analytics;

use App\Models\DataSource;
use App\Models\Team;
use App\Models\TeamMarketValueSnapshot;
use App\Services\Analytics\TeamStructuralRatingCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for TeamStructuralRatingCalculator::calculateForTeamAtDate().
 *
 * Verifies snapshot selection logic, source isolation, no-leakage guarantee,
 * and correct delegation to calculateFromMarketValue().
 *
 *  [A]  Exact date match → all output fields present and correct
 *  [B]  Query between two snapshots → most recent prior snapshot is used
 *  [C]  Query after last snapshot → last snapshot is used
 *  [D]  Query before first snapshot → returns null (no leakage)
 *  [E]  Two sources: each dataSourceId returns only its own snapshot
 *  [F]  structural_raw and structural_rating match calculateFromMarketValue()
 */
class TeamStructuralRatingAtDateTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;
    private DataSource $source;

    private const DELTA = 0.001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);

        $this->source = DataSource::create([
            'slug'        => 'transfermarkt',
            'name'        => 'Transfermarkt',
            'source_type' => 'manual',
            'is_active'   => true,
        ]);
    }

    private function snap(string $date, int $marketValue, ?DataSource $source = null): void
    {
        TeamMarketValueSnapshot::create([
            'team_id'        => $this->team->id,
            'data_source_id' => ($source ?? $this->source)->id,
            'snapshot_date'  => $date,
            'market_value'   => $marketValue,
        ]);
    }

    // ─── [A] Exact date match — all output fields present and correct ────────

    public function test_exact_date_match_returns_all_fields(): void
    {
        $this->snap('2026-09-01', 485_000_000);

        $result = TeamStructuralRatingCalculator::calculateForTeamAtDate(
            $this->team->id,
            Carbon::parse('2026-09-01'),
            $this->source->id
        );

        $this->assertNotNull($result);

        // team and source identity
        $this->assertSame((int) $this->team->id,   $result['team_id']);
        $this->assertSame((int) $this->source->id, $result['data_source_id']);

        // snapshot fields
        $this->assertEquals('2026-09-01', $result['snapshot_date']->toDateString());
        $this->assertSame(485_000_000, $result['market_value']);

        // computed fields match pure calculator
        $expected = TeamStructuralRatingCalculator::calculateFromMarketValue(485_000_000);
        $this->assertEqualsWithDelta($expected['structural_raw'],    $result['structural_raw'],    self::DELTA);
        $this->assertEqualsWithDelta($expected['structural_rating'], $result['structural_rating'], self::DELTA);
    }

    // ─── [B] Date between two snapshots → most recent prior snapshot ─────────
    //
    // Snapshots: 2026-09-01 = 485M, 2027-02-05 = 510M
    // Query: 2027-01-10 → must use 485M
    // ─────────────────────────────────────────────────────────────────────────

    public function test_query_between_snapshots_uses_most_recent_prior(): void
    {
        $this->snap('2026-09-01', 485_000_000);
        $this->snap('2027-02-05', 510_000_000);

        $result = TeamStructuralRatingCalculator::calculateForTeamAtDate(
            $this->team->id,
            Carbon::parse('2027-01-10'),
            $this->source->id
        );

        $this->assertNotNull($result);
        $this->assertSame(485_000_000, $result['market_value']);
        $this->assertEquals('2026-09-01', $result['snapshot_date']->toDateString());
    }

    // ─── [C] Query after last snapshot → last snapshot is used ───────────────
    //
    // Query: 2027-04-01 (after 2027-02-05) → must use 510M
    // ─────────────────────────────────────────────────────────────────────────

    public function test_query_after_last_snapshot_uses_last_snapshot(): void
    {
        $this->snap('2026-09-01', 485_000_000);
        $this->snap('2027-02-05', 510_000_000);

        $result = TeamStructuralRatingCalculator::calculateForTeamAtDate(
            $this->team->id,
            Carbon::parse('2027-04-01'),
            $this->source->id
        );

        $this->assertNotNull($result);
        $this->assertSame(510_000_000, $result['market_value']);
        $this->assertEquals('2027-02-05', $result['snapshot_date']->toDateString());
    }

    // ─── [D] Query before first snapshot → null (no leakage) ────────────────
    //
    // Query: 2026-08-01, snapshot only from 2026-09-01 → must return null
    // ─────────────────────────────────────────────────────────────────────────

    public function test_query_before_first_snapshot_returns_null(): void
    {
        $this->snap('2026-09-01', 485_000_000);

        $result = TeamStructuralRatingCalculator::calculateForTeamAtDate(
            $this->team->id,
            Carbon::parse('2026-08-01'),
            $this->source->id
        );

        $this->assertNull($result);
    }

    // ─── [E] Two sources: explicit dataSourceId selects only that source ─────
    //
    // Source A has 2026-09-01 = 485M.
    // Source B has 2026-09-01 = 600M.
    // Querying with source B must return 600M, not 485M.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_explicit_data_source_id_isolates_source(): void
    {
        $sourceB = DataSource::create([
            'slug'        => 'football-transfers',
            'name'        => 'FootballTransfers',
            'source_type' => 'manual',
            'is_active'   => true,
        ]);

        $this->snap('2026-09-01', 485_000_000, $this->source);
        $this->snap('2026-09-01', 600_000_000, $sourceB);

        $resultA = TeamStructuralRatingCalculator::calculateForTeamAtDate(
            $this->team->id,
            Carbon::parse('2026-09-01'),
            $this->source->id
        );

        $resultB = TeamStructuralRatingCalculator::calculateForTeamAtDate(
            $this->team->id,
            Carbon::parse('2026-09-01'),
            $sourceB->id
        );

        $this->assertSame(485_000_000, $resultA['market_value']);
        $this->assertSame(600_000_000, $resultB['market_value']);
        $this->assertSame((int) $this->source->id, $resultA['data_source_id']);
        $this->assertSame((int) $sourceB->id,      $resultB['data_source_id']);
    }

    // ─── [F] Rating matches calculateFromMarketValue() ───────────────────────

    public function test_returned_rating_matches_pure_calculator(): void
    {
        $this->snap('2026-09-01', 485_000_000);

        $result = TeamStructuralRatingCalculator::calculateForTeamAtDate(
            $this->team->id,
            Carbon::parse('2026-09-01'),
            $this->source->id
        );

        $expected = TeamStructuralRatingCalculator::calculateFromMarketValue(485_000_000);

        $this->assertEqualsWithDelta($expected['structural_raw'],    $result['structural_raw'],    self::DELTA);
        $this->assertEqualsWithDelta($expected['structural_rating'], $result['structural_rating'], self::DELTA);
    }
}
