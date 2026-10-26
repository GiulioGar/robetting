<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Odds\MarketConsensusService;
use App\Services\Odds\ModelMarketDiagnostics;
use App\Services\Prediction\PublicMatchPredictionService;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Value Engine V1 (diagnostic only): as-of market consensus, best odds,
 * Robetting-vs-market edge and theoretical EV. No thresholds, no stake.
 */
class ValueEngineDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $competition;
    private Season $season;
    private Team $home;
    private Team $away;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->competition = Competition::create(['country_id' => $country->id, 'name' => 'Serie A', 'slug' => 'serie-a', 'format' => 'league', 'is_active' => true]);
        $this->season = Season::create(['competition_id' => $this->competition->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);
        $this->home = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->away = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
    }

    private function match(string $status = 'scheduled', ?Carbon $kickoff = null): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->competition->id, 'season_id' => $this->season->id,
            'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
            'kickoff_at' => $kickoff ?? now()->addDays(3), 'status' => $status,
            'home_score_ft' => $status === 'finished' ? 1 : null, 'away_score_ft' => $status === 'finished' ? 0 : null,
        ]);
    }

    private function snap(FootballMatch $m, int $bmId, string $name, float $h, float $d, float $a, Carbon $fetchedAt): OddsSnapshot
    {
        return OddsSnapshot::create([
            'match_id' => $m->id, 'data_source_id' => $this->ds->id,
            'bookmaker_id' => $bmId, 'bookmaker_name' => $name, 'bet_id' => 1, 'bet_name' => 'Match Winner',
            'home_odd' => $h, 'draw_odd' => $d, 'away_odd' => $a,
            'provider_updated_at' => $fetchedAt->copy()->subHour(), 'fetched_at' => $fetchedAt, 'kickoff_at' => $m->kickoff_at,
        ]);
    }

    private function fair(float $h, float $d, float $a): array
    {
        $s = 1 / $h + 1 / $d + 1 / $a;

        return ['home' => 1 / $h / $s, 'draw' => 1 / $d / $s, 'away' => 1 / $a / $s];
    }

    public function test_devig_is_proportional_per_bookmaker(): void
    {
        $m = $this->match();
        $this->snap($m, 8, 'Bet365', 1.90, 3.40, 4.20, now()->subHours(2));

        $market = MarketConsensusService::asOf($m->id, now());
        $b = $market['bookmakers'][0];
        $exp = $this->fair(1.90, 3.40, 4.20);

        foreach (['home', 'draw', 'away'] as $o) {
            $this->assertEqualsWithDelta($exp[$o], $b['fair'][$o], 1e-12);
        }
        $this->assertEqualsWithDelta(1.0, array_sum($b['fair']), 1e-12);
        $this->assertEqualsWithDelta(1 / 1.90 + 1 / 3.40 + 1 / 4.20 - 1, $b['overround'], 1e-12);
        // a single bookmaker's consensus is its own fair probabilities
        foreach (['home', 'draw', 'away'] as $o) {
            $this->assertEqualsWithDelta($exp[$o], $market['consensus'][$o], 1e-12);
        }
    }

    public function test_consensus_is_median_of_fair_probabilities_renormalised(): void
    {
        $m = $this->match();
        $odds = [[8, 'A', 2.00, 3.50, 3.80], [4, 'B', 2.20, 3.30, 3.40], [11, 'C', 1.85, 3.70, 4.60]];
        foreach ($odds as [$id, $name, $h, $d, $a]) {
            $this->snap($m, $id, $name, $h, $d, $a, now()->subHours(2));
        }

        $market = MarketConsensusService::asOf($m->id, now());

        $fairs = array_map(fn ($o) => $this->fair($o[2], $o[3], $o[4]), $odds);
        $med = [];
        foreach (['home', 'draw', 'away'] as $k) {
            $v = array_column($fairs, $k);
            sort($v);
            $med[$k] = $v[1];
        }
        $sum = array_sum($med);

        $this->assertSame(3, $market['bookmaker_count']);
        foreach (['home', 'draw', 'away'] as $k) {
            $this->assertEqualsWithDelta($med[$k] / $sum, $market['consensus'][$k], 1e-12);
        }
        $this->assertEqualsWithDelta(1.0, array_sum($market['consensus']), 1e-12);

        // Even count: mean of the two middle values.
        $m2 = $this->match();
        $this->snap($m2, 8, 'A', 2.00, 3.50, 3.80, now()->subHours(2));
        $this->snap($m2, 4, 'B', 2.20, 3.30, 3.40, now()->subHours(2));
        $two = MarketConsensusService::asOf($m2->id, now());
        $fa = $this->fair(2.00, 3.50, 3.80);
        $fb = $this->fair(2.20, 3.30, 3.40);
        $meds = ['home' => ($fa['home'] + $fb['home']) / 2, 'draw' => ($fa['draw'] + $fb['draw']) / 2, 'away' => ($fa['away'] + $fb['away']) / 2];
        $this->assertEqualsWithDelta($meds['home'] / array_sum($meds), $two['consensus']['home'], 1e-12);
    }

    public function test_as_of_excludes_later_snapshots_and_picks_latest_not_after_as_of_per_bookmaker(): void
    {
        $m = $this->match();
        $asOf = now()->subHours(3);
        $this->snap($m, 8, 'Bet365', 2.00, 3.50, 3.80, now()->subHours(10));   // older
        $this->snap($m, 8, 'Bet365', 2.10, 3.40, 3.70, now()->subHours(4));    // latest <= asOf
        $this->snap($m, 8, 'Bet365', 9.00, 9.00, 9.00, now()->subHours(1));    // after asOf: excluded
        $this->snap($m, 4, 'Pinnacle', 8.00, 8.00, 8.00, now()->subHours(2));  // only after asOf: bookmaker absent

        $market = MarketConsensusService::asOf($m->id, $asOf);

        $this->assertSame(1, $market['bookmaker_count']);
        $this->assertSame('Bet365', $market['bookmakers'][0]['name']);
        $this->assertEquals([2.10, 3.40, 3.70], [$market['bookmakers'][0]['home'], $market['bookmakers'][0]['draw'], $market['bookmakers'][0]['away']]);
        $this->assertTrue($market['latest_fetched_at']->lte($asOf));
        // Before the first snapshot: no market at all.
        $this->assertNull(MarketConsensusService::asOf($m->id, now()->subHours(11)));
    }

    public function test_best_odds_use_the_same_as_of_set(): void
    {
        $m = $this->match();
        $asOf = now()->subHours(3);
        $this->snap($m, 8, 'Bet365', 2.10, 3.40, 3.60, now()->subHours(5));
        $this->snap($m, 4, 'Pinnacle', 2.25, 3.30, 3.60, now()->subHours(5));
        $this->snap($m, 11, '1xBet', 5.00, 6.00, 7.00, now()->subHours(1));   // better odds, but after asOf

        $best = MarketConsensusService::asOf($m->id, $asOf)['best'];

        $this->assertSame(2.25, $best['home']['odd']);
        $this->assertSame(['Pinnacle'], $best['home']['bookmakers']);
        $this->assertSame(3.40, $best['draw']['odd']);
        $this->assertSame(['Bet365'], $best['draw']['bookmakers']);
        $this->assertEqualsCanonicalizing(['Bet365', 'Pinnacle'], $best['away']['bookmakers']);
        $this->assertSame(3.60, $best['away']['odd']);
    }

    public function test_edge_and_theoretical_ev_formulas(): void
    {
        $m = $this->match();
        $this->snap($m, 8, 'Bet365', 2.00, 4.00, 4.00, now()->subHours(2)); // fair 0.50 / 0.25 / 0.25
        $market = MarketConsensusService::asOf($m->id, now());

        $rows = ModelMarketDiagnostics::compare(['home' => 0.56, 'draw' => 0.22, 'away' => 0.22], $market);

        $this->assertEqualsWithDelta(0.56 - 0.50, $rows['home']['probability_edge'], 1e-12);
        $this->assertEqualsWithDelta(0.22 - 0.25, $rows['draw']['probability_edge'], 1e-12);
        $this->assertEqualsWithDelta(0.56 * 2.00 - 1, $rows['home']['theoretical_ev'], 1e-12);
        $this->assertEqualsWithDelta(0.22 * 4.00 - 1, $rows['draw']['theoretical_ev'], 1e-12);
        $this->assertSame(['Bet365'], $rows['away']['best_bookmakers']);
        // Diagnostic only: no classification / threshold / stake keys.
        $this->assertSame(['model_probability', 'market_fair_probability', 'probability_edge', 'best_odd', 'best_bookmakers', 'theoretical_ev'], array_keys($rows['home']));
    }

    public function test_finished_match_uses_prediction_generated_at_and_not_later_quotes(): void
    {
        $kickoff = now()->subDay();
        $m = $this->match('finished', $kickoff);
        $generatedAt = $kickoff->copy()->subHours(6);
        $this->snap($m, 8, 'Bet365', 2.00, 4.00, 4.00, $kickoff->copy()->subHours(10));  // known at generation
        $this->snap($m, 8, 'Bet365', 1.40, 5.50, 9.00, $kickoff->copy()->subHours(2));   // later (closing-like): must NOT be used
        Prediction::create([
            'match_id' => $m->id, 'model_key' => 'candidate47_structural_log', 'purpose' => Prediction::PURPOSE_OFFICIAL,
            'model_version' => '1.1.0', 'feature_set_version' => 'x', 'generated_at' => $generatedAt, 'kickoff_at' => $kickoff,
            'lambda_home' => 1.5, 'lambda_away' => 1.1, 'probability_home' => 0.56, 'probability_draw' => 0.22, 'probability_away' => 0.22,
            'features_json' => ['a' => 1.0],
        ]);

        $mc = $this->get(route('matches.show', $m, absolute: false))->assertOk()->viewData('marketComparison');

        $this->assertSame('official', $mc['source']);
        $this->assertEquals($generatedAt->toIso8601String(), $mc['as_of']->toIso8601String());
        $this->assertEqualsWithDelta(0.50, $mc['rows']['home']['market_fair_probability'], 1e-9);   // from the 2.00/4.00/4.00 snapshot
        $this->assertEqualsWithDelta(2.00, $mc['rows']['home']['best_odd'], 1e-9);
        $this->assertEqualsWithDelta(0.56 * 2.00 - 1, $mc['rows']['home']['theoretical_ev'], 1e-9);
    }

    public function test_finished_match_without_official_prediction_shows_no_historical_comparison(): void
    {
        $kickoff = now()->subDay();
        $m = $this->match('finished', $kickoff);
        $this->snap($m, 8, 'Bet365', 2.00, 4.00, 4.00, $kickoff->copy()->subHours(5));

        $response = $this->get(route('matches.show', $m, absolute: false))->assertOk();

        $this->assertNull($response->viewData('marketComparison'));
        $response->assertDontSee('Confronto Robetting vs mercato');
        $response->assertSee('dato storico'); // the raw historical market table is still there
    }

    public function test_future_match_shows_diagnostic_block_and_does_not_change_robetting_probabilities(): void
    {
        $fixed = [
            'model_used' => PublicMatchPredictionService::MODEL_CANDIDATE_V2, 'fallback_used' => false, 'fallback_reason' => null, 'experimental_profile' => null,
            'lambda_home' => 1.5, 'lambda_away' => 1.2, 'lambda3' => 0.15,
            'probability_home' => 0.50, 'probability_draw' => 0.25, 'probability_away' => 0.25,
            'fair_odds_home' => 2.0, 'fair_odds_draw' => 4.0, 'fair_odds_away' => 4.0,
        ];
        $mock = $this->createMock(PublicMatchPredictionService::class);
        $mock->method('predict')->willReturn($fixed);
        $this->app->instance(PublicMatchPredictionService::class, $mock);

        $m = $this->match();
        $without = $this->get(route('matches.show', $m, absolute: false))->assertOk();
        $this->assertNull($without->viewData('marketComparison')); // no quotes yet

        $this->snap($m, 8, 'Bet365', 3.10, 3.40, 2.60, now()->subHours(1));
        $with = $this->get(route('matches.show', $m, absolute: false))->assertOk();

        $this->assertSame($fixed, $without->viewData('publicPrediction'));
        $this->assertSame($fixed, $with->viewData('publicPrediction'));
        $mc = $with->viewData('marketComparison');
        $this->assertSame('live', $mc['source']);
        $this->assertEquals([0.50, 0.25, 0.25], [$mc['rows']['home']['model_probability'], $mc['rows']['draw']['model_probability'], $mc['rows']['away']['model_probability']]);
        $with->assertSee('Confronto Robetting vs mercato');
        $with->assertSee('Dati diagnostici, non raccomandazione di scommessa.');
    }
}
