<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\GoalOddsSnapshot;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Odds\GoalMarketComparator;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GoalMarketComparator::forMatch() — prediction and quote selection as of a timestamp (DB, synthetic rows).
 */
class GoalMarketComparatorForMatchTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;
    private DataSource $ds;
    private Carbon $asOf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();
        $this->asOf = Carbon::parse('2026-11-05 12:00:00', 'UTC');

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

    private function prediction(Carbon $generatedAt, string $purpose = Prediction::PURPOSE_OFFICIAL, string $model = 'candidate47_structural_log', float $lh = 1.5): Prediction
    {
        return Prediction::create([
            'match_id' => $this->match->id, 'model_key' => $model, 'purpose' => $purpose,
            'model_version' => '1.1.0', 'feature_set_version' => 'x', 'generated_at' => $generatedAt, 'kickoff_at' => $this->match->kickoff_at,
            'lambda_home' => $lh, 'lambda_away' => 1.2, 'lambda3' => 0.15,
            'probability_home' => 0.4, 'probability_draw' => 0.3, 'probability_away' => 0.3, 'features_json' => [],
        ]);
    }

    private function pair(int $bm, float $over, float $under, Carbon $fetchedAt, string $obsId, ?Carbon $kickoff = null): void
    {
        foreach (['over' => $over, 'under' => $under] as $sel => $odd) {
            GoalOddsSnapshot::create([
                'match_id' => $this->match->id, 'data_source_id' => $this->ds->id, 'observation_id' => $obsId,
                'bookmaker_id' => $bm, 'bookmaker_name' => "BM{$bm}", 'bet_id' => 5, 'bet_name' => 'Goals Over/Under',
                'market' => 'total_goals', 'selection' => $sel, 'line' => 2.5, 'provider_label' => ucfirst($sel) . ' 2.5', 'odd' => $odd,
                'provider_updated_at' => $fetchedAt->copy()->subMinutes(20), 'fetched_at' => $fetchedAt, 'kickoff_at' => $kickoff ?? $this->match->kickoff_at,
            ]);
        }
    }

    public function test_uses_latest_official_candidate47_prediction_available_at_as_of(): void
    {
        $early = $this->prediction($this->asOf->copy()->subDays(1), lh: 1.4);
        $latest = $this->prediction($this->asOf->copy()->subHours(2), lh: 1.6);
        $this->prediction($this->asOf->copy()->addHour(), lh: 3.0);                                                  // not yet generated at as-of
        $this->prediction($this->asOf->copy()->subHour(), Prediction::PURPOSE_PUBLIC_LATEST, lh: 2.5);               // wrong purpose
        $this->prediction($this->asOf->copy()->subMinutes(30), model: 'full59', lh: 2.2);                            // wrong model
        $this->pair(1, 1.9, 1.95, $this->asOf->copy()->subHour(), 'o1');

        $r = GoalMarketComparator::forMatch($this->match->id, $this->asOf);

        $this->assertSame('ok', $r['status']);
        $this->assertSame($latest->id, $r['prediction']['id']);
        $this->assertNotSame($early->id, $r['prediction']['id']);
        $this->assertEqualsWithDelta(1.6, $r['prediction']['lambda_home'], 1e-12);
        $this->assertSame(2, $r['quoted_markets']);
    }

    public function test_no_prediction_at_as_of_is_explicit(): void
    {
        $this->prediction($this->asOf->copy()->addHour());
        $this->pair(1, 1.9, 1.95, $this->asOf->copy()->subHour(), 'o1');

        $r = GoalMarketComparator::forMatch($this->match->id, $this->asOf);

        $this->assertSame('no_prediction', $r['status']);
        $this->assertSame([], $r['markets']);
    }

    public function test_quotes_after_as_of_or_not_certified_pre_match_are_excluded(): void
    {
        $this->prediction($this->asOf->copy()->subHours(2));
        $this->pair(1, 1.9, 1.95, $this->asOf->copy()->subHour(), 'ok');
        $this->pair(1, 2.5, 1.5, $this->asOf->copy()->addMinutes(10), 'future');                        // after as-of
        $this->pair(2, 2.0, 1.8, $this->asOf->copy()->subHour(), 'late-kickoff', Carbon::parse('2026-11-05 11:00:00', 'UTC')); // fetched >= its kickoff

        $r = GoalMarketComparator::forMatch($this->match->id, $this->asOf);
        $over = collect($r['markets'])->first(fn ($m) => $m['market'] === 'total_goals' && $m['line'] === 2.5 && $m['selection'] === 'over');

        $this->assertSame(1, $over['bookmaker_count']);
        $this->assertEqualsWithDelta(1.9, $over['best_odd'], 1e-12);
        $this->assertSame('BM1', $over['best_bookmakers'][0]['name']);
        $this->assertSame(60, $over['latest_age_minutes']);
    }

    public function test_prediction_without_quotes_returns_no_quotes_status_with_model_values(): void
    {
        $this->prediction($this->asOf->copy()->subHours(2));

        $r = GoalMarketComparator::forMatch($this->match->id, $this->asOf);

        $this->assertSame('no_quotes', $r['status']);
        $this->assertNotEmpty($r['markets']);
        $this->assertNotNull($r['markets'][0]['model_probability']);
        $this->assertNull($r['markets'][0]['theoretical_ev']);
    }
}
