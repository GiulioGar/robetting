<?php

namespace Tests\Feature\Structural;

use App\Models\DataSource;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Models\TeamMarketValueSnapshot;
use App\Services\Structural\MarketValueImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for MarketValueImportService::preview() and ::confirm().
 *
 *  [01]  Valid JSON with multiple teams → preview summary correct
 *  [02]  JSON syntax error → valid=false, error message
 *  [03]  Source not found → valid=false
 *  [04]  Invalid snapshot_date → valid=false
 *  [05]  Team not in DB → status unmapped
 *  [06]  market_value <= 0 → status invalid_market_value
 *  [07]  market_value non-integer (float) → status invalid_market_value
 *  [07b] market_value non-integer (string) → status invalid_market_value
 *  [08]  Duplicate team in JSON → both rows status duplicate_in_file
 *  [09]  Snapshot already in DB → status already_exists
 *  [10]  Preview does not write to DB
 *  [11]  Confirm creates snapshots for ok rows
 *  [12]  Inserted row has correct data_source_id
 *  [13]  Inserted row has correct snapshot_date
 *  [14]  Second import same date: existing row not overwritten, skipped_existing incremented
 *  [15]  Mapping via teams.name (exact)
 *  [16]  Mapping via team_external_ids.external_name when teams.name doesn't match
 */
class MarketValueImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketValueImportService $service;
    private DataSource $source;
    private Team $cityTeam;
    private Team $interTeam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MarketValueImportService();

        $this->source = DataSource::create([
            'slug'        => 'transfermarkt',
            'name'        => 'Transfermarkt',
            'source_type' => 'manual',
            'is_active'   => true,
        ]);

        $this->cityTeam  = Team::create(['name' => 'Manchester City', 'type' => 'club', 'is_active' => true]);
        $this->interTeam = Team::create(['name' => 'Inter',            'type' => 'club', 'is_active' => true]);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function validJson(array $teams = [], string $date = '2026-09-11', string $source = 'transfermarkt'): string
    {
        if (empty($teams)) {
            $teams = [
                ['team' => 'Manchester City', 'market_value' => 1_430_000_000],
                ['team' => 'Inter',           'market_value' => 684_000_000],
            ];
        }
        return json_encode([
            'snapshot_date' => $date,
            'source'        => $source,
            'generated_at'  => '2026-09-11T01:30:00+02:00',
            'teams'         => $teams,
        ]);
    }

    // ─── [01] Valid JSON — happy path ─────────────────────────────────────────

    public function test_valid_json_produces_correct_preview_summary(): void
    {
        $preview = $this->service->preview($this->validJson());

        $this->assertTrue($preview['valid']);
        $this->assertNull($preview['error']);
        $this->assertSame('2026-09-11', $preview['snapshot_date']);
        $this->assertSame((int) $this->source->id, $preview['data_source_id']);

        $s = $preview['summary'];
        $this->assertSame(2, $s['total_teams']);
        $this->assertSame(2, $s['mapped_teams']);
        $this->assertSame(0, $s['unmapped_teams']);
        $this->assertSame(2, $s['valid_values']);
        $this->assertSame(0, $s['invalid_values']);
        $this->assertSame(0, $s['duplicate_teams']);
        $this->assertSame(0, $s['existing_snapshots']);
        $this->assertSame(2, $s['new_snapshots']);

        $this->assertCount(2, $preview['rows']);
        foreach ($preview['rows'] as $row) {
            $this->assertSame('ok', $row['status']);
        }
    }

    // ─── [02] JSON syntax error ────────────────────────────────────────────────

    public function test_invalid_json_syntax_returns_file_error(): void
    {
        $preview = $this->service->preview('{not valid json');

        $this->assertFalse($preview['valid']);
        $this->assertNotNull($preview['error']);
        $this->assertStringContainsString('JSON non valido', $preview['error']);
        $this->assertEmpty($preview['rows']);
        $this->assertNull($preview['summary']);
    }

    // ─── [03] Source not found ────────────────────────────────────────────────

    public function test_unknown_source_returns_file_error(): void
    {
        $preview = $this->service->preview($this->validJson(source: 'nonexistent-source'));

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('nonexistent-source', $preview['error']);
    }

    // ─── [04] Invalid snapshot_date ───────────────────────────────────────────

    public function test_invalid_snapshot_date_returns_file_error(): void
    {
        $preview = $this->service->preview($this->validJson(date: 'not-a-date'));

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('snapshot_date', $preview['error']);
    }

    public function test_out_of_range_date_returns_file_error(): void
    {
        $preview = $this->service->preview($this->validJson(date: '2026-13-01'));

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('snapshot_date', $preview['error']);
    }

    // ─── [05] Team not in DB → unmapped ──────────────────────────────────────

    public function test_unknown_team_name_is_marked_unmapped(): void
    {
        $preview = $this->service->preview($this->validJson(teams: [
            ['team' => 'Unknown FC', 'market_value' => 12_000_000],
        ]));

        $this->assertTrue($preview['valid']);
        $row = $preview['rows'][0];
        $this->assertSame('unmapped', $row['status']);
        $this->assertNull($row['team_id']);
        $this->assertSame('Unknown FC', $row['team_name']);
        $this->assertSame(1, $preview['summary']['unmapped_teams']);
        $this->assertSame(0, $preview['summary']['new_snapshots']);
    }

    // ─── [06] market_value <= 0 ───────────────────────────────────────────────

    public function test_zero_market_value_is_invalid(): void
    {
        $preview = $this->service->preview($this->validJson(teams: [
            ['team' => 'Manchester City', 'market_value' => 0],
        ]));

        $this->assertTrue($preview['valid']);
        $this->assertSame('invalid_market_value', $preview['rows'][0]['status']);
        $this->assertSame(1, $preview['summary']['invalid_values']);
    }

    public function test_negative_market_value_is_invalid(): void
    {
        $preview = $this->service->preview($this->validJson(teams: [
            ['team' => 'Manchester City', 'market_value' => -500_000],
        ]));

        $this->assertTrue($preview['valid']);
        $this->assertSame('invalid_market_value', $preview['rows'][0]['status']);
    }

    // ─── [07] market_value non-integer ───────────────────────────────────────

    public function test_float_market_value_is_invalid(): void
    {
        // JSON float: 1430000000.5 — PHP decodes as float, not int
        $json = '{"snapshot_date":"2026-09-11","source":"transfermarkt","teams":[{"team":"Manchester City","market_value":1430000000.5}]}';

        $preview = $this->service->preview($json);

        $this->assertTrue($preview['valid']);
        $this->assertSame('invalid_market_value', $preview['rows'][0]['status']);
        $this->assertStringContainsString('intero', $preview['rows'][0]['detail']);
    }

    public function test_string_market_value_is_invalid(): void
    {
        $json = '{"snapshot_date":"2026-09-11","source":"transfermarkt","teams":[{"team":"Manchester City","market_value":"1430000000"}]}';

        $preview = $this->service->preview($json);

        $this->assertTrue($preview['valid']);
        $this->assertSame('invalid_market_value', $preview['rows'][0]['status']);
    }

    // ─── [08] Duplicate team in JSON → both rows duplicate_in_file ───────────

    public function test_duplicate_team_in_file_marks_all_occurrences(): void
    {
        $preview = $this->service->preview($this->validJson(teams: [
            ['team' => 'Manchester City', 'market_value' => 1_430_000_000],
            ['team' => 'Manchester City', 'market_value' => 1_450_000_000],
            ['team' => 'Inter',           'market_value' => 684_000_000],
        ]));

        $this->assertTrue($preview['valid']);

        $statuses = array_column($preview['rows'], 'status');
        $this->assertSame('duplicate_in_file', $statuses[0]);
        $this->assertSame('duplicate_in_file', $statuses[1]);
        $this->assertSame('ok',                $statuses[2]);

        $this->assertSame(2, $preview['summary']['duplicate_teams']);
        $this->assertSame(1, $preview['summary']['new_snapshots']);
    }

    // ─── [09] Snapshot already in DB ─────────────────────────────────────────

    public function test_existing_snapshot_is_marked_already_exists(): void
    {
        TeamMarketValueSnapshot::create([
            'team_id'        => $this->cityTeam->id,
            'data_source_id' => $this->source->id,
            'snapshot_date'  => '2026-09-11',
            'market_value'   => 1_400_000_000,
        ]);

        $preview = $this->service->preview($this->validJson(teams: [
            ['team' => 'Manchester City', 'market_value' => 1_430_000_000],
        ]));

        $this->assertTrue($preview['valid']);
        $this->assertSame('already_exists', $preview['rows'][0]['status']);
        $this->assertSame(1, $preview['summary']['existing_snapshots']);
        $this->assertSame(0, $preview['summary']['new_snapshots']);
    }

    // ─── [10] Preview does not write to DB ───────────────────────────────────

    public function test_preview_does_not_write_to_database(): void
    {
        $this->service->preview($this->validJson());

        $this->assertSame(0, TeamMarketValueSnapshot::count());
    }

    // ─── [11] Confirm creates snapshots ──────────────────────────────────────

    public function test_confirm_inserts_ok_rows(): void
    {
        $preview = $this->service->preview($this->validJson());
        $result  = $this->service->confirm($preview);

        $this->assertSame(2, $result['inserted']);
        $this->assertSame(0, $result['skipped_existing']);
        $this->assertSame(2, $result['total_attempted']);
        $this->assertSame(2, TeamMarketValueSnapshot::count());
    }

    // ─── [12] Correct data_source_id ────────────────────────────────────────

    public function test_confirm_inserts_with_correct_data_source_id(): void
    {
        $preview = $this->service->preview($this->validJson(teams: [
            ['team' => 'Manchester City', 'market_value' => 1_430_000_000],
        ]));
        $this->service->confirm($preview);

        $snapshot = TeamMarketValueSnapshot::first();
        $this->assertSame((int) $this->source->id, (int) $snapshot->data_source_id);
    }

    // ─── [13] Correct snapshot_date ─────────────────────────────────────────

    public function test_confirm_inserts_with_correct_snapshot_date(): void
    {
        $preview = $this->service->preview($this->validJson(
            teams: [['team' => 'Manchester City', 'market_value' => 1_430_000_000]],
            date:  '2026-09-11'
        ));
        $this->service->confirm($preview);

        $snapshot = TeamMarketValueSnapshot::first();
        $this->assertSame('2026-09-11', $snapshot->snapshot_date->toDateString());
    }

    // ─── [14] No overwrite on second import ──────────────────────────────────

    public function test_second_import_same_date_does_not_overwrite(): void
    {
        $json = $this->validJson(teams: [
            ['team' => 'Manchester City', 'market_value' => 1_430_000_000],
        ]);

        // First import
        $p1 = $this->service->preview($json);
        $this->service->confirm($p1);

        // Second import with different value, same date
        $json2 = $this->validJson(teams: [
            ['team' => 'Manchester City', 'market_value' => 1_500_000_000],
        ]);
        $p2     = $this->service->preview($json2);
        $result = $this->service->confirm($p2);

        // Preview correctly detected it as already_exists
        $this->assertSame('already_exists', $p2['rows'][0]['status']);
        // Confirm skipped it (no ok rows)
        $this->assertSame(0, $result['inserted']);
        $this->assertSame(0, $result['total_attempted']);

        // Value in DB unchanged
        $snapshot = TeamMarketValueSnapshot::where('team_id', $this->cityTeam->id)->first();
        $this->assertSame(1_430_000_000, (int) $snapshot->market_value);
        $this->assertSame(1, TeamMarketValueSnapshot::count());
    }

    // ─── [15] Mapping via teams.name ────────────────────────────────────────

    public function test_team_is_mapped_via_exact_teams_name(): void
    {
        $preview = $this->service->preview($this->validJson(teams: [
            ['team' => 'Inter', 'market_value' => 684_000_000],
        ]));

        $this->assertSame('ok', $preview['rows'][0]['status']);
        $this->assertSame((int) $this->interTeam->id, $preview['rows'][0]['team_id']);
    }

    // ─── [16] Mapping via team_external_ids.external_name ────────────────────

    public function test_team_is_mapped_via_external_name_when_teams_name_differs(): void
    {
        // In Transfermarkt, Inter is listed as 'FC Internazionale Milano'
        TeamExternalId::create([
            'team_id'        => $this->interTeam->id,
            'data_source_id' => $this->source->id,
            'external_id'    => 'tm-46',
            'external_name'  => 'FC Internazionale Milano',
        ]);

        $preview = $this->service->preview($this->validJson(teams: [
            ['team' => 'FC Internazionale Milano', 'market_value' => 684_000_000],
        ]));

        $this->assertSame('ok', $preview['rows'][0]['status']);
        $this->assertSame((int) $this->interTeam->id, $preview['rows'][0]['team_id']);
    }

    public function test_external_name_does_not_bleed_across_sources(): void
    {
        $otherSource = DataSource::create([
            'slug'        => 'football-transfers',
            'name'        => 'FootballTransfers',
            'source_type' => 'manual',
            'is_active'   => true,
        ]);

        // external_name registered for the OTHER source, not transfermarkt
        TeamExternalId::create([
            'team_id'        => $this->interTeam->id,
            'data_source_id' => $otherSource->id,
            'external_id'    => 'ft-999',
            'external_name'  => 'FC Internazionale Milano',
        ]);

        // Importing via transfermarkt: this external_name must NOT be found
        $preview = $this->service->preview($this->validJson(teams: [
            ['team' => 'FC Internazionale Milano', 'market_value' => 684_000_000],
        ]));

        $this->assertSame('unmapped', $preview['rows'][0]['status']);
    }

    // ─── edge: confirm on invalid preview throws ──────────────────────────────

    public function test_confirm_on_invalid_preview_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);

        $badPreview = $this->service->preview('{bad json}');
        $this->service->confirm($badPreview);
    }
}
