<?php

namespace Tests\Unit\Services\Prediction;

use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\PredictionEngineV1;
use PHPUnit\Framework\TestCase;

/**
 * P18D — Candidate47 "ROBETTING CANDIDATE V2 LOG" runtime tests.
 *
 * Golden fixture: tests/Fixtures/Prediction/candidate47_structural_log_golden.json,
 * written by tools/scripts/generate_candidate47_structural_log_artifact.py with the
 * Python model's own lambdas/probabilities for 6 real training rows. Each case is
 * replayed through the full PHP runtime path (latent + structural snapshots →
 * log transform → MISSING30 → GLM → Bivariate Poisson), proving train/runtime parity.
 */
class CandidateModelServiceStructuralLogTest extends TestCase
{
    private const MODELS_DIR  = __DIR__ . '/../../../../tools/models';
    private const V1_GOLDEN   = __DIR__ . '/../../../Fixtures/Prediction/prediction_engine_v1_golden.json';
    private const LOG_GOLDEN  = __DIR__ . '/../../../Fixtures/Prediction/candidate47_structural_log_golden.json';
    private const LOG_FILE    = 'prediction_engine_candidate47_structural_log.json';
    private const RAW_FILE    = 'prediction_engine_candidate48_structural.json';

    private const HOME = 111;
    private const AWAY = 222;

    private string $tempDir;
    private array $baseFeatures59;

