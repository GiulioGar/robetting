<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\MatchPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-match page: size and scope of the samples behind the statistics are explicit (match 12031 audit),
 * and a fallback 1X2 estimate is flagged. No statistic or probability is changed.
 */
class MatchPageSampleTransparencyTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;
    private Competition $c;
    private Season $s;
    private Team $home;
    private Team $away;
    private Team $other;

    protected function setUp(): void
    {
        parent::setUp();
        $country = Country::create(['name' => 'Europe', 'code' => 'EU']);
        $this->c = Competition::create(['name' => 'UEFA Europa League', 'slug' => 'uefa-europa-league', 'country_id' => $country->id]);
        $this->s = Season::create(['competition_id' => $this->c->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $this->home = Team::create(['name' => 'AC Milan', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Ferencvarosi TC', 'country_id' => $country->id]);
        $this->other = Team::create(['name' => 'Other FC', 'country_id' => $country->id]);

        $this->played($this->home, $this->other, '2026-10-22 19:00:00', 'League Stage - 3');
        $this->played($this->other, $this->away, '2026-07-09 18:00:00', '1st Qualifying Round');
        $this->played($this->away, $this->other, '2026-07-16 18:00:00', '1st Qualifying Round');
        $this->played($this->away, $this->other, '2026-10-15 19:00:00', 'League Stage - 2');

        $this->match = FootballMatch::create([
            'competition_id' => $this->c->id, 'season_id' => $this->s->id, 'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
            'kickoff_at' => '2026-11-05 17:45:00', 'status' => 'scheduled', 'round' => 'League Stage - 4',
        ]);
    }

    private function played(Team $h, Team $a, string $at, string $round): void
    {
        FootballMatch::create([
            'competition_id' => $this->c->id, 'season_id' => $this->s->id, 'home_team_id' => $h->id, 'away_team_id' => $a->id,
            'kickoff_at' => $at, 'status' => 'finished', 'round' => $round, 'home_score_ft' => 1, 'away_score_ft' => 0,
        ]);
    }

    private function mockPrediction(bool $candidate): void
    {
        $full59 = ['lambda_home' => 1.69, 'lambda_away' => 1.44, 'probability_home' => 0.437, 'probability_draw' => 0.235, 'probability_away' => 0.328];
        $pred = $this->createMock(MatchPredictionService::class);
        $pred->method('predictWithDebug')->willReturn($full59 + [
            'match_id' => $this->match->id, 'model_version' => '1.0.0', 'feature_set_version' => 'core_v1', 'feature_count' => 59,
            'null_count' => 0, 'features' => array_fill_keys(array_map(fn ($i) => "core_feature_{$i}", range(1, 59)), 1.0),
            'timing_agg_ms' => 1.0, 'timing_inf_ms' => 0.1, 'timing_total_ms' => 1.1,
        ]);
        $this->app->instance(MatchPredictionService::class, $pred);

        $v2 = $candidate ? ['lambda_home' => 1.77, 'lambda_away' => 1.27, 'lambda3' => 0.15, 'probability_home' => 0.49, 'probability_draw' => 0.25, 'probability_away' => 0.26] : null;
        $old = ['lambda_home' => 1.3, 'lambda_away' => 1.1, 'probability_home' => 0.40, 'probability_draw' => 0.30, 'probability_away' => 0.30];
        $inputs = ['structural_home' => 1.0, 'structural_away' => 1.0, 'structural_gap' => 0.0];
        $cand = $this->createMock(CandidateModelService::class);
        $cand->method('compare')->willReturn([
            'full59' => $full59, 'no_e9' => $old, 'no_e9_no_e10' => $old, 'candidate40' => $old,
            'candidate40_robust_bp' => $old + ['lambda3' => 0.15], 'candidate44_bp' => $old + ['lambda3' => 0.15],
            'candidate48_structural' => $v2, 'candidate48_structural_inputs' => $v2 ? $inputs : null,
            'candidate47_structural_log' => $v2, 'candidate47_structural_log_inputs' => $v2 ? $inputs : null,
            'no_e9_available' => true, 'no_e10_available' => true, 'candidate40_available' => true,
            'candidate40_robust_bp_available' => true, 'candidate44_bp_available' => true,
            'candidate48_structural_available' => $v2 !== null, 'candidate47_structural_log_available' => $v2 !== null,
        ]);
        $this->app->instance(CandidateModelService::class, $cand);
    }

    public function test_season_sample_note_shows_scope_size_and_date_range_per_team(): void
    {
        $this->mockPrediction(true);

        $r = $this->get(route('matches.show', $this->match));

        $r->assertOk();
        $r->assertSee('data-testid="season-sample-note"', false);
        $r->assertSee('tutte le fasi disputate (comprese eventuali qualificazioni)');
        $r->assertSee('AC Milan: 1 partita, dal 22/10/2026 al 22/10/2026', false);
        $r->assertSee('Ferencvarosi TC: 3 partite, dal 09/07/2026 al 15/10/2026', false);
    }

    public function test_form_block_flags_reduced_sample_with_actual_size(): void
    {
        $this->mockPrediction(true);

        $r = $this->get(route('matches.show', $this->match));

        // competition is not in the configured Team History scope in this fixture -> 0 eligible matches, flagged as reduced
        $r->assertSee('data-testid="form-sample-note-home"', false);
        $r->assertSee('data-testid="form-sample-note-away"', false);
        $r->assertSee('campione ridotto');
        $r->assertSee('Campione: 0 partite');
    }

    public function test_fallback_estimate_is_flagged(): void
    {
        $this->mockPrediction(false);

        $this->get(route('matches.show', $this->match))
            ->assertSee('data-testid="public-prediction-fallback-note"', false)
            ->assertSee('modello di riserva FULL59')
            ->assertDontSee('Affidabilità', false);
    }

    public function test_candidate_estimate_is_not_flagged(): void
    {
        $this->c->update(['slug' => 'bundesliga']);   // UEFA club competitions use the artifact-based profile; domestic uses the mocked Candidate47
        $this->mockPrediction(true);

        $this->get(route('matches.show', $this->match))
            ->assertDontSee('public-prediction-fallback-note', false);
    }
}
