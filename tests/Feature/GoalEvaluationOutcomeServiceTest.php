<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\GoalMarketEvaluation;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Odds\GoalEvaluationOutcomeService as S;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Outcome verification of the GOAL evaluation register (read-only service, synthetic rows).
 */
class GoalEvaluationOutcomeServiceTest extends TestCase
{
    use RefreshDatabase;

    private Competition $c;
    private Season $s;
    private Team $h;
    private Team $a;

    protected function setUp(): void
    {
        parent::setUp();
        $country = Country::create(['name' => 'Germany', 'football_code' => 'DE']);
        $this->c = Competition::create(['country_id' => $country->id, 'name' => 'Bundesliga', 'slug' => 'bundesliga', 'format' => 'league', 'is_active' => true]);
        $this->s = Season::create(['competition_id' => $this->c->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);
        $this->h = Team::create(['name' => 'Bayern', 'type' => 'club', 'is_active' => true]);
        $this->a = Team::create(['name' => 'Dortmund', 'type' => 'club', 'is_active' => true]);
    }

    private function match(array $attrs = []): FootballMatch
    {
        return FootballMatch::create($attrs + [
            'competition_id' => $this->c->id, 'season_id' => $this->s->id, 'home_team_id' => $this->h->id, 'away_team_id' => $this->a->id,
            'kickoff_at' => '2026-11-01 19:30:00', 'status' => 'finished', 'definitive_at' => '2026-11-01 21:30:00',
            'home_score_ft' => 2, 'away_score_ft' => 1,
        ]);
    }

    private function eval(FootballMatch $m, string $market, ?float $line, string $sel, float $odd, float $pm, float $pc, string $at = '2026-10-31 10:00:00'): GoalMarketEvaluation
    {
        $p = Prediction::where('match_id', $m->id)->first() ?? Prediction::create([
            'match_id' => $m->id, 'model_key' => 'candidate47_structural_log', 'purpose' => Prediction::PURPOSE_OFFICIAL,
            'model_version' => '1.1.0', 'feature_set_version' => 'x', 'generated_at' => '2026-10-31 08:00:00', 'kickoff_at' => $m->kickoff_at,
            'lambda_home' => 1.5, 'lambda_away' => 1.2, 'lambda3' => 0.15,
            'probability_home' => 0.4, 'probability_draw' => 0.3, 'probability_away' => 0.3, 'features_json' => [],
        ]);
        static $n = 0;
        $n++;

        return GoalMarketEvaluation::create([
            'prediction_id' => $p->id, 'match_id' => $m->id, 'model_key' => 'candidate47_structural_log', 'model_version' => '1.1.0',
            'kickoff_at' => $m->kickoff_at, 'market' => $market, 'line' => $line, 'line_key' => $line === null ? '-' : number_format($line, 1, '.', ''),
            'selection' => $sel, 'model_probability' => $pm, 'model_fair_odd' => 1 / $pm, 'consensus_probability' => $pc,
            'probability_edge' => $pm - $pc, 'theoretical_ev' => $pm * $odd - 1, 'best_odd' => $odd, 'best_bookmaker_id' => 1,
            'best_bookmaker_name' => 'BM1', 'best_observation_id' => "obs-$n", 'bookmaker_count' => 1, 'bookmakers_json' => [],
            'quote_state_hash' => sha1("h$n"), 'prediction_generated_at' => '2026-10-31 08:00:00',
            'quotes_latest_fetched_at' => '2026-10-31 09:00:00', 'quotes_oldest_fetched_at' => '2026-10-31 09:00:00', 'evaluation_at' => $at,
        ]);
    }

    public function test_market_rules_on_regulation_score(): void
    {
        // 2-1: total 3
        $this->assertTrue(S::selectionWins('total_goals', 2.5, 'over', 2, 1));
        $this->assertFalse(S::selectionWins('total_goals', 3.5, 'over', 2, 1));
        $this->assertTrue(S::selectionWins('total_goals', 3.5, 'under', 2, 1));
        $this->assertFalse(S::selectionWins('total_goals', 0.5, 'under', 0, 1));
        $this->assertTrue(S::selectionWins('total_goals', 0.5, 'under', 0, 0));
        $this->assertTrue(S::selectionWins('btts', null, 'yes', 2, 1));
        $this->assertTrue(S::selectionWins('btts', null, 'no', 2, 0));
        $this->assertFalse(S::selectionWins('btts', null, 'yes', 0, 0));
        $this->assertTrue(S::selectionWins('team_goals_home', 1.5, 'over', 2, 1));
        $this->assertFalse(S::selectionWins('team_goals_away', 1.5, 'over', 2, 1));
        $this->assertTrue(S::selectionWins('team_goals_away', 1.5, 'under', 2, 1));
        $this->assertTrue(S::selectionWins('team_goals_home', 2.5, 'under', 2, 5));
        // unsupported: never guessed
        $this->assertNull(S::selectionWins('total_goals', 2.0, 'over', 2, 1));       // integer line (push)
        $this->assertNull(S::selectionWins('total_goals', null, 'over', 2, 1));
        $this->assertNull(S::selectionWins('corners', 9.5, 'over', 2, 1));
        $this->assertNull(S::selectionWins('btts', null, 'over', 2, 1));
    }

    public function test_uses_regulation_score_not_extra_time_or_penalties(): void
    {
        $m = $this->match(['home_score_ft' => 1, 'away_score_ft' => 1, 'home_score_et' => 1, 'away_score_et' => 0, 'home_score_penalties' => 4, 'away_score_penalties' => 3]);
        $this->eval($m, 'total_goals', 2.5, 'over', 2.0, 0.5, 0.5);    // 1-1 after 90': 2 goals -> Under 2.5 would win; Over loses
        $this->eval($m, 'total_goals', 1.5, 'over', 2.0, 0.5, 0.5);    // wins at 90' (2 goals)

        $rows = S::settle()->keyBy('line_key');

        $this->assertFalse($rows['2.5']['won']);
        $this->assertTrue($rows['1.5']['won']);
        $this->assertEqualsWithDelta(-1.0, $rows['2.5']['return'], 1e-12);
        $this->assertEqualsWithDelta(1.0, $rows['1.5']['return'], 1e-12);
    }

    public function test_non_final_or_non_regulation_matches_are_not_settleable(): void
    {
        $cases = [
            $this->match(['status' => 'scheduled', 'home_score_ft' => null, 'away_score_ft' => null, 'definitive_at' => null]),
            $this->match(['status' => 'abandoned']),
            $this->match(['status' => 'awarded']),
            $this->match(['definitive_at' => null]),                                                 // not certified definitive
            $this->match(['home_score_ft' => null, 'away_score_ft' => null]),                        // finished but no FT score
            $this->match(['status' => 'postponed', 'home_score_ft' => null, 'away_score_ft' => null, 'definitive_at' => null]),
        ];
        foreach ($cases as $m) {
            $this->eval($m, 'btts', null, 'yes', 2.0, 0.5, 0.5);
        }

        $rep = S::report();

        $this->assertSame(6, $rep['registered_evaluations']);
        $this->assertSame(6, $rep['not_settleable']);
        $this->assertSame(0, $rep['overall']['selections']);
        $this->assertNull($rep['overall']['roi']);
        $this->assertNull($rep['overall']['brier_model']);
    }

    public function test_unsupported_market_is_reported_not_settled(): void
    {
        $m = $this->match();
        $this->eval($m, 'total_goals', 2.0, 'over', 2.0, 0.5, 0.5);   // integer line

        $rep = S::report();

        $this->assertSame(1, $rep['unsupported']);
        $this->assertSame(0, $rep['overall']['selections']);
    }

    public function test_first_evaluation_per_selection_ignores_later_quote_moves(): void
    {
        $m = $this->match();                                                              // 2-1
        $this->eval($m, 'total_goals', 2.5, 'over', 1.80, 0.60, 0.55, '2026-10-31 10:00:00');
        $this->eval($m, 'total_goals', 2.5, 'over', 2.40, 0.60, 0.42, '2026-10-31 12:00:00');   // later photograph, better odd
        $this->eval($m, 'total_goals', 2.5, 'over', 2.10, 0.60, 0.50, '2026-10-31 10:00:00');   // same instant, higher id -> not first

        $all = S::settle();
        $first = S::firstPerSelection($all);

        $this->assertCount(3, $all);
        $this->assertCount(1, $first);
        $this->assertEqualsWithDelta(1.80, $first[0]['best_odd'], 1e-12);
        $this->assertEqualsWithDelta(0.80, $first[0]['return'], 1e-12);
        $this->assertSame(1, S::report()['overall']['selections']);
    }

    public function test_summary_profit_roi_brier_and_splits(): void
    {
        $m1 = $this->match();                                                              // 2-1 : total 3, btts yes, home 2, away 1
        $m2 = $this->match(['home_score_ft' => 0, 'away_score_ft' => 0]);                  // 0-0

        $this->eval($m1, 'total_goals', 2.5, 'over', 2.00, 0.60, 0.50);    // win  +1.0   EV +0.20
        $this->eval($m1, 'btts', null, 'no', 3.00, 0.30, 0.40);            // lose -1.0   EV -0.10
        $this->eval($m2, 'total_goals', 2.5, 'over', 1.50, 0.50, 0.60);    // lose -1.0   EV -0.25
        $this->eval($m2, 'team_goals_home', 0.5, 'under', 2.50, 0.40, 0.40);// win  +1.5   EV 0.00

        $rep = S::report();
        $o = $rep['overall'];

        $this->assertSame(2, $o['matches']);
        $this->assertSame(4, $o['selections']);
        $this->assertSame(2, $o['won']);
        $this->assertSame(2, $o['lost']);
        $this->assertEqualsWithDelta(0.5, $o['profit'], 1e-12);
        $this->assertEqualsWithDelta(0.125, $o['roi'], 1e-12);
        // model: (0.4^2 + 0.3^2 + 0.5^2 + 0.6^2)/4 ; outcomes 1,0,0,1
        $this->assertEqualsWithDelta((0.16 + 0.09 + 0.25 + 0.36) / 4, $o['brier_model'], 1e-12);
        // consensus: (0.5^2 + 0.4^2 + 0.6^2 + 0.6^2)/4
        $this->assertEqualsWithDelta((0.25 + 0.16 + 0.36 + 0.36) / 4, $o['brier_consensus'], 1e-12);

        $this->assertSame(2, $rep['by_market']['total_goals']['selections']);
        $this->assertEqualsWithDelta(0.0, $rep['by_market']['total_goals']['profit'], 1e-12);
        $this->assertSame(1, $rep['by_market']['btts']['selections']);
        $this->assertSame(1, $rep['by_ev_sign']['ev_positive']['selections']);
        $this->assertSame(3, $rep['by_ev_sign']['ev_non_positive']['selections']);   // EV == 0 counted as non-positive
        $this->assertSame(2, $rep['caveats']['independent_units_are_matches']);
    }

    public function test_register_is_not_modified(): void
    {
        $m = $this->match();
        $this->eval($m, 'btts', null, 'yes', 2.0, 0.5, 0.5);
        $before = GoalMarketEvaluation::count();

        S::report();

        $this->assertSame($before, GoalMarketEvaluation::count());
    }
}
