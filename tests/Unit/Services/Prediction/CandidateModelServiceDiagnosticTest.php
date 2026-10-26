<?php

namespace Tests\Unit\Services\Prediction;

use App\Services\Prediction\CandidateModelService;
use PHPUnit\Framework\TestCase;

/**
 * diagnoseLatentStructuralAvailability() — display-only diagnostic for the
 * match page (Champions League visual-test step). Never affects inference.
 */
class CandidateModelServiceDiagnosticTest extends TestCase
{
    private const SAMPLE_KICKOFF = '2026-10-20T19:00:00Z';

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/c_diag_test_' . uniqid();
        mkdir($this->tempDir);
        CandidateModelService::setArtifactDir($this->tempDir);
    }

    protected function tearDown(): void
    {
        CandidateModelService::reset();
        array_map('unlink', glob($this->tempDir . '/*'));
        rmdir($this->tempDir);
        parent::tearDown();
    }

    private function writeLatentSnapshot(string $generatedAt, array $teamIds): void
    {
        file_put_contents(
            $this->tempDir . '/latent_strength_current.json',
            json_encode([
                'generated_at'           => $generatedAt,
                'last_match_included_at' => '2026-01-01T00:00:00Z',
                'teams'                  => array_map(
                    fn ($id) => ['team_id' => $id, 'attack' => 0.1, 'defence' => -0.1],
                    $teamIds
                ),
            ])
        );
    }

    private function writeStructuralSnapshot(string $generatedAt, array $teamIds): void
    {
        $teamsById = [];
        foreach ($teamIds as $id) {
            $teamsById[(string) $id] = [
                'transfermarkt_club_id' => $id,
                'team_name'             => 'Test Team ' . $id,
                'players_count'         => 25,
                'top25_market_value'    => 100000000,
            ];
        }
        file_put_contents(
            $this->tempDir . '/structural_strength_current.json',
            json_encode(['generated_at' => $generatedAt, 'definition' => 'test', 'teams' => $teamsById])
        );
    }

    /** @test */
    public function test_both_teams_present_in_both_snapshots_reports_nothing_missing(): void
    {
        $before = '2025-01-01T00:00:00Z';
        $this->writeLatentSnapshot($before, [278, 414]);
        $this->writeStructuralSnapshot($before, [278, 414]);

        $result = (new CandidateModelService())->diagnoseLatentStructuralAvailability(278, 414, self::SAMPLE_KICKOFF);

        $this->assertFalse($result['latent_missing_home']);
        $this->assertFalse($result['latent_missing_away']);
        $this->assertFalse($result['structural_missing_home']);
        $this->assertFalse($result['structural_missing_away']);
    }

    /** @test */
    public function test_away_team_missing_from_both_snapshots_reports_away_only(): void
    {
        // Napoli (278) present, Bodo/Glimt (414) absent — reproduces the real fixture.
        $before = '2025-01-01T00:00:00Z';
        $this->writeLatentSnapshot($before, [278]);
        $this->writeStructuralSnapshot($before, [278]);

        $result = (new CandidateModelService())->diagnoseLatentStructuralAvailability(278, 414, self::SAMPLE_KICKOFF);

        $this->assertFalse($result['latent_missing_home']);
        $this->assertTrue($result['latent_missing_away']);
        $this->assertFalse($result['structural_missing_home']);
        $this->assertTrue($result['structural_missing_away']);
    }

    /** @test */
    public function test_stale_snapshot_marks_both_teams_missing_for_that_snapshot(): void
    {
        // generated_at AFTER kickoff must never be usable (anti-leakage) — both
        // home and away are reported missing for that snapshot, even if both
        // teams are technically listed in it.
        $after = '2026-11-01T00:00:00Z';
        $this->writeLatentSnapshot($after, [278, 414]);
        $this->writeStructuralSnapshot('2025-01-01T00:00:00Z', [278, 414]);

        $result = (new CandidateModelService())->diagnoseLatentStructuralAvailability(278, 414, self::SAMPLE_KICKOFF);

        $this->assertTrue($result['latent_snapshot_stale']);
        $this->assertTrue($result['latent_missing_home']);
        $this->assertTrue($result['latent_missing_away']);
        $this->assertFalse($result['structural_snapshot_stale']);
        $this->assertFalse($result['structural_missing_home']);
        $this->assertFalse($result['structural_missing_away']);
    }

    /** @test */
    public function test_missing_snapshot_files_return_null(): void
    {
        // Neither writeLatentSnapshot() nor writeStructuralSnapshot() called.
        $result = (new CandidateModelService())->diagnoseLatentStructuralAvailability(278, 414, self::SAMPLE_KICKOFF);

        $this->assertNull($result);
    }
}
