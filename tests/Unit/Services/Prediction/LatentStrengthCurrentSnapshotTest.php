<?php

namespace Tests\Unit\Services\Prediction;

use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\PredictionEngineV1;
use PHPUnit\Framework\TestCase;

/**
 * P19A — the real tools/models/latent_strength_current.json (generated from the
 * DB by tools/scripts/generate_latent_strength_snapshot.py) has the expected
 * schema/freshness metadata and is consumed by Candidate V2 LOG at runtime.
 */
class LatentStrengthCurrentSnapshotTest extends TestCase
{
    private const MODELS_DIR = __DIR__ . '/../../../../tools/models';
    private const V1_GOLDEN  = __DIR__ . '/../../../Fixtures/Prediction/prediction_engine_v1_golden.json';

    private array $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshot = json_decode(file_get_contents(self::MODELS_DIR . '/latent_strength_current.json'), true, 512, JSON_THROW_ON_ERROR);

        PredictionEngineV1::setArtifactPath(realpath(self::MODELS_DIR . '/prediction_engine_v1.json'));
        CandidateModelService::setArtifactDir(realpath(self::MODELS_DIR));
    }

    protected function tearDown(): void
    {
        CandidateModelService::reset();
        PredictionEngineV1::reset();
        parent::tearDown();
    }

    /** @test */
    public function test_schema_and_freshness_metadata(): void
    {
        $s = $this->snapshot;

        foreach (['generated_at', 'last_match_included_at', 'match_count', 'model_version', 'source', 'methodology', 'teams'] as $key) {
            $this->assertArrayHasKey($key, $s);
        }
        $this->assertSame('database', $s['source']);
        $this->assertIsInt($s['match_count']);
        $this->assertGreaterThan(0, $s['match_count']);
        $this->assertLessThan(strtotime($s['generated_at']), strtotime($s['last_match_included_at']), 'cutoff: last match must precede generated_at');
        $this->assertSame(3, $s['methodology']['prior_n']);

        $this->assertNotEmpty($s['teams']);
        $attackSum = $defenceSum = 0.0;
        foreach ($s['teams'] as $team) {
            $this->assertIsInt($team['team_id']);
            $this->assertIsFloat($team['attack']);
            $this->assertIsFloat($team['defence']);
            $attackSum  += $team['attack'];
            $defenceSum += $team['defence'];
        }
        // hard sum-to-zero reparametrization
        $this->assertEqualsWithDelta(0.0, $attackSum, 1e-9);
        $this->assertEqualsWithDelta(0.0, $defenceSum, 1e-9);
    }

    /** @test */
    public function test_candidate_v2_log_uses_real_snapshot_and_full59_is_unchanged(): void
    {
        $v1       = json_decode(file_get_contents(self::V1_GOLDEN), true, 512, JSON_THROW_ON_ERROR);
        $features = $v1['cases'][0]['features'];

        $structural = json_decode(file_get_contents(self::MODELS_DIR . '/structural_strength_current.json'), true)['teams'];
        $latentIds  = array_column($this->snapshot['teams'], 'team_id');
        $ids        = array_values(array_intersect($latentIds, array_map('intval', array_keys($structural))));
        [$home, $away] = [$ids[0], $ids[1]];

        $after = gmdate('Y-m-d\TH:i:s\Z', strtotime($this->snapshot['generated_at']) + 86400);
        $r     = (new CandidateModelService())->compare($features, $home, $away, $after);

        $this->assertTrue($r['candidate47_structural_log_available']);
        $p = $r['candidate47_structural_log'];
        $this->assertEqualsWithDelta(1.0, $p['probability_home'] + $p['probability_draw'] + $p['probability_away'], 1e-9);
        $this->assertSame(PredictionEngineV1::predict($features), $r['full59']);

        // Anti-leakage: a kickoff not strictly after generated_at cannot use the snapshot.
        CandidateModelService::setArtifactDir(realpath(self::MODELS_DIR));
        $same = (new CandidateModelService())->compare($features, $home, $away, $this->snapshot['generated_at']);
        $this->assertFalse($same['candidate47_structural_log_available']);
    }
}