    protected function setUp(): void
    {
        parent::setUp();

        $v1 = json_decode(file_get_contents(self::V1_GOLDEN), true, 512, JSON_THROW_ON_ERROR);
        $this->baseFeatures59 = $v1['cases'][0]['features'];

        $v1Path = realpath(self::MODELS_DIR . '/prediction_engine_v1.json') ?: self::MODELS_DIR . '/prediction_engine_v1.json';
        PredictionEngineV1::setArtifactPath($v1Path);

        $this->tempDir = sys_get_temp_dir() . '/c47_log_test_' . uniqid();
        mkdir($this->tempDir);
        foreach ([self::LOG_FILE, self::RAW_FILE] as $file) {
            copy(realpath(self::MODELS_DIR) . '/' . $file, $this->tempDir . '/' . $file);
        }

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

    private function artifact(string $file): array
    {
        return json_decode(file_get_contents($this->tempDir . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
    }

    private function golden(): array
    {
        return json_decode(file_get_contents(self::LOG_GOLDEN), true, 512, JSON_THROW_ON_ERROR)['cases'];
    }

    /** Write both snapshots for HOME/AWAY with generated_at one day before kickoff. */
    private function writeSnapshots(string $kickoffAt, array $latent, float $top25Home, float $top25Away, string $offset = '-1 day'): void
    {
        $generatedAt = (new \DateTimeImmutable($kickoffAt))->modify($offset)->format(DATE_ATOM);

        file_put_contents($this->tempDir . '/latent_strength_current.json', json_encode([
            'generated_at'           => $generatedAt,
            'last_match_included_at' => $generatedAt,
            'model_version'          => 'test',
            'matches_used'           => 100,
            'teams'                  => [
                ['team_id' => self::HOME, 'attack' => $latent['latent_attack_home'], 'defence' => $latent['latent_defence_home']],
                ['team_id' => self::AWAY, 'attack' => $latent['latent_attack_away'], 'defence' => $latent['latent_defence_away']],
            ],
        ]));

        file_put_contents($this->tempDir . '/structural_strength_current.json', json_encode([
            'generated_at' => $generatedAt,
            'definition'   => 'top25_market_value',
            'teams'        => [
                (string) self::HOME => ['transfermarkt_club_id' => 1, 'team_name' => 'Home', 'players_count' => 25, 'top25_market_value' => $top25Home],
                (string) self::AWAY => ['transfermarkt_club_id' => 2, 'team_name' => 'Away', 'players_count' => 25, 'top25_market_value' => $top25Away],
            ],
        ]));
    }

    private function compareCase(array $case, string $offset = '-1 day'): array
    {
        $this->writeSnapshots($case['kickoff_at'], $case['latent'], $case['top25_home'], $case['top25_away'], $offset);
        CandidateModelService::setArtifactDir($this->tempDir); // clear per-request snapshot cache between cases
        $features = array_merge($this->baseFeatures59, $case['features39']);

        return (new CandidateModelService())->compare($features, self::HOME, self::AWAY, $case['kickoff_at']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_artifact_has_47_features_in_exact_order(): void
    {
        $log = $this->artifact(self::LOG_FILE);
        $raw = $this->artifact(self::RAW_FILE);

        $this->assertCount(47, $log['features']);
        foreach ([$log['imputer']['values'], $log['scaler']['mean'], $log['scaler']['scale'],
                  $log['home_model']['coefficients'], $log['away_model']['coefficients']] as $vector) {
            $this->assertCount(47, $vector);
        }

        // First 44 identical to Candidate V2 RAW (39 base + elo_gap_signed_square + 4 latent) ...
        $this->assertSame(array_slice($raw['features'], 0, 44), array_slice($log['features'], 0, 44));
        // ... then exactly the 3 log structural features, no raw ones.
        $this->assertSame(['structural_log_home', 'structural_log_away', 'structural_log_ratio'], array_slice($log['features'], 44));
        foreach (['structural_home', 'structural_away', 'structural_gap', 'structural_gap_signed_square'] as $removed) {
            $this->assertNotContains($removed, $log['features']);
        }

        $this->assertSame(0.15, (float) $log['lambda3']);
        $this->assertSame(1.0, (float) $log['alpha']);
        $this->assertSame($raw['missing30_features'], $log['missing30_features']);
        $this->assertSame((float) $raw['missing30_threshold'], (float) $log['missing30_threshold']);
    }

    /** @test */
    public function test_log_transformation_is_exact(): void
    {
        $f = CandidateModelService::structuralLogFeatures(424_000_000.0, 111_800_000.0);

        $this->assertSame(['structural_log_home', 'structural_log_away', 'structural_log_ratio'], array_keys($f));
        $this->assertEqualsWithDelta(log(424_000_000.0), $f['structural_log_home'], 1e-12);
        $this->assertEqualsWithDelta(log(111_800_000.0), $f['structural_log_away'], 1e-12);
        $this->assertEqualsWithDelta(log(424_000_000.0 / 111_800_000.0), $f['structural_log_ratio'], 1e-12);

        $this->assertNull(CandidateModelService::structuralLogFeatures(0.0, 111_800_000.0));
        $this->assertNull(CandidateModelService::structuralLogFeatures(424_000_000.0, -1.0));
    }

    /** @test */
    public function test_runtime_log_features_match_training_features(): void
    {
        foreach ($this->golden() as $case) {
            $f = CandidateModelService::structuralLogFeatures($case['top25_home'], $case['top25_away']);
            foreach (['structural_log_home', 'structural_log_away', 'structural_log_ratio'] as $k) {
                $this->assertEqualsWithDelta($case['expected'][$k], $f[$k], 1e-12, "match {$case['match_id']} {$k}");
            }
        }
    }

    /** @test */
    public function test_php_runtime_matches_python_training_predictions(): void
    {
        foreach ($this->golden() as $case) {
            $r = $this->compareCase($case);

            $this->assertTrue($r['candidate47_structural_log_available'], "match {$case['match_id']}");
            $p = $r['candidate47_structural_log'];
            foreach (['lambda_home', 'lambda_away', 'probability_home', 'probability_draw', 'probability_away'] as $k) {
                $this->assertEqualsWithDelta($case['expected'][$k], $p[$k], 1e-9, "match {$case['match_id']} {$k}");
            }
            $this->assertSame(0.15, $p['lambda3']);
        }
    }

    /** @test */
    public function test_probabilities_sum_to_one(): void
    {
        foreach ($this->golden() as $case) {
            $p = $this->compareCase($case)['candidate47_structural_log'];
            $this->assertEqualsWithDelta(1.0, $p['probability_home'] + $p['probability_draw'] + $p['probability_away'], 1e-9);
        }
    }

    /** @test */
    public function test_available_with_valid_snapshots_and_raw_kept_for_rollback(): void
    {
        $case = $this->golden()[0];
        $r    = $this->compareCase($case);

        $this->assertTrue($r['candidate47_structural_log_available']);
        $this->assertTrue($r['candidate48_structural_available']);
        $this->assertNotEquals($r['candidate48_structural']['probability_home'], $r['candidate47_structural_log']['probability_home']);
        $this->assertSame($r['candidate48_structural_inputs'], $r['candidate47_structural_log_inputs']);
        $this->assertEqualsWithDelta($case['top25_home'], $r['candidate47_structural_log_inputs']['structural_home'], 1e-6);
    }

    /** @test */
    public function test_unavailable_when_top25_not_positive(): void
    {
        $case = $this->golden()[0];
        $case['top25_away'] = 0.0;
        $r = $this->compareCase($case);

        $this->assertFalse($r['candidate47_structural_log_available']);
        $this->assertNull($r['candidate47_structural_log']);
        $this->assertNull($r['candidate47_structural_log_inputs']);
    }

    /** @test */
    public function test_unavailable_when_snapshot_not_strictly_before_kickoff(): void
    {
        $r = $this->compareCase($this->golden()[0], '+0 seconds');

        $this->assertFalse($r['candidate47_structural_log_available']);
        $this->assertFalse($r['candidate48_structural_available']);
    }

    /** @test */
    public function test_full59_unchanged(): void
    {
        $case     = $this->golden()[0];
        $features = array_merge($this->baseFeatures59, $case['features39']);

        $this->assertSame(PredictionEngineV1::predict($features), $this->compareCase($case)['full59']);
    }
}
