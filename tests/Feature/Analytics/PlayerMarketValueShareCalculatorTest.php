<?php

namespace Tests\Feature\Analytics;

use App\Models\DataSource;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\Team;
use App\Services\Analytics\PlayerMarketValueShareCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for PlayerMarketValueShareCalculator (Availability V1, Step 6A/6B).
 * Rules under test:
 *  [A] player correctly mapped → market_value and share computed correctly
 *  [B] share formula is exactly market_value / team_top25_market_value
 *  [C] player with no Transfermarkt mapping → all null
 *  [D] tm_player_id not present in the artifact's team roster → market_value null
 *  [E] team missing from the Structural strength artifact → top25 null
 *  [F] top25_market_value present but zero/invalid → null, never 0
 *  [G] no name-based matching: a same-name player under a DIFFERENT
 *      tm_player_id must never resolve
 *  [H] Step 6B anti-leakage: an artifact generated AFTER cutoff is unusable
 *  [I] Step 6B: each artifact's generated_at is checked independently
 */
class PlayerMarketValueShareCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $tmDs;
    private Team $teamA;
    private string $artifactDir;
    private Carbon $cutoff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmDs = DataSource::create([
            'slug'        => 'transfermarkt',
            'name'        => 'Transfermarkt',
            'source_type' => 'scraper',
        ]);

        $this->teamA  = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->cutoff = Carbon::parse('2026-09-10 20:45:00');

        $this->artifactDir = sys_get_temp_dir() . '/pmvs_test_' . uniqid();
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
    // [A]/[B] Correct mapping and formula
    // ─────────────────────────────────────────────────────────────────────────

    public function test_mapped_player_computes_correct_share(): void
    {
        $player = Player::create(['name' => 'Lautaro']);
        PlayerExternalId::create([
            'player_id'      => $player->id,
            'data_source_id' => $this->tmDs->id,
            'external_id'    => '111222',
        ]);

        $this->writePlayersArtifact([
            (string) $this->teamA->id => [
                'players' => [
                    ['tm_player_id' => 111222, 'name' => 'Lautaro Martinez', 'market_value' => 90000000],
                ],
            ],
        ]);
        $this->writeStrengthArtifact([
            (string) $this->teamA->id => ['top25_market_value' => 900000000],
        ]);

        $result = PlayerMarketValueShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(90000000, $result['market_value']);
        $this->assertSame(900000000, $result['team_top25_market_value']);
        $this->assertSame(0.1, $result['market_value_share_top25']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] No Transfermarkt mapping at all → fail-closed
    // ─────────────────────────────────────────────────────────────────────────

    public function test_player_without_transfermarkt_mapping_returns_null(): void
    {
        $player = Player::create(['name' => 'Unmapped']);

        $this->writePlayersArtifact([
            (string) $this->teamA->id => ['players' => [
                ['tm_player_id' => 999, 'name' => 'Someone Else', 'market_value' => 50000000],
            ]],
        ]);
        $this->writeStrengthArtifact([
            (string) $this->teamA->id => ['top25_market_value' => 500000000],
        ]);

        $result = PlayerMarketValueShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertNull($result['market_value']);
        $this->assertSame(500000000, $result['team_top25_market_value']); // team-level lookup still succeeds
        $this->assertNull($result['market_value_share_top25']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] tm_player_id not present in the team's roster in the artifact
    // ─────────────────────────────────────────────────────────────────────────

    public function test_tm_player_id_missing_from_artifact_returns_null_market_value(): void
    {
        $player = Player::create(['name' => 'Ghost']);
        PlayerExternalId::create([
            'player_id'      => $player->id,
            'data_source_id' => $this->tmDs->id,
            'external_id'    => '555000',
        ]);

        $this->writePlayersArtifact([
            (string) $this->teamA->id => ['players' => [
                ['tm_player_id' => 1, 'name' => 'Nobody', 'market_value' => 1000000],
            ]],
        ]);
        $this->writeStrengthArtifact([
            (string) $this->teamA->id => ['top25_market_value' => 200000000],
        ]);

        $result = PlayerMarketValueShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertNull($result['market_value']);
        $this->assertNull($result['market_value_share_top25']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] Team missing from the Structural strength artifact
    // ─────────────────────────────────────────────────────────────────────────

    public function test_team_missing_from_structural_artifact_returns_null_top25(): void
    {
        $player = Player::create(['name' => 'Mapped']);
        PlayerExternalId::create([
            'player_id'      => $player->id,
            'data_source_id' => $this->tmDs->id,
            'external_id'    => '222333',
        ]);

        $this->writePlayersArtifact([
            (string) $this->teamA->id => ['players' => [
                ['tm_player_id' => 222333, 'name' => 'Mapped Guy', 'market_value' => 10000000],
            ]],
        ]);
        $this->writeStrengthArtifact([]); // team absent entirely

        $result = PlayerMarketValueShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(10000000, $result['market_value']);
        $this->assertNull($result['team_top25_market_value']);
        $this->assertNull($result['market_value_share_top25']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] top25_market_value present but zero/invalid → null, never 0
    // ─────────────────────────────────────────────────────────────────────────

    public function test_zero_top25_market_value_returns_null_not_zero(): void
    {
        $player = Player::create(['name' => 'Mapped2']);
        PlayerExternalId::create([
            'player_id'      => $player->id,
            'data_source_id' => $this->tmDs->id,
            'external_id'    => '333444',
        ]);

        $this->writePlayersArtifact([
            (string) $this->teamA->id => ['players' => [
                ['tm_player_id' => 333444, 'name' => 'Mapped Guy2', 'market_value' => 5000000],
            ]],
        ]);
        $this->writeStrengthArtifact([
            (string) $this->teamA->id => ['top25_market_value' => 0],
        ]);

        $result = PlayerMarketValueShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(5000000, $result['market_value']);
        $this->assertNull($result['team_top25_market_value']);
        $this->assertNull($result['market_value_share_top25']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] No name-based matching
    // ─────────────────────────────────────────────────────────────────────────

    public function test_same_name_different_tm_id_never_resolves(): void
    {
        $player = Player::create(['name' => 'Jonas Martin']);
        PlayerExternalId::create([
            'player_id'      => $player->id,
            'data_source_id' => $this->tmDs->id,
            'external_id'    => '700001',
        ]);

        // Artifact has a player with the EXACT SAME name but a DIFFERENT tm_player_id.
        $this->writePlayersArtifact([
            (string) $this->teamA->id => ['players' => [
                ['tm_player_id' => 700002, 'name' => 'Jonas Martin', 'market_value' => 7000000],
            ]],
        ]);
        $this->writeStrengthArtifact([
            (string) $this->teamA->id => ['top25_market_value' => 100000000],
        ]);

        $result = PlayerMarketValueShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertNull($result['market_value']);
        $this->assertNull($result['market_value_share_top25']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] Step 6B — artifact generated AFTER cutoff is unusable
    // ─────────────────────────────────────────────────────────────────────────

    public function test_artifact_generated_after_cutoff_is_unusable(): void
    {
        $player = Player::create(['name' => 'Future']);
        PlayerExternalId::create([
            'player_id'      => $player->id,
            'data_source_id' => $this->tmDs->id,
            'external_id'    => '444555',
        ]);

        $this->writePlayersArtifact([
            (string) $this->teamA->id => ['players' => [
                ['tm_player_id' => 444555, 'name' => 'Future Guy', 'market_value' => 20000000],
            ]],
        ], $this->cutoff->copy()->addDay()); // generated AFTER cutoff
        $this->writeStrengthArtifact([
            (string) $this->teamA->id => ['top25_market_value' => 300000000],
        ], $this->cutoff->copy()->addDay());

        $result = PlayerMarketValueShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertNull($result['market_value']);
        $this->assertNull($result['team_top25_market_value']);
        $this->assertNull($result['market_value_share_top25']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] Step 6B — each artifact's generated_at is checked independently
    // ─────────────────────────────────────────────────────────────────────────

    public function test_only_the_too_recent_artifact_is_discarded(): void
    {
        $player = Player::create(['name' => 'HalfStale']);
        PlayerExternalId::create([
            'player_id'      => $player->id,
            'data_source_id' => $this->tmDs->id,
            'external_id'    => '666777',
        ]);

        // Players artifact: generated BEFORE cutoff — usable.
        $this->writePlayersArtifact([
            (string) $this->teamA->id => ['players' => [
                ['tm_player_id' => 666777, 'name' => 'Half Stale', 'market_value' => 8000000],
            ]],
        ], $this->cutoff->copy()->subDay());

        // Strength artifact: generated AFTER cutoff — unusable.
        $this->writeStrengthArtifact([
            (string) $this->teamA->id => ['top25_market_value' => 400000000],
        ], $this->cutoff->copy()->addHour());

        $result = PlayerMarketValueShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(8000000, $result['market_value']); // players artifact still usable
        $this->assertNull($result['team_top25_market_value']); // strength artifact discarded
        $this->assertNull($result['market_value_share_top25']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function writePlayersArtifact(array $teams, ?Carbon $generatedAt = null): void
    {
        $generatedAt ??= $this->cutoff->copy()->subDay();
        file_put_contents(
            $this->artifactDir . '/structural_players_current.json',
            json_encode(['generated_at' => $generatedAt->toIso8601String(), 'definition' => 'squad_players_market_value', 'teams' => $teams])
        );
    }

    private function writeStrengthArtifact(array $teams, ?Carbon $generatedAt = null): void
    {
        $generatedAt ??= $this->cutoff->copy()->subDay();
        file_put_contents(
            $this->artifactDir . '/structural_strength_current.json',
            json_encode(['generated_at' => $generatedAt->toIso8601String(), 'definition' => 'top25_market_value', 'teams' => $teams])
        );
    }
}
