<?php

namespace Tests\Feature\Prediction;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P27C — OfficialPredictionResultCaptureService + robetting:capture-official-results.
 *
 * No Candidate V2 LOG artifact/snapshot is needed here: this service only
 * reads football_matches + predictions, so test fixtures insert Prediction
 * rows directly (bypassing OfficialPredictionRecorder::record()'s own
 * validation, which is already covered by OfficialPredictionRecorderTest).
 */
class OfficialPredictionResultCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    private Team $home;
    private Team $away;
    private Competition $competition;
    private Season $season;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::create(['name' => 'Italy', 'code' => 'IT']);
        $this->home = Team::create(['name' => 'Inter', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Udinese', 'country_id' => $country->id]);
        $this->competition = Competition::create(['name' => 'Serie A', 'slug' => 'serie-a-test', 'country_id' => $country->id]);
        $this->season = Season::create([
            'competition_id' => $this->competition->id, 'name' => '2025/26', 'year_start' => 2025, 'year_end' => 2026,
        ]);
    }

    private function createMatch(string $status, ?int $hg = null, ?int $ag = null, string $kickoffAt = '2026-01-01 18:00:00'): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->competition->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->home->id,
            'away_team_id'   => $this->away->id,
            'kickoff_at'     => $kickoffAt,
            'status'         => $status,
            'home_score_ft'  => $hg,
            'away_score_ft'  => $ag,
        ]);
    }

    private function createPrediction(FootballMatch $match, string $modelKey = 'candidate47_structural_log', array $overrides = []): Prediction
    {
        return Prediction::create(array_merge([
            'match_id'             => $match->id,
            'model_key'            => $modelKey,
            'model_version'        => '1.0.0',
            'feature_set_version'  => 'core_v1_candidate47_structural_log',
            'artifact_sha256'      => str_repeat('a', 64),
            'generated_at'         => now()->subDays(2),
            'kickoff_at'           => $match->kickoff_at,
            'lambda_home'          => 1.5,
            'lambda_away'          => 1.1,
            'lambda3'              => 0.15,
            'probability_home'     => 0.5,
            'probability_draw'     => 0.3,
            'probability_away'     => 0.2,
            'features_json'        => ['a' => 1.0],
        ], $overrides));
    }

    private function runCommand(bool $dryRun = false): \Illuminate\Testing\PendingCommand
    {
        $params = $dryRun ? ['--dry-run' => true] : [];
        return $this->artisan('robetting:capture-official-results', $params);
    }

    // 1-4. finished + FT valido -> risultato registrato, outcome corretto
    public function test_finished_with_valid_ft_records_outcome_home_win(): void
    {
        $match = $this->createMatch('finished', 2, 1);
        $pred = $this->createPrediction($match);

        $this->runCommand()->assertExitCode(0);

        $fresh = Prediction::find($pred->id);
        $this->assertSame(2, $fresh->home_goals);
        $this->assertSame(1, $fresh->away_goals);
        $this->assertSame('1', $fresh->outcome);
        $this->assertSame('finished', $fresh->match_status_at_result);
        $this->assertNotNull($fresh->result_recorded_at);
    }

    public function test_outcome_draw_is_x(): void
    {
        $match = $this->createMatch('finished', 1, 1);
        $pred = $this->createPrediction($match);

        $this->runCommand();

        $this->assertSame('X', Prediction::find($pred->id)->outcome);
    }

    public function test_outcome_away_win_is_2(): void
    {
        $match = $this->createMatch('finished', 0, 2);
        $pred = $this->createPrediction($match);

        $this->runCommand();

        $this->assertSame('2', Prediction::find($pred->id)->outcome);
    }

    // 5-7. match non definitivo -> nessuna scrittura
    public function test_scheduled_match_is_not_written(): void
    {
        $match = $this->createMatch('scheduled');
        $pred = $this->createPrediction($match);

        $this->runCommand()
            ->expectsOutputToContain('WAITING_RESULT');

        $this->assertNull(Prediction::find($pred->id)->home_goals);
    }

    public function test_live_match_is_not_written(): void
    {
        $match = $this->createMatch('live');
        $pred = $this->createPrediction($match);

        $this->runCommand()->expectsOutputToContain('WAITING_RESULT');

        $this->assertNull(Prediction::find($pred->id)->home_goals);
    }

    public function test_postponed_match_is_not_written(): void
    {
        $match = $this->createMatch('postponed');
        $pred = $this->createPrediction($match);

        $this->runCommand()->expectsOutputToContain('WAITING_RESULT');

        $this->assertNull(Prediction::find($pred->id)->home_goals);
    }

    // 8. definitivo senza FT -> nessuna scrittura
    public function test_definitive_without_ft_score_is_not_written(): void
    {
        $match = $this->createMatch('finished', null, null);
        $pred = $this->createPrediction($match);

        $this->runCommand()->expectsOutputToContain('INVALID_RESULT');

        $this->assertNull(Prediction::find($pred->id)->home_goals);
    }

    // 9. prediction già risolta -> immutabile
    public function test_already_resolved_prediction_is_immutable(): void
    {
        $match = $this->createMatch('finished', 2, 1);
        $pred = $this->createPrediction($match, overrides: [
            'home_goals' => 2, 'away_goals' => 1, 'outcome' => '1',
            'match_status_at_result' => 'finished', 'result_recorded_at' => now()->subDay(),
        ]);
        $originalRecordedAt = $pred->result_recorded_at->toIso8601String();

        $this->runCommand()->expectsOutputToContain('ALREADY_RECORDED');

        $fresh = Prediction::find($pred->id);
        $this->assertSame($originalRecordedAt, $fresh->result_recorded_at->toIso8601String());
    }

    // 10. due prediction stesso match -> entrambe aggiornate
    public function test_two_predictions_same_match_both_updated(): void
    {
        $match = $this->createMatch('finished', 2, 1);
        $p1 = $this->createPrediction($match, 'candidate47_structural_log');
        $p2 = $this->createPrediction($match, 'full59');

        $this->runCommand()->expectsOutputToContain('predictions=2');

        $this->assertSame(2, Prediction::find($p1->id)->home_goals);
        $this->assertSame(2, Prediction::find($p2->id)->home_goals);
    }

    // 11. una già risolta + una irrisolta -> solo quella irrisolta aggiornata
    public function test_one_resolved_one_unresolved_only_unresolved_updated(): void
    {
        $match = $this->createMatch('finished', 2, 1);
        $resolved = $this->createPrediction($match, 'full59', [
            'home_goals' => 9, 'away_goals' => 9, 'outcome' => 'X',
            'match_status_at_result' => 'finished', 'result_recorded_at' => now()->subDay(),
        ]);
        $unresolved = $this->createPrediction($match, 'candidate47_structural_log');

        $this->runCommand();

        // The already-resolved row must remain exactly as it was (sentinel 9-9/X).
        $freshResolved = Prediction::find($resolved->id);
        $this->assertSame(9, $freshResolved->home_goals);
        $this->assertSame(9, $freshResolved->away_goals);
        $this->assertSame('X', $freshResolved->outcome);

        $freshUnresolved = Prediction::find($unresolved->id);
        $this->assertSame(2, $freshUnresolved->home_goals);
        $this->assertSame(1, $freshUnresolved->away_goals);
    }

    // 12. awarded/walkover -> risultato registrato con status distinguibile
    public function test_awarded_match_records_result_with_distinguishable_status(): void
    {
        $match = $this->createMatch('awarded', 3, 0);
        $pred = $this->createPrediction($match);

        $this->runCommand();

        $fresh = Prediction::find($pred->id);
        $this->assertSame(3, $fresh->home_goals);
        $this->assertSame('awarded', $fresh->match_status_at_result);
    }

    public function test_walkover_match_records_result_with_distinguishable_status(): void
    {
        $match = $this->createMatch('walkover', 0, 3);
        $pred = $this->createPrediction($match);

        $this->runCommand();

        $this->assertSame('walkover', Prediction::find($pred->id)->match_status_at_result);
    }

    // 13. dry-run -> zero scritture
    public function test_dry_run_never_writes(): void
    {
        $match = $this->createMatch('finished', 2, 1);
        $pred = $this->createPrediction($match);

        $this->runCommand(dryRun: true)
            ->expectsOutputToContain('WOULD_RECORD')
            ->assertExitCode(0);

        $this->assertNull(Prediction::find($pred->id)->home_goals);
    }

    // 14. errore su un match -> gli altri continuano
    public function test_error_on_one_match_does_not_block_others(): void
    {
        $okMatch = $this->createMatch('finished', 2, 1);
        $okPred = $this->createPrediction($okMatch);

        // Prediction pointing to a non-existent match_id -> ERROR for that row.
        // FK checks disabled only around this single insert, purely to
        // simulate the defensive "match not found" branch in isolation.
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $orphan = Prediction::create([
            'match_id' => 999999, 'model_key' => 'candidate47_structural_log',
            'model_version' => '1.0.0', 'feature_set_version' => 'core_v1',
            'generated_at' => now()->subDays(2), 'kickoff_at' => now()->subDay(),
            'lambda_home' => 1.0, 'lambda_away' => 1.0,
            'probability_home' => 0.5, 'probability_draw' => 0.3, 'probability_away' => 0.2,
            'features_json' => ['a' => 1.0],
        ]);
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->runCommand()
            ->expectsOutputToContain('ERROR')
            ->assertExitCode(0);

        $this->assertSame(2, Prediction::find($okPred->id)->home_goals);
        $this->assertNull(Prediction::find($orphan->id)->home_goals);
    }

    // 15. nessun campo della prediction originale cambia
    public function test_original_prediction_fields_never_change(): void
    {
        $match = $this->createMatch('finished', 2, 1);
        $pred = $this->createPrediction($match);
        $originalGeneratedAt = $pred->generated_at->toIso8601String();
        $originalLambdaHome = $pred->lambda_home;
        $originalFeatures = $pred->features_json;
        $originalModelKey = $pred->model_key;

        $this->runCommand();

        $fresh = Prediction::find($pred->id);
        $this->assertSame($originalGeneratedAt, $fresh->generated_at->toIso8601String());
        $this->assertSame($originalLambdaHome, $fresh->lambda_home);
        $this->assertSame($originalFeatures, $fresh->features_json);
        $this->assertSame($originalModelKey, $fresh->model_key);
    }
}
