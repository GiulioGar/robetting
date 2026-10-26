<?php

namespace Tests\Feature\Prediction;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\RecentXgFeatureExporter;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Offline xG experiment export: orientation, Team History window, 120h
 * cutoff, null handling, no leakage, target preservation.
 */
class RecentXgFeatureExporterTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $serieA;
    private Competition $champions;
    private Season $serieA2025;
    private Season $serieA2026;
    private Season $cl2026;
    private Team $t;      // team under test (core)
    private Team $opp;    // core opponent

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        config(['api-football.core_leagues'            => [135 => 'serie-a']]);
        config(['api-football.additional_competitions' => [2 => 'uefa-champions-league']]);

        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->serieA    = Competition::create(['country_id' => $country->id, 'name' => 'Serie A', 'slug' => 'serie-a', 'format' => 'league', 'is_active' => true]);
        $this->champions = Competition::create(['country_id' => $country->id, 'name' => 'UEFA Champions League', 'slug' => 'uefa-champions-league', 'format' => 'cup', 'is_active' => true]);

        $this->serieA2025 = Season::create(['competition_id' => $this->serieA->id, 'name' => '2025/26', 'year_start' => 2025, 'year_end' => 2026]);
        $this->serieA2026 = Season::create(['competition_id' => $this->serieA->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);
        $this->cl2026     = Season::create(['competition_id' => $this->champions->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);

        $this->t   = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->opp = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        foreach ([$this->serieA2025, $this->serieA2026] as $s) {
            $s->teams()->attach([$this->t->id, $this->opp->id]);
        }
    }

    private function played(Competition $c, Season $s, Team $home, Team $away, string $kickoff, ?float $hxg, ?float $axg): FootballMatch
    {
        $m = FootballMatch::create([
            'competition_id' => $c->id, 'season_id' => $s->id,
            'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'kickoff_at' => Carbon::parse($kickoff), 'status' => 'finished',
            'home_score_ft' => 1, 'away_score_ft' => 0,
        ]);
        if ($hxg !== null || $axg !== null) {
            MatchStatistic::create([
                'match_id' => $m->id, 'data_source_id' => $this->ds->id, 'fetched_at' => now(),
                'home_expected_goals' => $hxg, 'away_expected_goals' => $axg,
            ]);
        }

        return $m;
    }

    private function target(string $kickoff = '2026-10-20 18:00:00', ?Team $home = null, ?Team $away = null): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->serieA->id, 'season_id' => $this->serieA2026->id,
            'home_team_id' => ($home ?? $this->t)->id, 'away_team_id' => ($away ?? $this->opp)->id,
            'kickoff_at' => Carbon::parse($kickoff), 'status' => 'scheduled',
        ]);
    }

    private function exportOne(FootballMatch $target): array
    {
        return (new RecentXgFeatureExporter())->export([$target->id])[0];
    }

    public function test_orientation_home_and_away(): void
    {
        $this->played($this->serieA, $this->serieA2026, $this->t, $this->opp, '2026-09-01 18:00:00', 2.0, 0.5);  // t home
        $this->played($this->serieA, $this->serieA2026, $this->opp, $this->t, '2026-09-08 18:00:00', 1.0, 3.0);  // t away
        $target = $this->target();

        $row = $this->exportOne($target);

        // t as home side of the target: for = (2.0 home + 3.0 away)/2, against = (0.5 + 1.0)/2
        $this->assertEqualsWithDelta(2.5, $row['home_recent_avg_xg_for'], 1e-9);
        $this->assertEqualsWithDelta(0.75, $row['home_recent_avg_xg_against'], 1e-9);
        // opp as away side: mirror image
        $this->assertEqualsWithDelta(0.75, $row['away_recent_avg_xg_for'], 1e-9);
        $this->assertEqualsWithDelta(2.5, $row['away_recent_avg_xg_against'], 1e-9);
    }

    public function test_window_is_cross_competition_and_cross_season(): void
    {
        $this->played($this->serieA, $this->serieA2025, $this->t, $this->opp, '2026-05-01 18:00:00', 1.0, 0.0);   // previous season
        $this->played($this->champions, $this->cl2026, $this->t, $this->opp, '2026-09-17 18:00:00', 3.0, 0.0);    // Champions (TEAM_HISTORY)

        $row = $this->exportOne($this->target());

        $this->assertSame(2, $row['home_recent_window_matches']);
        $this->assertSame(2, $row['home_recent_xg_for_obs']);
        $this->assertEqualsWithDelta(2.0, $row['home_recent_avg_xg_for'], 1e-9);
    }

    public function test_cutoff_120h_is_inclusive_and_later_matches_do_not_contribute(): void
    {
        $target = $this->target('2026-10-20 18:00:00');
        $this->played($this->serieA, $this->serieA2026, $this->t, $this->opp, '2026-10-15 18:00:00', 4.0, 0.0);   // exactly -120h: usable
        $this->played($this->serieA, $this->serieA2026, $this->t, $this->opp, '2026-10-15 18:01:00', 9.0, 0.0);   // -119h59m: in window, NOT usable

        $row = $this->exportOne($target);

        $this->assertSame(2, $row['home_recent_window_matches']);
        $this->assertSame(1, $row['home_recent_xg_for_obs']);
        $this->assertEqualsWithDelta(4.0, $row['home_recent_avg_xg_for'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $row['home_recent_xg_coverage'], 1e-9);
    }

    public function test_null_xg_excluded_and_zero_observations_is_null(): void
    {
        $this->played($this->serieA, $this->serieA2026, $this->t, $this->opp, '2026-09-01 18:00:00', 2.0, 1.0);
        $this->played($this->serieA, $this->serieA2026, $this->t, $this->opp, '2026-09-08 18:00:00', null, null);  // no stats row at all
        $this->played($this->serieA, $this->serieA2026, $this->t, $this->opp, '2026-09-15 18:00:00', 4.0, null);   // partial: for only

        $row = $this->exportOne($this->target());

        $this->assertSame(3, $row['home_recent_window_matches']);
        $this->assertSame(2, $row['home_recent_xg_for_obs']);
        $this->assertSame(1, $row['home_recent_xg_against_obs']);
        $this->assertEqualsWithDelta(3.0, $row['home_recent_avg_xg_for'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $row['home_recent_avg_xg_against'], 1e-9);

        // Opponent side: its own matches are the same three, mirrored.
        // A team with no history at all => nulls, not zeros.
        $stranger = Team::create(['name' => 'Verona', 'type' => 'club', 'is_active' => true]);
        $this->serieA2026->teams()->attach($stranger->id);
        $row2 = $this->exportOne($this->target('2026-10-21 18:00:00', $stranger, $this->opp));
        $this->assertNull($row2['home_recent_avg_xg_for']);
        $this->assertNull($row2['home_recent_avg_xg_against']);
        $this->assertSame(0, $row2['home_recent_window_matches']);
        $this->assertNull($row2['home_recent_xg_coverage']);
    }

    public function test_no_backfill_beyond_last_10_matches(): void
    {
        // 2 old matches WITH xG, then the 10 most recent WITHOUT xG: the old ones are outside the window.
        $this->played($this->serieA, $this->serieA2025, $this->t, $this->opp, '2026-03-01 18:00:00', 5.0, 5.0);
        $this->played($this->serieA, $this->serieA2025, $this->t, $this->opp, '2026-03-08 18:00:00', 5.0, 5.0);
        for ($i = 0; $i < 10; $i++) {
            $this->played($this->serieA, $this->serieA2026, $this->t, $this->opp, Carbon::parse('2026-09-01 18:00:00')->addDays($i * 3)->toDateTimeString(), null, null);
        }

        $row = $this->exportOne($this->target());

        $this->assertSame(10, $row['home_recent_window_matches']);
        $this->assertSame(0, $row['home_recent_xg_for_obs']);
        $this->assertNull($row['home_recent_avg_xg_for']);
    }

    public function test_no_future_leakage(): void
    {
        $target = $this->target('2026-10-20 18:00:00');
        $this->played($this->serieA, $this->serieA2026, $this->t, $this->opp, '2026-10-20 18:00:00', 7.0, 7.0);   // same kickoff
        $this->played($this->serieA, $this->serieA2026, $this->t, $this->opp, '2026-10-25 18:00:00', 8.0, 8.0);   // after target

        $row = $this->exportOne($target);

        $this->assertSame(0, $row['home_recent_window_matches']);
        $this->assertNull($row['home_recent_avg_xg_for']);
    }

    public function test_export_preserves_target_count_and_order_and_rejects_unknown_or_duplicate_ids(): void
    {
        $a = $this->target('2026-10-20 18:00:00');
        $b = $this->target('2026-10-21 18:00:00');
        $c = $this->target('2026-10-19 18:00:00');

        $rows = (new RecentXgFeatureExporter())->export([$b->id, $c->id, $a->id]);

        $this->assertSame([$b->id, $c->id, $a->id], array_column($rows, 'match_id'));
        $this->assertSame(RecentXgFeatureExporter::COLUMNS, array_keys($rows[0]));

        $this->expectException(\InvalidArgumentException::class);
        (new RecentXgFeatureExporter())->export([$a->id, 999999]);
    }

    public function test_command_writes_csv_keyed_by_target_dataset_in_order(): void
    {
        $a = $this->target('2026-10-20 18:00:00');
        $b = $this->target('2026-10-21 18:00:00');
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xg_export_' . uniqid();
        mkdir($dir);
        $in  = $dir . DIRECTORY_SEPARATOR . 'targets.csv';
        $out = $dir . DIRECTORY_SEPARATOR . 'xg.csv';
        file_put_contents($in, "match_id,kickoff_at\n{$b->id},x\n{$a->id},y\n");

        $this->artisan('robetting:export-recent-xg-features', ['--dataset' => $in, '--output' => $out])->assertSuccessful();

        $lines = array_map('str_getcsv', file($out, FILE_IGNORE_NEW_LINES));
        $this->assertSame(RecentXgFeatureExporter::COLUMNS, $lines[0]);
        $this->assertSame([(string) $b->id, (string) $a->id], [$lines[1][0], $lines[2][0]]);
        $this->assertCount(3, $lines);
        $this->assertSame('', $lines[1][1]); // null xG exported as empty, not imputed

        @unlink($in);
        @unlink($out);
        @rmdir($dir);
    }
}
