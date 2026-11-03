<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\GoalOddsSnapshot;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\GoalMarketProbabilityCalculator;
use App\Services\Prediction\MatchPredictionService;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Step 48 — "Confronto GOAL con bookmaker" on the pre-match page: Robetting (current Candidate47 prediction, as of now)
 * vs stored GOAL quotes. Diagnostic only: no recommendation, stake or threshold.
 *
 *  A. quotes present   → table with model probability, fair odd, de-vigged consensus, best odd + bookmaker, EV, count, freshness
 *  B. no quotes        → discreet informative note, no table
 *  C. FULL59 fallback / pipeline failure / finished match → section absent
 *  D. quotes fetched after "now" and incomplete pairs are never shown
 *  E. no extra prediction call
 */
class MatchPageGoalComparisonTest extends TestCase
{
    use RefreshDatabase;

    private const V2 = [
        'lambda_home' => 1.7735, 'lambda_away' => 1.2685, 'lambda3' => 0.15,
        'probability_home' => 0.4912, 'probability_draw' => 0.2471, 'probability_away' => 0.2617,
    ];
    private const FULL59 = [
        'lambda_home' => 1.6774, 'lambda_away' => 1.3710,
        'probability_home' => 0.4483, 'probability_draw' => 0.2381, 'probability_away' => 0.3136,
    ];

