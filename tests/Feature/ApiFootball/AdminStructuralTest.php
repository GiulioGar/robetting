<?php

namespace Tests\Feature\ApiFootball;

use App\Models\DataSource;
use App\Models\Team;
use App\Models\TeamMarketValueSnapshot;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Integration tests for the admin Structural Strength page.
 *
 *  [01]  Dashboard still renders OK in local env (no regression)
 *  [02]  Structural page returns 200 in local env
 *  [03]  Structural page shows "Nessuno snapshot" when no snapshots
 *  [04]  Structural page shows summary table when snapshots exist
 *  [05]  Structural rating shown in view is mathematically correct
 *  [06]  Upload valid JSON → preview row table visible on page
 *  [07]  Preview POST does not write any snapshots to DB
 *  [08]  Upload invalid JSON → error banner visible on page
 *  [09]  Confirm POST creates snapshots from session-stored JSON
 *  [10]  JSON file is saved to local storage on valid preview
 *  [11]  Preview shows NON MAPPATO badge for unmapped team
 *  [12]  Confirm with all-already_exists rows inserts nothing, DB unchanged
 *  [13]  Confirm POST without prior session → redirect with error
 *  [14]  Dashboard shows Forza Strutturale nav button (no regression)
 */
class AdminStructuralTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $tmSource;
    private Team $cityTeam;
    private Team $interTeam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
        Storage::fake('local');

        $this->app['env'] = 'local';

        $this->tmSource = DataSource::create([
            'slug'        => 'transfermarkt',
            'name'        => 'Transfermarkt',
            'source_type' => 'manual',
            'is_active'   => true,
        ]);

        $this->cityTeam  = Team::create(['name' => 'Manchester City', 'type' => 'club', 'is_active' => true]);
        $this->interTeam = Team::create(['name' => 'Inter',           'type' => 'club', 'is_active' => true]);
    }

    // ─── [01] Dashboard still renders ────────────────────────────────────────

    public function test_admin_dashboard_still_renders_ok_in_local_env(): void
    {
        $this->get(route('admin.api-football.dashboard'))
            ->assertOk();
    }

    // ─── [02] Structural page 200 ─────────────────────────────────────────────

    public function test_structural_page_returns_200_in_local_env(): void
    {
        $this->get(route('admin.api-football.structural'))
            ->assertOk()
            ->assertViewIs('admin.api-football.structural');
    }

    public function test_structural_page_returns_404_outside_local_env(): void
    {
        $this->app['env'] = 'testing';

        $this->get(route('admin.api-football.structural'))
            ->assertNotFound();
    }

    // ─── [03] "Nessuno snapshot" state ───────────────────────────────────────

    public function test_structural_page_shows_no_snapshot_message_when_empty(): void
    {
        // tmSource exists but no snapshots
        $this->get(route('admin.api-football.structural'))
            ->assertOk()
            ->assertSee('Nessuno snapshot disponibile');
    }

    // ─── [04] Summary shown when snapshots exist ──────────────────────────────

    public function test_structural_page_shows_team_table_when_snapshots_exist(): void
    {
        TeamMarketValueSnapshot::create([
            'team_id'        => $this->cityTeam->id,
            'data_source_id' => $this->tmSource->id,
            'snapshot_date'  => '2026-09-11',
            'market_value'   => 1_430_000_000,
        ]);

        $this->get(route('admin.api-football.structural'))
            ->assertOk()
            ->assertSee('Manchester City')
            ->assertSee('Transfermarkt')
            ->assertDontSee('Nessuno snapshot disponibile');
    }

    // ─── [05] Structural rating correct ──────────────────────────────────────

    public function test_structural_rating_for_reference_value_is_1500(): void
    {
        // market_value == reference_market_value (100_000_000) → rating = 1500
        TeamMarketValueSnapshot::create([
            'team_id'        => $this->interTeam->id,
            'data_source_id' => $this->tmSource->id,
            'snapshot_date'  => '2026-09-11',
            'market_value'   => 100_000_000,
        ]);

        // Italian number_format(1500, 1, ',', '.') = '1.500,0'
        $this->get(route('admin.api-football.structural'))
            ->assertOk()
            ->assertSee('1.500,0');
    }

    // ─── [06] Upload valid JSON → preview visible ─────────────────────────────

    public function test_upload_valid_json_shows_preview_on_page(): void
    {
        $this->post(route('admin.api-football.structural.preview'), [
            'json_file' => $this->makeJsonFile([
                ['team' => 'Manchester City', 'market_value' => 1_430_000_000],
                ['team' => 'Inter',           'market_value' => 684_000_000],
            ]),
        ])->assertRedirect(route('admin.api-football.structural'));

        $this->get(route('admin.api-football.structural'))
            ->assertOk()
            ->assertSee('Preview import')
            ->assertSee('Manchester City')
            ->assertSee('PRONTO');
    }

    // ─── [07] Preview does not write to DB ───────────────────────────────────

    public function test_preview_post_does_not_insert_any_snapshots(): void
    {
        $this->post(route('admin.api-football.structural.preview'), [
            'json_file' => $this->makeJsonFile([
                ['team' => 'Manchester City', 'market_value' => 1_430_000_000],
            ]),
        ]);

        $this->assertSame(0, TeamMarketValueSnapshot::count());
    }

    // ─── [08] Invalid JSON → error shown ─────────────────────────────────────

    public function test_invalid_json_upload_shows_error_on_page(): void
    {
        $badFile = UploadedFile::fake()->createWithContent('bad.json', '{not valid json');

        $this->post(route('admin.api-football.structural.preview'), [
            'json_file' => $badFile,
        ])->assertRedirect(route('admin.api-football.structural'));

        $this->get(route('admin.api-football.structural'))
            ->assertOk()
            ->assertSee('JSON non valido');
    }

    // ─── [09] Confirm creates snapshots ──────────────────────────────────────

    public function test_confirm_post_inserts_ok_rows_into_db(): void
    {
        // Step 1: preview (sets structural_pending_json in session)
        $this->post(route('admin.api-football.structural.preview'), [
            'json_file' => $this->makeJsonFile([
                ['team' => 'Manchester City', 'market_value' => 1_430_000_000],
                ['team' => 'Inter',           'market_value' => 684_000_000],
            ]),
        ]);

        $this->assertSame(0, TeamMarketValueSnapshot::count());

        // Step 2: confirm (reads from session)
        $this->post(route('admin.api-football.structural.confirm'))
            ->assertRedirect(route('admin.api-football.structural'));

        $this->assertSame(2, TeamMarketValueSnapshot::count());
    }

    public function test_confirm_flashes_inserted_count(): void
    {
        $this->post(route('admin.api-football.structural.preview'), [
            'json_file' => $this->makeJsonFile([
                ['team' => 'Manchester City', 'market_value' => 1_430_000_000],
            ]),
        ]);

        $this->post(route('admin.api-football.structural.confirm'))
            ->assertSessionHas('structural_confirm_result');

        $this->get(route('admin.api-football.structural'))
            ->assertSee('snapshot inseriti');
    }

    // ─── [10] JSON file saved to storage ─────────────────────────────────────

    public function test_valid_preview_saves_json_file_to_local_storage(): void
    {
        $this->post(route('admin.api-football.structural.preview'), [
            'json_file' => $this->makeJsonFile([
                ['team' => 'Manchester City', 'market_value' => 1_430_000_000],
            ], '2026-09-11'),
        ]);

        Storage::disk('local')->assertExists(
            'structural/market_values_transfermarkt_2026-09-11.json'
        );
    }

    public function test_second_upload_same_date_saves_with_numeric_suffix(): void
    {
        $file1 = $this->makeJsonFile([['team' => 'Manchester City', 'market_value' => 1_430_000_000]], '2026-09-11');
        $file2 = $this->makeJsonFile([['team' => 'Manchester City', 'market_value' => 1_430_000_000]], '2026-09-11');

        $this->post(route('admin.api-football.structural.preview'), ['json_file' => $file1]);
        $this->post(route('admin.api-football.structural.preview'), ['json_file' => $file2]);

        Storage::disk('local')->assertExists('structural/market_values_transfermarkt_2026-09-11.json');
        Storage::disk('local')->assertExists('structural/market_values_transfermarkt_2026-09-11-2.json');
    }

    // ─── [11] Unmapped / invalid rows shown in preview ───────────────────────

    public function test_preview_shows_non_mappato_badge_for_unknown_team(): void
    {
        $this->post(route('admin.api-football.structural.preview'), [
            'json_file' => $this->makeJsonFile([
                ['team' => 'Unknown FC', 'market_value' => 12_000_000],
            ]),
        ]);

        $this->get(route('admin.api-football.structural'))
            ->assertOk()
            ->assertSee('NON MAPPATO');
    }

    public function test_preview_shows_valore_non_valido_badge_for_zero_value(): void
    {
        $this->post(route('admin.api-football.structural.preview'), [
            'json_file' => $this->makeJsonFile([
                ['team' => 'Manchester City', 'market_value' => 0],
            ]),
        ]);

        $this->get(route('admin.api-football.structural'))
            ->assertOk()
            ->assertSee('VALORE NON VALIDO');
    }

    // ─── [12] Existing snapshot not overwritten ───────────────────────────────

    public function test_existing_snapshot_shows_already_exists_and_is_not_overwritten(): void
    {
        TeamMarketValueSnapshot::create([
            'team_id'        => $this->cityTeam->id,
            'data_source_id' => $this->tmSource->id,
            'snapshot_date'  => '2026-09-11',
            'market_value'   => 1_400_000_000,
        ]);

        // Preview the same date with different value
        $this->post(route('admin.api-football.structural.preview'), [
            'json_file' => $this->makeJsonFile([
                ['team' => 'Manchester City', 'market_value' => 1_500_000_000],
            ]),
        ]);

        // Verify preview shows "GIÀ PRESENTE"
        $this->get(route('admin.api-football.structural'))
            ->assertOk()
            ->assertSee('GIÀ PRESENTE');

        // Confirm (no ok rows, so nothing should be inserted)
        $this->post(route('admin.api-football.structural.confirm'));

        // Original value unchanged
        $snap = TeamMarketValueSnapshot::where('team_id', $this->cityTeam->id)->first();
        $this->assertSame(1_400_000_000, (int) $snap->market_value);
        $this->assertSame(1, TeamMarketValueSnapshot::count());
    }

    // ─── [13] Confirm without prior session returns error ────────────────────

    public function test_confirm_without_session_data_redirects_with_error(): void
    {
        // No preview POST before confirm → no structural_pending_json in session
        $this->post(route('admin.api-football.structural.confirm'))
            ->assertRedirect(route('admin.api-football.structural'))
            ->assertSessionHas('structural_upload_error');

        $this->assertSame(0, TeamMarketValueSnapshot::count());
    }

    public function test_confirm_error_message_visible_on_structural_page(): void
    {
        $this->post(route('admin.api-football.structural.confirm'));

        $this->get(route('admin.api-football.structural'))
            ->assertSee('Nessuna preview da confermare');
    }

    // ─── [14] Dashboard has Forza Strutturale nav (no regression) ────────────

    public function test_dashboard_nav_contains_forza_strutturale_link(): void
    {
        $this->get(route('admin.api-football.dashboard'))
            ->assertOk()
            ->assertSee('Forza Strutturale');
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function makeJsonFile(array $teams, string $date = '2026-09-11'): UploadedFile
    {
        $json = json_encode([
            'snapshot_date' => $date,
            'source'        => 'transfermarkt',
            'generated_at'  => $date . 'T00:00:00+00:00',
            'teams'         => $teams,
        ]);

        return UploadedFile::fake()->createWithContent('market_values.json', $json);
    }
}
