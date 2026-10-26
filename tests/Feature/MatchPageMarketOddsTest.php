<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\OddsSnapshot;
use App\Models\Season;
use App\Models\Team;
use App\Services\Matches\MatchMarketOddsBuilder;
use App\Services\Prediction\PublicMatchPredictionService;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Match Page "Mercato 1X2": raw market information from certified pre-match
 * Odds V1 snapshots. No value logic, no change to Robetting probabilities.
 */
class MatchPageMarketOddsTest extends TestCase
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

    private function snap(FootballMatch $m, int $bmId, string $bmName, float $h, float $d, float $a, ?Carbon $fetchedAt = null, ?Carbon $providerAt = null, ?Carbon $kickoff = null): OddsSnapshot
    {
        return OddsSnapshot::create([
            'match_id' => $m->id, 'data_source_id' => $this->ds->id,
            'bookmaker_id' => $bmId, 'bookmaker_name' => $bmName, 'bet_id' => 1, 'bet_name' => 'Match Winner',
            'home_odd' => $h, 'draw_odd' => $d, 'away_odd' => $a,
            'provider_updated_at' => $providerAt ?? ($fetchedAt ?? now()->subHours(2))->copy()->subHour(),
            'fetched_at' => $fetchedAt ?? now()->subHours(2),
            'kickoff_at' => $kickoff ?? $m->kickoff_at,
        ]);
    }

    private function page(FootballMatch $m)
    {
        return $this->get(route('matches.show', $m, absolute: false));
    }

    public function test_only_latest_snapshot_per_bookmaker_is_shown(): void
    {
        $m = $this->match();
        $this->snap($m, 8, 'Bet365', 2.11, 3.31, 3.91, now()->subHours(9));   // old
        $this->snap($m, 8, 'Bet365', 2.37, 3.47, 3.73, now()->subHours(1));   // latest
        $this->snap($m, 4, 'Pinnacle', 2.05, 3.55, 3.85, now()->subHours(2));

        $response = $this->page($m)->assertOk();
        $html = $response->getContent();

        $response->assertSee('Bet365');
        $response->assertSee('2.37');
        $response->assertDontSee('2.11');
        $response->assertDontSee('3.31');
        $this->assertSame(2, substr_count($html, 'data-bookmaker='));
        // Stable alphabetical order, no preferred bookmaker.
        $this->assertLessThan(strpos($html, 'Pinnacle'), strpos($html, 'Bet365'));
        // History untouched.
        $this->assertSame(3, OddsSnapshot::count());
    }

    public function test_only_certified_pre_match_snapshots_are_used(): void
    {
        $m = $this->match();
        // fetched AFTER the kickoff known on the row: not certified.
        $this->snap($m, 11, '1xBet', 9.99, 9.98, 9.97, $m->kickoff_at->copy()->addHour(), null, $m->kickoff_at);
        $this->snap($m, 8, 'Bet365', 2.10, 3.40, 3.60);
        // not yet received (future fetched_at): ignored as well.
        $this->snap($m, 4, 'Pinnacle', 8.88, 8.87, 8.86, now()->addHour(), now()->addMinutes(30));

        $r = MatchMarketOddsBuilder::forMatch($m->id);

        $this->assertSame(['Bet365'], array_column($r['bookmakers'], 'name'));
        $this->page($m)->assertDontSee('9.99')->assertDontSee('1xBet')->assertDontSee('8.88');
    }

    public function test_best_odds_per_outcome_with_bookmakers_and_ties(): void
    {
        $m = $this->match();
        $this->snap($m, 8, 'Bet365', 2.10, 3.40, 3.60);
        $this->snap($m, 4, 'Pinnacle', 2.25, 3.30, 3.60);
        $this->snap($m, 11, '1xBet', 2.20, 3.55, 3.50);

        $best = MatchMarketOddsBuilder::forMatch($m->id)['best'];

        $this->assertSame(2.25, $best['home']['odd']);
        $this->assertSame(['Pinnacle'], $best['home']['bookmakers']);
        $this->assertSame(3.55, $best['draw']['odd']);
        $this->assertSame(['1xBet'], $best['draw']['bookmakers']);
        $this->assertSame(3.60, $best['away']['odd']);
        $this->assertEqualsCanonicalizing(['Bet365', 'Pinnacle'], $best['away']['bookmakers']); // tie

        $html = $this->page($m)->assertOk()->getContent();
        $this->assertStringContainsString('(informazione di mercato, non un segnale value)', $html);
        $this->assertMatchesRegularExpression('/data-best="home".*?2\.25.*?Pinnacle/s', $html);
    }

    public function test_overround_is_computed_per_bookmaker(): void
    {
        $m = $this->match();
        $this->snap($m, 8, 'Bet365', 2.00, 4.00, 4.00);   // 0.5 + 0.25 + 0.25 - 1 = 0
        $this->snap($m, 4, 'Pinnacle', 1.90, 3.40, 4.20);  // 5.85%

        $rows = collect(MatchMarketOddsBuilder::forMatch($m->id)['bookmakers'])->keyBy('name');

        $this->assertEqualsWithDelta(0.0, $rows['Bet365']['overround'], 1e-9);
        $this->assertEqualsWithDelta(1 / 1.90 + 1 / 3.40 + 1 / 4.20 - 1, $rows['Pinnacle']['overround'], 1e-9);
        $html = $this->page($m)->getContent();
        $this->assertStringContainsString('overround 0.0%', $html);
        $this->assertStringContainsString('overround 5.9%', $html);
    }

    public function test_incomplete_snapshot_bookmaker_is_not_shown_and_empty_state_text(): void
    {
        $m = $this->match();
        $this->snap($m, 8, 'Bet365', 2.10, 1.00, 3.60); // draw <= 1: incomplete/invalid

        $response = $this->page($m)->assertOk();

        $this->assertSame([], MatchMarketOddsBuilder::forMatch($m->id)['bookmakers']);
        $response->assertSee('Quote di mercato non ancora disponibili');
        $response->assertDontSee('Bet365');

        $other = $this->match();
        $this->page($other)->assertSee('Quote di mercato non ancora disponibili');
    }

    public function test_finished_match_shows_last_pre_match_snapshot_as_historical(): void
    {
        $kickoff = now()->subDay();
        $m = $this->match('finished', $kickoff);
        $this->snap($m, 8, 'Bet365', 2.10, 3.40, 3.60, $kickoff->copy()->subHours(5), null, $kickoff);
        $this->snap($m, 8, 'Bet365', 2.25, 3.45, 3.30, $kickoff->copy()->subHours(1), null, $kickoff);
        // Post-kickoff acquisition: never shown.
        $this->snap($m, 8, 'Bet365', 1.11, 7.77, 15.0, $kickoff->copy()->addHour(), null, $kickoff);

        $response = $this->page($m)->assertOk();

        $response->assertSee('dato storico');
        $response->assertSee('2.25');
        $response->assertDontSee('1.11');
        $response->assertDontSee('2.10');
    }

    public function test_robetting_probabilities_are_untouched_by_market_data(): void
    {
        $fixed = [
            'model_used' => 'candidate47_structural_log', 'fallback_used' => false, 'fallback_reason' => null, 'experimental_profile' => null,
            'lambda_home' => 1.5, 'lambda_away' => 1.2, 'lambda3' => 0.15,
            'probability_home' => 0.50, 'probability_draw' => 0.25, 'probability_away' => 0.25,
            'fair_odds_home' => 2.0, 'fair_odds_draw' => 4.0, 'fair_odds_away' => 4.0,
        ];
        $mock = $this->createMock(PublicMatchPredictionService::class);
        $mock->method('predict')->willReturn($fixed);
        $this->app->instance(PublicMatchPredictionService::class, $mock);

        $m = $this->match();
        $before = $this->page($m)->assertOk()->viewData('publicPrediction');

        $this->snap($m, 8, 'Bet365', 3.10, 3.40, 2.60); // market strongly disagrees with the model
        $response = $this->page($m)->assertOk();

        $this->assertSame($fixed, $before);
        $this->assertSame($fixed, $response->viewData('publicPrediction'));
        $html = $response->getContent();
        $this->assertStringContainsString('data-testid="market-robetting"', $html);
        $this->assertStringContainsString('50.0%', $html);
        $this->assertStringContainsString('quota equa 2.00', $html);
        // No value logic anywhere in the block.
        foreach (['edge', 'ROI', 'stake', 'value bet', 'consiglio'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, substr($html, (int) strpos($html, 'data-testid="market-1x2"'), 6000));
        }
    }
}