    private FootballMatch $match;
    private DataSource $ds;
    private int $obs = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();
        $this->match = $this->createMatch();
    }

    private function createMatch(string $status = 'scheduled', bool $future = true): FootballMatch
    {
        $country = Country::create(['name' => 'Germany', 'code' => 'DE']);
        $c = Competition::create(['name' => 'Bundesliga', 'slug' => 'bundesliga', 'country_id' => $country->id]);
        $s = Season::create(['competition_id' => $c->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $h = Team::create(['name' => 'Bayern', 'country_id' => $country->id]);
        $a = Team::create(['name' => 'Dortmund', 'country_id' => $country->id]);

        return FootballMatch::create([
            'competition_id' => $c->id, 'season_id' => $s->id, 'home_team_id' => $h->id, 'away_team_id' => $a->id,
            'kickoff_at' => $future ? now()->addDays(3) : now()->subDays(3), 'status' => $status,
            'home_score_ft' => $status === 'finished' ? 2 : null, 'away_score_ft' => $status === 'finished' ? 0 : null,
        ]);
    }

    private function mockPipeline(?array $v2, bool $baseThrows = false): void
    {
        $pred = $this->createMock(MatchPredictionService::class);
        $m = $pred->expects($this->any())->method('predictWithDebug');
        if ($baseThrows) {
            $m->willThrowException(new RuntimeException('aggregation failed'));
        } else {
            $m->willReturn(array_merge(self::FULL59, [
                'match_id' => $this->match->id, 'model_version' => '1.0.0', 'feature_set_version' => 'core_v1', 'feature_count' => 59,
                'null_count' => 0, 'features' => array_fill_keys(array_map(fn ($i) => "core_feature_{$i}", range(1, 59)), 1.0),
                'timing_agg_ms' => 1.0, 'timing_inf_ms' => 0.1, 'timing_total_ms' => 1.1,
            ]));
        }
        $this->app->instance(MatchPredictionService::class, $pred);

        $cand = $this->createMock(CandidateModelService::class);
        $old = ['lambda_home' => 1.3, 'lambda_away' => 1.1, 'probability_home' => 0.40, 'probability_draw' => 0.30, 'probability_away' => 0.30];
        $inputs = ['structural_home' => 1.0, 'structural_away' => 1.0, 'structural_gap' => 0.0];
        $cand->expects($this->any())->method('compare')->willReturn([
            'full59' => self::FULL59, 'no_e9' => $old, 'no_e9_no_e10' => $old, 'candidate40' => $old,
            'candidate40_robust_bp' => $old + ['lambda3' => 0.15], 'candidate44_bp' => $old + ['lambda3' => 0.15],
            'candidate48_structural' => $v2, 'candidate48_structural_inputs' => $v2 ? $inputs : null,
            'candidate47_structural_log' => $v2, 'candidate47_structural_log_inputs' => $v2 ? $inputs : null,
            'no_e9_available' => true, 'no_e10_available' => true, 'candidate40_available' => true,
            'candidate40_robust_bp_available' => true, 'candidate44_bp_available' => true,
            'candidate48_structural_available' => $v2 !== null, 'candidate47_structural_log_available' => $v2 !== null,
        ]);
        $this->app->instance(CandidateModelService::class, $cand);
    }

    /** One complete observation (both selections). */
    private function quote(int $bm, string $name, string $market, ?float $line, array $odds, ?Carbon $fetchedAt = null, ?FootballMatch $match = null): void
    {
        $match ??= $this->match;
        $fetchedAt ??= now()->subHour();
        $obsId = 'obs-' . $this->obs++;
        foreach ($odds as $sel => $odd) {
            GoalOddsSnapshot::create([
                'match_id' => $match->id, 'data_source_id' => $this->ds->id, 'observation_id' => $obsId,
                'bookmaker_id' => $bm, 'bookmaker_name' => $name, 'bet_id' => 5, 'bet_name' => 'x',
                'market' => $market, 'selection' => $sel, 'line' => $line, 'provider_label' => $sel, 'odd' => $odd,
                'provider_updated_at' => $fetchedAt->copy()->subMinutes(20), 'fetched_at' => $fetchedAt, 'kickoff_at' => $match->kickoff_at,
            ]);
        }
    }

    private function section(string $html): string
    {
        $start = strpos($html, 'data-testid="goal-comparison"');
        $this->assertNotFalse($start, 'comparison section not rendered');
        $end = strpos($html, 'data-testid="market-1x2"', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    public function test_A_table_shows_model_fair_odd_consensus_best_odd_ev_count_and_freshness(): void
    {
        $this->mockPipeline(self::V2);
        $this->quote(1, 'Alpha', 'total_goals', 2.5, ['over' => 1.90, 'under' => 1.95], now()->subHours(3));
        $this->quote(2, 'Bravo', 'total_goals', 2.5, ['over' => 2.00, 'under' => 1.80], now()->subHour());
        $this->quote(1, 'Alpha', 'btts', null, ['yes' => 1.70, 'no' => 2.10], now()->subHours(2));
        $this->quote(1, 'Alpha', 'total_goals', 1.5, ['over' => 1.30, 'under' => 3.40]);
        $this->quote(1, 'Alpha', 'team_goals_home', 0.5, ['over' => 1.25, 'under' => 3.90]);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();
        $html = $this->section($response->getContent());

        $model = GoalMarketProbabilityCalculator::calculate(1.7735, 1.2685, 0.15);
        $pOver = $model['total_goals']['2.5']['over'];
        $medOver = (1.95 / 3.85 + 1.80 / 3.80) / 2;
        $medUnder = (1.90 / 3.85 + 2.00 / 3.80) / 2;
        $consensus = $medOver / ($medOver + $medUnder);
        $ev = $pOver * 2.00 - 1;

        [$summary, $all] = $this->parts($html);

        // summary: only the 4 main figures
        $row = $this->row($summary, 'total_goals', '2.5', 'over');
        $this->assertStringContainsString(number_format($pOver * 100, 1) . '%', $row);
        $this->assertStringContainsString('2.00', $row);
        $this->assertStringContainsString('Bravo', $row);                                // best odd bookmaker
        $this->assertStringContainsString(($ev >= 0 ? '+' : '−') . number_format(abs($ev) * 100, 1) . '%', $row);
        $this->assertStringNotContainsString('Consenso', $row);
        $this->assertStringNotContainsString('Quota equa', $row);

        $btts = $this->row($summary, 'btts', '', 'yes');
        $this->assertStringContainsString('Alpha', $btts);
        $this->assertStringContainsString('1.70', $btts);

        // summary shows only 2.5 / 3.5 / BTTS that are really quoted; every other line and Team Goals live in "Tutti i mercati"
        $this->assertStringNotContainsString('data-line="3.5"', $html);
        $this->assertStringNotContainsString('data-line="1.5"', $summary);
        $this->assertStringNotContainsString('Team Goals', $summary);
        $this->assertStringContainsString('<details', $html);
        $this->assertStringNotContainsString('<details open', $html);                    // collapsed by default
        $this->assertStringContainsString('data-line="1.5"', $all);
        $this->assertStringContainsString('Team Goals · Bayern', $all);
        $this->assertStringContainsString('data-line="0.5"', $all);

        // detail keeps consensus, fair odd, bookmaker count and freshness accessible
        $detail = $this->row($all, 'total_goals', '2.5', 'over');
        $this->assertStringContainsString('Consenso ' . number_format($consensus * 100, 1) . '%', $detail);
        $this->assertStringContainsString('Quota equa ' . number_format(1 / $pOver, 2), $detail);
        $this->assertStringContainsString('2 bookmaker', $detail);
        $this->assertStringContainsString('quote delle', $detail);

        // freshness: oldest-latest range, not presented as real time, not a recommendation
        $meta = substr($html, strpos($html, 'goal-comparison-meta'));
        $this->assertStringContainsString('Bookmaker per mercato', $meta);
        $this->assertStringContainsString('tra il', $meta);
        $this->assertStringContainsString('non quote in tempo reale', $meta);
        $this->assertStringContainsString('non un consiglio', $meta);
    }

    /** @return array{0:string,1:string} summary HTML, "Tutti i mercati" HTML */
    private function parts(string $sectionHtml): array
    {
        $a = strpos($sectionHtml, 'data-testid="goal-comparison-summary"');
        $b = strpos($sectionHtml, 'data-testid="goal-comparison-all"');
        $c = strpos($sectionHtml, 'data-testid="goal-comparison-meta"');
        $this->assertTrue($a !== false && $b !== false && $c !== false && $a < $b && $b < $c);

        return [substr($sectionHtml, $a, $b - $a), substr($sectionHtml, $b, $c - $b)];
    }

    private function row(string $html, string $market, string $line, string $selection): string
    {
        $needle = 'data-market="' . $market . '" data-line="' . $line . '" data-selection="' . $selection . '"';
        $start = strpos($html, $needle);
        $this->assertNotFalse($start, "row {$needle} missing");

        return substr($html, $start, strpos($html, '</tr>', $start) - $start);
    }

    public function test_B_no_quotes_shows_discreet_note_without_table(): void
    {
        $this->mockPipeline(self::V2);

        $response = $this->get(route('matches.show', $this->match));
        $response->assertOk();
        $html = $this->section($response->getContent());

        $this->assertStringContainsString('non ancora disponibili', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('EV teorico', $html);
    }

    public function test_B2_competition_outside_the_goal_odds_scope_says_quotes_are_not_collected(): void
    {
        $this->mockPipeline(self::V2);
        $this->match->competition->update(['slug' => 'coppa-italia']);

        $response = $this->get(route('matches.show', $this->match));
        $html = $this->section($response->getContent());

        $this->assertStringContainsString('non sono raccolte per questa competizione', $html);
        $this->assertStringNotContainsString('non ancora disponibili', $html);
    }

    public function test_C_section_absent_without_candidate47_prediction_or_for_finished_matches(): void
    {
        $this->quote(1, 'Alpha', 'total_goals', 2.5, ['over' => 1.90, 'under' => 1.95]);

        $this->mockPipeline(null);                                   // FULL59 fallback
        $r = $this->get(route('matches.show', $this->match));
        $r->assertOk();
        $this->assertNull($r->viewData('goalComparison'));
        $r->assertDontSee('Confronto GOAL con bookmaker');

        $this->mockPipeline(self::V2, baseThrows: true);             // pipeline failure
        $r = $this->get(route('matches.show', $this->match));
        $r->assertOk();
        $this->assertNull($r->viewData('goalComparison'));
        $r->assertDontSee('Confronto GOAL con bookmaker');
    }

    public function test_C2_finished_match_has_no_comparison(): void
    {
        $finished = $this->createMatchWithSuffix();
        $r = $this->get(route('matches.show', $finished));

        $r->assertOk();
        $this->assertNull($r->viewData('goalComparison'));
        $r->assertDontSee('Confronto GOAL con bookmaker');
    }

    private function createMatchWithSuffix(): FootballMatch
    {
        $c = Competition::first();
        $s = Season::first();
        $h = Team::first();
        $a = Team::orderByDesc('id')->first();

        return FootballMatch::create([
            'competition_id' => $c->id, 'season_id' => $s->id, 'home_team_id' => $h->id, 'away_team_id' => $a->id,
            'kickoff_at' => now()->subDays(3), 'status' => 'finished', 'home_score_ft' => 1, 'away_score_ft' => 0,
        ]);
    }

    public function test_D_quotes_after_now_and_incomplete_pairs_are_not_shown(): void
    {
        $this->mockPipeline(self::V2);
        $this->quote(1, 'Alpha', 'total_goals', 2.5, ['over' => 1.90, 'under' => 1.95], now()->subHours(2));
        $this->quote(1, 'Alpha', 'total_goals', 2.5, ['over' => 3.00, 'under' => 1.30], now()->addHour());   // after "now": never used
        $this->quote(2, 'Bravo', 'total_goals', 1.5, ['over' => 1.30]);                                       // incomplete pair

        $response = $this->get(route('matches.show', $this->match));
        $html = $this->section($response->getContent());
        [$summary, $all] = $this->parts($html);
        $row = $this->row($summary, 'total_goals', '2.5', 'over');

        $this->assertStringContainsString('1.90', $row);
        $this->assertStringNotContainsString('3.00', $row);
        $this->assertStringNotContainsString('data-line="1.5"', $html);
        $this->assertStringContainsString('1 bookmaker', $this->row($all, 'total_goals', '2.5', 'over'));
    }

    public function test_E_no_extra_prediction_call(): void
    {
        $pred = $this->createMock(MatchPredictionService::class);
        $pred->expects($this->once())->method('predictWithDebug')->willReturn(array_merge(self::FULL59, [
            'match_id' => $this->match->id, 'model_version' => '1.0.0', 'feature_set_version' => 'core_v1', 'feature_count' => 59,
            'null_count' => 0, 'features' => array_fill_keys(array_map(fn ($i) => "core_feature_{$i}", range(1, 59)), 1.0),
            'timing_agg_ms' => 1.0, 'timing_inf_ms' => 0.1, 'timing_total_ms' => 1.1,
        ]));
        $this->app->instance(MatchPredictionService::class, $pred);

        $cand = $this->createMock(CandidateModelService::class);
        $old = ['lambda_home' => 1.3, 'lambda_away' => 1.1, 'probability_home' => 0.40, 'probability_draw' => 0.30, 'probability_away' => 0.30];
        $cand->expects($this->once())->method('compare')->willReturn([
            'full59' => self::FULL59, 'no_e9' => $old, 'no_e9_no_e10' => $old, 'candidate40' => $old,
            'candidate40_robust_bp' => $old + ['lambda3' => 0.15], 'candidate44_bp' => $old + ['lambda3' => 0.15],
            'candidate48_structural' => self::V2, 'candidate48_structural_inputs' => ['structural_home' => 1.0, 'structural_away' => 1.0, 'structural_gap' => 0.0],
            'candidate47_structural_log' => self::V2, 'candidate47_structural_log_inputs' => ['structural_home' => 1.0, 'structural_away' => 1.0, 'structural_gap' => 0.0],
            'no_e9_available' => true, 'no_e10_available' => true, 'candidate40_available' => true,
            'candidate40_robust_bp_available' => true, 'candidate44_bp_available' => true,
            'candidate48_structural_available' => true, 'candidate47_structural_log_available' => true,
        ]);
        $this->app->instance(CandidateModelService::class, $cand);
        $this->quote(1, 'Alpha', 'total_goals', 2.5, ['over' => 1.90, 'under' => 1.95]);

        $this->get(route('matches.show', $this->match))->assertOk();
    }
}
