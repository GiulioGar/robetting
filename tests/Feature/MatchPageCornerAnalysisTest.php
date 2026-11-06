<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\Corner\CornerModelService;
use App\Services\Prediction\MatchPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Step 88 — "Analisi corner (sperimentale)" on the public match page.
 *
 *  A. available            → expected corners, Over/Under lines and last-10 averages rendered from the service output
 *  B. available=false      → discreet "dati insufficienti" note, no numbers
 *  C. unsupported league   → section hidden
 *  D. finished match       → section hidden and the service is never called
 *  E. real service + data  → the page shows exactly what CornerModelService computes
 */
class MatchPageCornerAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private Competition $competition;
    private Season $season;
    private Team $home;
    private Team $away;
    private Team $other;
    private DataSource $ds;

    protected function setUp(): void
    {
        parent::setUp();
        $country = Country::create(['name' => 'Italy', 'code' => 'IT']);
        $this->competition = Competition::create(['name' => 'Serie A', 'slug' => 'serie-a', 'country_id' => $country->id]);
        $this->season = Season::create(['competition_id' => $this->competition->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $this->home = Team::create(['name' => 'Napoli', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Frosinone', 'country_id' => $country->id]);
        $this->other = Team::create(['name' => 'Lecce', 'country_id' => $country->id]);
        $this->ds = DataSource::create(['name' => 'API-Football', 'slug' => 'api-football', 'source_type' => 'api']);
        config(['api-football.core_leagues' => [135 => 'serie-a']]);

        // the 1X2 pipeline is irrelevant here: make it fail so the page renders without a public prediction
        $pred = $this->createMock(MatchPredictionService::class);
        $pred->method('predictWithDebug')->willThrowException(new RuntimeException('not needed'));
        $this->app->instance(MatchPredictionService::class, $pred);
    }

    private function match(string $status = 'scheduled', bool $future = true): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->competition->id, 'season_id' => $this->season->id, 'home_team_id' => $this->home->id,
            'away_team_id' => $this->away->id, 'kickoff_at' => $future ? now()->addDays(3) : now()->subDays(3), 'status' => $status,
            'home_score_ft' => $status === 'finished' ? 2 : null, 'away_score_ft' => $status === 'finished' ? 0 : null,
        ]);
    }

    private function fakeService(array $result, int $calls): void
    {
        $mock = $this->createMock(CornerModelService::class);
        $mock->expects($this->exactly($calls))->method('predictForMatch')->willReturn($result);
        $this->app->instance(CornerModelService::class, $mock);
    }

    private function availableResult(): array
    {
        return [
            'available' => true, 'model_key' => 'corner_nb_copula', 'model_version' => '1.0.0',
            'features' => ['h_for10' => 5.6, 'h_ag10' => 4.1, 'h_n10' => 9, 'a_for10' => 3.9, 'a_ag10' => null, 'a_n10' => 0],
            'mu_home' => 5.43, 'mu_away' => 4.12, 'expected_total' => 9.55,
            'over' => ['8.5' => 0.612, '9.5' => 0.481, '10.5' => 0.352], 'under' => ['8.5' => 0.388, '9.5' => 0.519, '10.5' => 0.648],
        ];
    }

    public function test_A_available_section_is_rendered_from_the_service_output(): void
    {
        $match = $this->match();
        $this->fakeService($this->availableResult(), 1);

        $response = $this->get(route('matches.show', $match));
        $response->assertOk();
        $this->assertEquals($this->availableResult(), $response->viewData('cornerAnalysis'));

        $html = $response->getContent();
        $block = substr($html, strpos($html, 'data-testid="corner-analysis"'));
        $block = substr($block, 0, strpos($block, 'Quote bookmaker'));
        $this->assertStringContainsString('Sperimentale', $block);
        $this->assertStringContainsString('data-corner="home">5,4<', $block);
        $this->assertStringContainsString('data-corner="away">4,1<', $block);
        $this->assertStringContainsString('data-corner="total">9,6<', $block);       // 9.55 -> one decimal
        foreach (['8.5' => ['61.2%', '38.8%'], '9.5' => ['48.1%', '51.9%'], '10.5' => ['35.2%', '64.8%']] as $line => [$over, $under]) {
            $this->assertMatchesRegularExpression('/data-line="' . preg_quote($line, '/') . '">.*?' . preg_quote($over, '/') . '.*?' . preg_quote($under, '/') . '/s', $block);
        }
        $this->assertStringContainsString('Medie storiche corner', $block);
        $this->assertStringContainsString('n.d.', $block);                           // away "concessi" missing -> shown as n.d., never 0
        $this->assertStringNotContainsStringIgnoringCase('scommess', $block);        // no betting advice wording
        $this->assertStringNotContainsStringIgnoringCase('valore atteso', $block);
    }

    public function test_B_unavailable_shows_a_discreet_message_and_no_numbers(): void
    {
        $match = $this->match();
        $this->fakeService(['available' => false, 'reason' => 'insufficient_corner_history'], 1);

        $response = $this->get(route('matches.show', $match));
        $response->assertOk();
        $response->assertSee('data-testid="corner-unavailable"', false);
        $response->assertSee('Dati insufficienti per l\'analisi dei corner', false);
        $response->assertDontSee('data-testid="corner-over-under"', false);
    }

    public function test_C_unsupported_competition_hides_the_section(): void
    {
        $match = $this->match();
        $this->fakeService(['available' => false, 'reason' => 'competition_not_supported'], 1);

        $response = $this->get(route('matches.show', $match));
        $response->assertOk();
        $this->assertNull($response->viewData('cornerAnalysis'));
        $response->assertDontSee('data-testid="corner-analysis"', false);
    }

    public function test_D_finished_match_hides_the_section_and_never_calls_the_service(): void
    {
        $match = $this->match('finished', false);
        $this->fakeService($this->availableResult(), 0);

        $response = $this->get(route('matches.show', $match));
        $response->assertOk();
        $this->assertNull($response->viewData('cornerAnalysis'));
        $response->assertDontSee('data-testid="corner-analysis"', false);
    }

    public function test_E_real_service_output_is_what_the_page_shows(): void
    {
        foreach ([[$this->home, $this->other, 6, 3], [$this->other, $this->home, 4, 7], [$this->away, $this->other, 5, 4], [$this->other, $this->away, 3, 6]] as $i => [$h, $a, $hc, $ac]) {
            $m = FootballMatch::create(['competition_id' => $this->competition->id, 'season_id' => $this->season->id, 'home_team_id' => $h->id,
                'away_team_id' => $a->id, 'kickoff_at' => now()->subDays(20 - $i), 'status' => 'finished', 'home_score_ft' => 1, 'away_score_ft' => 0]);
            MatchStatistic::create(['match_id' => $m->id, 'data_source_id' => $this->ds->id, 'home_corners' => $hc, 'away_corners' => $ac]);
        }
        $match = $this->match();

        $response = $this->get(route('matches.show', $match));
        $response->assertOk();
        $shown = $response->viewData('cornerAnalysis');
        $expected = (new CornerModelService())->predictForMatch($match->fresh('competition'));

        $this->assertTrue($shown['available']);
        $this->assertEqualsWithDelta($expected['mu_home'], $shown['mu_home'], 1e-12);
        $this->assertEqualsWithDelta($expected['over']['9.5'], $shown['over']['9.5'], 1e-12);
        $html = $response->getContent();
        $this->assertStringContainsString('data-corner="total">' . number_format($expected['expected_total'], 1, ',', '') . '<', $html);
        $this->assertStringContainsString('data-line="9.5"', $html);
    }
}
