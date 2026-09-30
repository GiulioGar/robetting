<?php

namespace Tests\Unit\Services\Prediction;

use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\PredictionEngineV1;
use PHPUnit\Framework\TestCase;

/**
 * P16B — Candidate48 "ROBETTING CANDIDATE V2 — STRUCTURAL" snapshot integration tests.
 *
 * Isolated temp artifact dir per test (copies the real candidate48_structural
 * artifact + controlled latent_strength_current.json / structural_strength_current.json
 * fixtures) so the anti-leakage timing scenarios are fully deterministic.
 *
 * Candidate48 needs BOTH the latent snapshot (already required by Candidate44)
 * AND the structural snapshot to be valid — this mirrors resolveLatentFeatures().
 */
class CandidateModelServiceStructuralSnapshotTest extends TestCase
{
    private const MODELS_DIR  = __DIR__ . '/../../../../tools/models';
    private const GOLDEN_PATH = __DIR__ . '/../../../Fixtures/Prediction/prediction_engine_v1_golden.json';

    private string $tempDir;
    private array $sampleFeatures;
    private string $sampleKickoff;

    protected function setUp(): void
    {
        parent::setUp();

        $golden = json_decode(file_get_contents(self::GOLDEN_PATH), true, 512, JSON_THROW_ON_ERROR);
        $case   = $golden['cases'][0];
        $this->sampleFeatures = $case['features'];
        $this->sampleKickoff  = $case['kickoff_at'];

        $v1Path = realpath(self::MODELS_DIR . '/prediction_engine_v1.json') ?: self::MODELS_DIR . '/prediction_engine_v1.json';
        PredictionEngineV1::setArtifactPath($v1Path);

        $this->tempDir = sys_get_temp_dir() . '/c48_snapshot_test_' . uniqid();
        mkdir($this->tempDir);

        copy(
            realpath(self::MODELS_DIR) . '/prediction_engine_candidate48_structural.json',
            $this->tempDir . '/prediction_engine_candidate48_structural.json',
        );

        CandidateModelService::setArtifactDir($this->tempDir);
    }

    protected function tearDown(): void
    {
        CandidateModelService::reset();
        PredictionEngineV1::reset();
        array_map('unlink', glob($this->tempDir . '/*'));
        rmdir($this->tempDir);
        parent::tearDown();
    }

    private function writeLatentSnapshot(string $generatedAt): void
    {
        file_put_contents(
            $this->tempDir . '/latent_strength_current.json',
            json_encode([
                'generated_at'           => $generatedAt,
                'last_match_included_at' => '2026-01-01T00:00:00Z',
                'model_version'          => 'test',
                'matches_used'           => 100,
                'teams'                  => [
                    ['team_id' => 111, 'attack' => 0.30, 'defence' => -0.20],
                    ['team_id' => 222, 'attack' => -0.10, 'defence' => 0.15],
                ],
            ])
        );
    }

