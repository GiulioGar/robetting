<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\GoalMarketEvaluation;
use App\Models\GoalOddsSnapshot;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Odds\GoalEvaluationRecorder;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GOAL evaluation register: persistence, idempotence, UTC timestamps and no look-ahead (synthetic rows, no API).
 */
class GoalEvaluationRecorderTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;
    private DataSource $ds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        $country = Country::create(['name' => 'Germany', 'football_code' => 'DE']);
        $c = Competition::create(['country_id' => $country->id, 'name' => 'Bundesliga', 'slug' => 'bundesliga', 'format' => 'league', 'is_active' => true]);
        $s = Season::create(['competition_id' => $c->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);
        $h = Team::create(['name' => 'Bayern', 'type' => 'club', 'is_active' => true]);
        $a = Team::create(['name' => 'Dortmund', 'type' => 'club', 'is_active' => true]);
        $this->match = FootballMatch::create([
            'competition_id' => $c->id, 'season_id' => $s->id, 'home_team_id' => $h->id, 'away_team_id' => $a->id,
            'kickoff_at' => '2026-11-06 19:30:00', 'status' => 'scheduled',
        ]);
    }

    private function t(string $t): Carbon
    {
        return Carbon::parse($t, 'UTC');
    }

    private function prediction(Carbon $generatedAt, string $purpose = Prediction::PURPOSE_OFFICIAL, string $model = 'candidate47_structural_log'): Prediction
    {
        return Prediction::create([
            'match_id' => $this->match->id, 'model_key' => $model, 'purpose' => $purpose,
            'model_version' => '1.1.0', 'feature_set_version' => 'x', 'generated_at' => $generatedAt, 'kickoff_at' => $this->match->kickoff_at,
            'lambda_home' => 1.5, 'lambda_away' => 1.2, 'lambda3' => 0.15,
            'probability_home' => 0.4, 'probability_draw' => 0.3, 'probability_away' => 0.3, 'features_json' => [],
        ]);
    }

    private function pair(int $bm, float $over, float $under, Carbon $fetchedAt, string $obsId): void
    {
        foreach (['over' => $over, 'under' => $under] as $sel => $odd) {
            GoalOddsSnapshot::create([
                'match_id' => $this->match->id, 'data_source_id' => $this->ds->id, 'observation_id' => $obsId,
                'bookmaker_id' => $bm, 'bookmaker_name' => "BM{$bm}", 'bet_id' => 5, 'bet_name' => 'Goals Over/Under',
                'market' => 'total_goals', 'selection' => $sel, 'line' => 2.5, 'provider_label' => ucfirst($sel) . ' 2.5', 'odd' => $odd,
                'provider_updated_at' => $fetchedAt->copy()->subMinutes(20), 'fetched_at' => $fetchedAt, 'kickoff_at' => $this->match->kickoff_at,
            ]);
        }
    }

    public function test_persists_full_evaluation_with_utc_timestamps_and_references(): void
    {
        $p = $this->prediction($this->t('2026-11-05 08:00:00'));
        $this->pair(1, 1.9, 1.95, $this->t('2026-11-05 09:00:00'), 'obs-1');
        $this->pair(2, 2.0, 1.85, $this->t('2026-11-05 10:00:00'), 'obs-2');
        $now = Carbon::parse('2026-11-05 12:00:00', 'Europe/Rome');   // 11:00 UTC

        $r = GoalEvaluationRecorder::recordPending($now);

        $this->assertSame(['matches' => 1, 'created' => 2, 'duplicates' => 0], $r);   // over + under 2.5, stored whatever the EV sign
        $row = GoalMarketEvaluation::where('selection', 'over')->firstOrFail();
        $this->assertSame($p->id, (int) $row->prediction_id);
        $this->assertSame('candidate47_structural_log', $row->model_key);
        $this->assertSame('1.1.0', $row->model_version);
        $this->assertSame('total_goals', $row->market);
        $this->assertEqualsWithDelta(2.5, $row->line, 1e-12);
        $this->assertSame(2, (int) $row->bookmaker_count);
        $this->assertEqualsWithDelta(2.0, (float) $row->best_odd, 1e-9);
        $this->assertSame('BM2', $row->best_bookmaker_name);
        $this->assertSame('obs-2', $row->best_observation_id);
        $this->assertEqualsWithDelta($row->model_probability * 2.0 - 1, $row->theoretical_ev, 1e-9);
        $this->assertEqualsWithDelta($row->model_probability - $row->consensus_probability, $row->probability_edge, 1e-12);
        $this->assertCount(2, $row->bookmakers_json);
        $this->assertSame('2026-11-05 08:00:00', $row->prediction_generated_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-05 10:00:00', $row->quotes_latest_fetched_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-05 09:00:00', $row->quotes_oldest_fetched_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-05 11:00:00', $row->evaluation_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-06 19:30:00', $row->kickoff_at->format('Y-m-d H:i:s'));
    }

    public function test_is_idempotent_and_records_new_photograph_only_when_quotes_change(): void
    {
        $this->prediction($this->t('2026-11-05 08:00:00'));
        $this->pair(1, 1.9, 1.95, $this->t('2026-11-05 09:00:00'), 'obs-1');

        GoalEvaluationRecorder::recordPending($this->t('2026-11-05 10:00:00'));
        $again = GoalEvaluationRecorder::recordPending($this->t('2026-11-05 10:30:00'));   // restart, same quotes, later instant

        $this->assertSame(['matches' => 1, 'created' => 0, 'duplicates' => 2], $again);
        $this->assertSame(2, GoalMarketEvaluation::count());
        $this->assertSame('2026-11-05 10:00:00', GoalMarketEvaluation::first()->evaluation_at->format('Y-m-d H:i:s'));   // first photograph kept

        $this->pair(1, 1.8, 2.05, $this->t('2026-11-05 11:00:00'), 'obs-1b');            // genuinely new observation
        $new = GoalEvaluationRecorder::recordPending($this->t('2026-11-05 11:30:00'));

        $this->assertSame(2, $new['created']);
        $this->assertSame(4, GoalMarketEvaluation::count());
    }

    public function test_no_look_ahead_on_quotes_or_predictions(): void
    {
        $this->prediction($this->t('2026-11-05 10:30:00'));                           // generated after "now"
        $this->pair(1, 1.9, 1.95, $this->t('2026-11-05 09:00:00'), 'obs-1');
        $this->assertSame(0, GoalEvaluationRecorder::recordPending($this->t('2026-11-05 10:00:00'))['matches']);

        $this->prediction($this->t('2026-11-05 08:00:00'));
        $this->pair(1, 1.5, 2.6, $this->t('2026-11-05 10:20:00'), 'obs-future');       // fetched after "now"
        GoalEvaluationRecorder::recordPending($this->t('2026-11-05 10:00:00'));

        $over = GoalMarketEvaluation::where('selection', 'over')->firstOrFail();
        $this->assertEqualsWithDelta(1.9, (float) $over->best_odd, 1e-9);
        $this->assertSame('obs-1', $over->best_observation_id);
        $this->assertSame('2026-11-05 08:00:00', $over->prediction_generated_at->format('Y-m-d H:i:s'));
    }

    public function test_nothing_recorded_at_or_after_kickoff_or_when_not_scheduled(): void
    {
        $this->prediction($this->t('2026-11-05 08:00:00'));
        $this->pair(1, 1.9, 1.95, $this->t('2026-11-05 09:00:00'), 'obs-1');

        GoalEvaluationRecorder::recordPending($this->t('2026-11-06 19:30:00'));          // exactly at kickoff
        GoalEvaluationRecorder::recordPending($this->t('2026-11-06 19:30:01'));          // after kickoff
        $this->assertSame(0, GoalMarketEvaluation::count());

        GoalEvaluationRecorder::recordPending($this->t('2026-11-06 19:29:59'));          // last second before
        $this->assertSame(2, GoalMarketEvaluation::count());

        $this->match->update(['status' => 'finished']);
        $this->assertSame(0, GoalEvaluationRecorder::recordPending($this->t('2026-11-06 19:29:59'))['matches']);
    }

    public function test_ignores_non_official_predictions_and_other_models_and_missing_quotes(): void
    {
        $this->prediction($this->t('2026-11-05 08:00:00'), Prediction::PURPOSE_PUBLIC_LATEST);
        $this->prediction($this->t('2026-11-05 08:00:00'), Prediction::PURPOSE_OFFICIAL, 'other_model');
        $this->pair(1, 1.9, 1.95, $this->t('2026-11-05 09:00:00'), 'obs-1');
        $this->assertSame(0, GoalEvaluationRecorder::recordPending($this->t('2026-11-05 10:00:00'))['matches']);

        $this->prediction($this->t('2026-11-05 08:00:00'));
        GoalOddsSnapshot::query()->delete();                                             // no quotes: nothing invented
        $this->assertSame(0, GoalEvaluationRecorder::recordPending($this->t('2026-11-05 10:00:00'))['matches']);
        $this->assertSame(0, GoalMarketEvaluation::count());
    }

    public function test_evaluations_are_append_only(): void
    {
        $this->prediction($this->t('2026-11-05 08:00:00'));
        $this->pair(1, 1.9, 1.95, $this->t('2026-11-05 09:00:00'), 'obs-1');
        GoalEvaluationRecorder::recordPending($this->t('2026-11-05 10:00:00'));
        $row = GoalMarketEvaluation::firstOrFail();

        $this->expectException(\LogicException::class);
        $row->update(['theoretical_ev' => 9.9]);
    }
}
