<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\Team;
use App\Models\TeamMarketValueSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the TeamMarketValueSnapshot model and its DB constraints.
 *
 *  [A]  Snapshot can be saved and retrieved with correct values
 *  [B]  Relationship team() returns the correct Team
 *  [C]  Duplicate (team_id, data_source_id, snapshot_date) is rejected
 *  [D]  Two snapshots for the same team on different dates are allowed
 */
class TeamMarketValueSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;
    private DataSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::create(['name' => 'Juventus', 'type' => 'club', 'is_active' => true]);

        $this->source = DataSource::create([
            'slug'        => 'transfermarkt',
            'name'        => 'Transfermarkt',
            'source_type' => 'manual',
            'is_active'   => true,
        ]);
    }

    // ─── [A] Snapshot saved and retrieved ────────────────────────────────────

    public function test_snapshot_can_be_saved_and_retrieved(): void
    {
        TeamMarketValueSnapshot::create([
            'team_id'        => $this->team->id,
            'data_source_id' => $this->source->id,
            'snapshot_date'  => '2026-09-01',
            'market_value'   => 684_000_000,
            'notes'          => 'Post-mercato estivo 2026',
        ]);

        $snapshot = TeamMarketValueSnapshot::first();

        $this->assertNotNull($snapshot);
        $this->assertSame($this->team->id, $snapshot->team_id);
        $this->assertSame($this->source->id, $snapshot->data_source_id);
        $this->assertEquals('2026-09-01', $snapshot->snapshot_date->toDateString());
        $this->assertSame(684_000_000, $snapshot->market_value);
        $this->assertSame('Post-mercato estivo 2026', $snapshot->notes);
    }

    // ─── [B] Relationship team() returns the correct Team ────────────────────

    public function test_snapshot_belongs_to_correct_team(): void
    {
        $snapshot = TeamMarketValueSnapshot::create([
            'team_id'        => $this->team->id,
            'data_source_id' => $this->source->id,
            'snapshot_date'  => '2026-09-01',
            'market_value'   => 684_000_000,
        ]);

        $this->assertTrue($snapshot->team->is($this->team));
    }

    // ─── [C] Duplicate (team_id, data_source_id, snapshot_date) rejected ─────

    public function test_duplicate_snapshot_for_same_team_source_and_date_is_rejected(): void
    {
        TeamMarketValueSnapshot::create([
            'team_id'        => $this->team->id,
            'data_source_id' => $this->source->id,
            'snapshot_date'  => '2026-09-01',
            'market_value'   => 684_000_000,
        ]);

        $this->expectException(QueryException::class);

        TeamMarketValueSnapshot::create([
            'team_id'        => $this->team->id,
            'data_source_id' => $this->source->id,
            'snapshot_date'  => '2026-09-01',
            'market_value'   => 700_000_000,
        ]);
    }

    // ─── [D] Two snapshots for same team on different dates are allowed ───────

    public function test_two_snapshots_for_same_team_on_different_dates_are_allowed(): void
    {
        TeamMarketValueSnapshot::create([
            'team_id'        => $this->team->id,
            'data_source_id' => $this->source->id,
            'snapshot_date'  => '2026-01-15',
            'market_value'   => 650_000_000,
            'notes'          => 'Pre-mercato invernale',
        ]);

        TeamMarketValueSnapshot::create([
            'team_id'        => $this->team->id,
            'data_source_id' => $this->source->id,
            'snapshot_date'  => '2026-09-01',
            'market_value'   => 684_000_000,
            'notes'          => 'Post-mercato estivo',
        ]);

        $this->assertSame(2, TeamMarketValueSnapshot::where('team_id', $this->team->id)->count());
    }
}