    /**
     * @param  array<int, array{team_id: int, top25_market_value: int}>  $teams
     */
    private function writeStructuralSnapshot(string $generatedAt, array $teams): void
    {
        // Real schema (P16D) keys `teams` by team_id (string, JSON object) —
        // convert the flat test fixtures into that shape here.
        $teamsById = [];
        foreach ($teams as $team) {
            $teamsById[(string) $team['team_id']] = [
                'transfermarkt_club_id' => $team['team_id'],
                'team_name'             => 'Test Team ' . $team['team_id'],
                'players_count'         => 25,
                'top25_market_value'    => $team['top25_market_value'],
            ];
        }

        file_put_contents(
            $this->tempDir . '/structural_strength_current.json',
            json_encode([
                'generated_at' => $generatedAt,
                'definition'   => 'top25_market_value',
                'teams'        => $teamsById,
            ])
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Artifact structure
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_artifact_has_48_features_in_correct_order(): void
    {
        $artifact = json_decode(
            file_get_contents($this->tempDir . '/prediction_engine_candidate48_structural.json'),
            true
        );

        $this->assertCount(48, $artifact['features']);
        $this->assertCount(48, $artifact['imputer']['values']);
        $this->assertCount(48, $artifact['scaler']['mean']);
        $this->assertCount(48, $artifact['home_model']['coefficients']);

        // Last 9 features are elo-derived + latent + structural, in that exact order.
        $tail = array_slice($artifact['features'], -9);
        $this->assertSame([
            'elo_gap_signed_square',
            'latent_attack_home', 'latent_defence_home', 'latent_attack_away', 'latent_defence_away',
            'structural_home', 'structural_away', 'structural_gap', 'structural_gap_signed_square',
        ], $tail);

        $this->assertEqualsWithDelta(0.15, $artifact['lambda3'], 1e-9);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Availability
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_future_match_with_valid_snapshots_is_available(): void
    {
        $futureKickoff = date('Y-m-d\TH:i:s\Z', strtotime('+30 days'));
        $now = date('Y-m-d\TH:i:s\Z');

        $this->writeLatentSnapshot($now);
        $this->writeStructuralSnapshot($now, [
            ['team_id' => 111, 'top25_market_value' => 450_000_000],
            ['team_id' => 222, 'top25_market_value' => 120_000_000],
        ]);

        $service = new CandidateModelService();
        $result  = $service->compare($this->sampleFeatures, 111, 222, $futureKickoff);

        $this->assertTrue($result['candidate48_structural_available']);
        $this->assertNotNull($result['candidate48_structural']);
        $this->assertArrayHasKey('lambda_home', $result['candidate48_structural']);
        $this->assertArrayHasKey('lambda3', $result['candidate48_structural']);
        $this->assertEqualsWithDelta(0.15, $result['candidate48_structural']['lambda3'], 1e-9);

        $this->assertSame(450_000_000.0, $result['candidate48_structural_inputs']['structural_home']);
        $this->assertSame(120_000_000.0, $result['candidate48_structural_inputs']['structural_away']);
        $this->assertSame(330_000_000.0, $result['candidate48_structural_inputs']['structural_gap']);
    }

    /** @test */
    public function test_probabilities_sum_to_one(): void
    {
        $futureKickoff = date('Y-m-d\TH:i:s\Z', strtotime('+30 days'));
        $now = date('Y-m-d\TH:i:s\Z');

        $this->writeLatentSnapshot($now);
        $this->writeStructuralSnapshot($now, [
            ['team_id' => 111, 'top25_market_value' => 450_000_000],
            ['team_id' => 222, 'top25_market_value' => 120_000_000],
        ]);

        $service = new CandidateModelService();
        $result  = $service->compare($this->sampleFeatures, 111, 222, $futureKickoff);

        $this->assertTrue($result['candidate48_structural_available']);
        $sum = $result['candidate48_structural']['probability_home']
             + $result['candidate48_structural']['probability_draw']
             + $result['candidate48_structural']['probability_away'];
        $this->assertEqualsWithDelta(1.0, $sum, 1e-8);
    }

    /**
     * @test
     * Anti-leakage: structural snapshot generated AFTER kickoff must never be used,
     * even if the latent snapshot is valid.
     */
    public function test_structural_snapshot_generated_after_kickoff_is_unavailable(): void
    {
        $before = date('Y-m-d\TH:i:s\Z', strtotime($this->sampleKickoff) - 3600 * 24 * 365);
        $after  = date('Y-m-d\TH:i:s\Z', strtotime($this->sampleKickoff) + 3600 * 24 * 30);

        $this->writeLatentSnapshot($before);
        $this->writeStructuralSnapshot($after, [
            ['team_id' => 111, 'top25_market_value' => 450_000_000],
            ['team_id' => 222, 'top25_market_value' => 120_000_000],
        ]);

        $service = new CandidateModelService();
        $result  = $service->compare($this->sampleFeatures, 111, 222, $this->sampleKickoff);

        $this->assertFalse($result['candidate48_structural_available']);
        $this->assertNull($result['candidate48_structural']);
    }

    /** @test */
    public function test_structural_snapshot_equal_timestamp_is_unavailable(): void
    {
        $before = date('Y-m-d\TH:i:s\Z', strtotime($this->sampleKickoff) - 3600 * 24 * 365);

        $this->writeLatentSnapshot($before);
        $this->writeStructuralSnapshot($this->sampleKickoff, [
            ['team_id' => 111, 'top25_market_value' => 450_000_000],
            ['team_id' => 222, 'top25_market_value' => 120_000_000],
        ]);

        $service = new CandidateModelService();
        $result  = $service->compare($this->sampleFeatures, 111, 222, $this->sampleKickoff);

        $this->assertFalse($result['candidate48_structural_available']);
    }

    /** @test */
    public function test_structural_snapshot_missing_team_is_unavailable(): void
    {
        $before = date('Y-m-d\TH:i:s\Z', strtotime($this->sampleKickoff) - 3600 * 24 * 365);

        $this->writeLatentSnapshot($before);
        $this->writeStructuralSnapshot($before, [
            ['team_id' => 111, 'top25_market_value' => 450_000_000],
            // team 222 intentionally absent
        ]);

        $service = new CandidateModelService();
        $result  = $service->compare($this->sampleFeatures, 111, 222, $this->sampleKickoff);

        $this->assertFalse($result['candidate48_structural_available']);
        $this->assertNull($result['candidate48_structural']);
    }

    /**
     * @test
     * Reflects the current production blocker (P16B step 3): no generator exists
     * yet for structural_strength_current.json, so the file is simply absent.
     * Must degrade to unavailable, never fatal, never a fabricated value.
     */
    public function test_missing_structural_snapshot_file_is_unavailable_not_fatal(): void
    {
        $before = date('Y-m-d\TH:i:s\Z', strtotime($this->sampleKickoff) - 3600 * 24 * 365);
        $this->writeLatentSnapshot($before);
        // No writeStructuralSnapshot() call — file does not exist.

        $service = new CandidateModelService();
        $result  = $service->compare($this->sampleFeatures, 111, 222, $this->sampleKickoff);

        $this->assertFalse($result['candidate48_structural_available']);
        $this->assertNull($result['candidate48_structural']);
        $this->assertNull($result['candidate48_structural_inputs']);
        // full59 must still be computed — a missing optional artifact is never fatal.
        $this->assertArrayHasKey('lambda_home', $result['full59']);
    }

    /**
     * @test
     * FULL59 must be byte-identical whether or not Candidate48 succeeds.
     */
    public function test_full59_unchanged_by_candidate48_availability(): void
    {
        $withoutSnapshots = (new CandidateModelService())->compare($this->sampleFeatures);

        $before = date('Y-m-d\TH:i:s\Z', strtotime($this->sampleKickoff) - 3600 * 24 * 365);
        $this->writeLatentSnapshot($before);
        $this->writeStructuralSnapshot($before, [
            ['team_id' => 111, 'top25_market_value' => 450_000_000],
            ['team_id' => 222, 'top25_market_value' => 120_000_000],
        ]);
        $withSnapshots = (new CandidateModelService())->compare($this->sampleFeatures, 111, 222, $this->sampleKickoff);

        $this->assertSame($withoutSnapshots['full59'], $withSnapshots['full59']);
        $this->assertFalse($withoutSnapshots['candidate48_structural_available']);
        $this->assertTrue($withSnapshots['candidate48_structural_available']);
    }
}
