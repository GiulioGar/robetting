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
 * Temporal boundaries of prediction/quote selection: kickoff, as-of, and as-of expressed in a non-UTC timezone
 * (persisted values are UTC; comparisons must not depend on the timezone of the Carbon instance passed in).
 */
class GoalMarketComparatorTemporalBoundaryTest extends TestCase
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

    private function utc(string $t): Carbon
    {
        return Carbon::parse($t, 'UTC');
    }

    private function prediction(Carbon $generatedAt): void
    {
        Prediction::create([
            'match_id' => $this->match->id, 'model_key' => 'candidate47_structural_log', 'purpose' => Prediction::PURPOSE_OFFICIAL,
            'model_version' => '1.1.0', 'feature_set_version' => 'x', 'generated_at' => $generatedAt, 'kickoff_at' => $this->match->kickoff_at,
            'lambda_home' => 1.5, 'lambda_away' => 1.2, 'lambda3' => 0.15,
            'probability_home' => 0.4, 'probability_draw' => 0.3, 'probability_away' => 0.3, 'features_json' => [],
        ]);
    }

    private function pair(Carbon $fetchedAt, string $obsId): void
    {
        foreach (['over' => 1.9, 'under' => 1.95] as $sel => $odd) {
            GoalOddsSnapshot::create([
                'match_id' => $this->match->id, 'data_source_id' => $this->ds->id, 'observation_id' => $obsId,
                'bookmaker_id' => 1, 'bookmaker_name' => 'BM1', 'bet_id' => 5, 'bet_name' => 'Goals Over/Under',
                'market' => 'total_goals', 'selection' => $sel, 'line' => 2.5, 'provider_label' => ucfirst($sel) . ' 2.5', 'odd' => $odd,
                'provider_updated_at' => $fetchedAt->copy()->subMinutes(20), 'fetched_at' => $fetchedAt, 'kickoff_at' => $this->match->kickoff_at,
            ]);
        }
    }

    public function test_prediction_generated_exactly_at_as_of_is_included_and_one_second_later_excluded(): void
    {
        $asOf = $this->utc('2026-11-05 12:00:00');
        $this->prediction($asOf->copy());
        $this->assertNotSame('no_prediction', GoalMarketComparator::forMatch($this->match->id, $asOf)['status']);
        $this->assertSame('no_prediction', GoalMarketComparator::forMatch($this->match->id, $asOf->copy()->subSecond())['status']);
    }

    public function test_quote_boundaries_around_as_of_and_kickoff(): void
    {
        $this->prediction($this->utc('2026-11-05 08:00:00'));
        $this->pair($this->utc('2026-11-06 19:29:59'), 'before-ko');
        $this->pair($this->utc('2026-11-06 19:30:00'), 'at-ko');
        $this->pair($this->utc('2026-11-06 19:30:01'), 'after-ko');

        // as-of after kickoff: only the quote strictly before kickoff is usable
        $r = GoalMarketComparator::forMatch($this->match->id, $this->utc('2026-11-06 21:00:00'));
        $this->assertCount(2, GoalMarketComparator::quoteRows($this->match->id, $this->utc('2026-11-06 21:00:00')));
        $over = collect($r['markets'])->first(fn ($m) => $m['market'] === 'total_goals' && $m['line'] === 2.5 && $m['selection'] === 'over');
        $this->assertSame('2026-11-06 19:29:59', $over['latest_fetched_at']->format('Y-m-d H:i:s'));

        // as-of before any quote: no quotes; quote later than the prediction is invisible earlier
        $this->assertSame('no_quotes', GoalMarketComparator::forMatch($this->match->id, $this->utc('2026-11-06 19:29:58'))['status']);
        $this->assertSame('ok', GoalMarketComparator::forMatch($this->match->id, $this->utc('2026-11-06 19:29:59'))['status']);
    }

    public function test_as_of_in_non_utc_timezone_selects_same_rows_as_utc(): void
    {
        $this->prediction($this->utc('2026-11-05 10:00:00'));
        $this->pair($this->utc('2026-11-05 11:30:00'), 'q1');

        // 12:00 Europe/Rome == 11:00 UTC  -> prediction ok, quote (11:30 UTC) not yet available
        $rome = Carbon::parse('2026-11-05 12:00:00', 'Europe/Rome');
        $this->assertSame('no_quotes', GoalMarketComparator::forMatch($this->match->id, $rome)['status']);
        $this->assertSame('no_quotes', GoalMarketComparator::forMatch($this->match->id, $rome->copy()->utc())['status']);
        $this->assertSame([], GoalMarketComparator::quoteRows($this->match->id, $rome));

        // 12:30 Rome == 11:30 UTC -> quote becomes available
        $romeLater = Carbon::parse('2026-11-05 12:30:00', 'Europe/Rome');
        $this->assertSame('ok', GoalMarketComparator::forMatch($this->match->id, $romeLater)['status']);
        $this->assertNotEmpty(GoalMarketComparator::quoteRows($this->match->id, $romeLater));
    }
}
