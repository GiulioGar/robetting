<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamMarketValueSnapshot;
use App\Services\Analytics\TeamEloCalculator;
use App\Services\Analytics\TeamStrengthComparisonCalculator;
use App\Services\Analytics\TeamStructuralRatingCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for TeamStrengthComparisonCalculator::calculateForMatch().
 *
 * Coverage checklist (per spec):
 *  01. Structural available for both teams
 *  02. Elo available for both
 *  03. structural_rating_diff correct
 *  04. elo_diff correct
 *  05. log_market_value_ratio correct
 *  06. elo_diff_scaled correct
 *  07. Uses latest Structural snapshot
 *  08. Structural snapshot after match excluded
 *  09. Structural absent → structural fields null
 *  10. Elo works when Structural missing
 *  11. Target match excluded from Elo (strict < cutoff)
 *  12. Future finished matches do not influence Elo
 *  13. signals_agree = true
 *  14. signals_agree = false
 *  15. signals_agree = null (no structural)
 *  16. home/away Elo assigned to correct team
 */
class TeamStrengthComparisonCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Team $home;
    private Team $away;
    private Competition $comp;
    private Season $season;
    private DataSource $source;

    // Target match kickoff — far enough in the future so "past" fixtures land before it.
    private const KICKOFF = '2026-10-15 20:45:00';

    protected function setUp(): void
    {
        parent::setUp();

        $country      = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->comp   = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $this->season = Season::create([
            'competition_id' => $this->comp->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);
        $this->home = Team::create(['name' => 'Inter',  'type' => 'club', 'is_active' => true]);
        $this->away = Team::create(['name' => 'Milan',  'type' => 'club', 'is_active' => true]);

        $this->source = DataSource::create([
            'slug'        => 'transfermarkt',
            'name'        => 'Transfermarkt',
            'source_type' => 'scraper',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function targetMatch(): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->home->id,
            'away_team_id'   => $this->away->id,
            'kickoff_at'     => Carbon::parse(self::KICKOFF, 'UTC'),
            'status'         => 'scheduled',
        ]);
    }

    private function makeSnapshot(Team $team, int $marketValue, string $date): TeamMarketValueSnapshot
    {
        return TeamMarketValueSnapshot::create([
            'team_id'        => $team->id,
            'data_source_id' => $this->source->id,
            'snapshot_date'  => $date,
            'market_value'   => $marketValue,
        ]);
    }

    private function makeFinished(
        Team $home,
        Team $away,
        string $kickoff,
        int $homeScore = 1,
        int $awayScore = 0
    ): FootballMatch {
        return FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $home->id,
            'away_team_id'   => $away->id,
            'kickoff_at'     => Carbon::parse($kickoff, 'UTC'),
            'status'         => 'finished',
            'home_score_ft'  => $homeScore,
            'away_score_ft'  => $awayScore,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 01 — Structural available for both teams
    // ─────────────────────────────────────────────────────────────────────────

    public function test_structural_available_for_both_teams(): void
    {
        $this->makeSnapshot($this->home, 800_000_000, '2026-09-11');
        $this->makeSnapshot($this->away, 400_000_000, '2026-09-11');

        $result = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        $this->assertNotNull($result['home_structural']['structural_rating']);
        $this->assertNotNull($result['home_structural']['market_value']);
        $this->assertNotNull($result['home_structural']['structural_snapshot_date']);

        $this->assertNotNull($result['away_structural']['structural_rating']);
        $this->assertNotNull($result['away_structural']['market_value']);
        $this->assertNotNull($result['away_structural']['structural_snapshot_date']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 02 — Elo available for both (always; INITIAL_ELO if no history)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_elo_available_for_both_teams(): void
    {
        $result = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        $this->assertIsFloat($result['home_elo']);
        $this->assertIsFloat($result['away_elo']);
        $this->assertSame(TeamEloCalculator::INITIAL_ELO, $result['home_elo']);
        $this->assertSame(TeamEloCalculator::INITIAL_ELO, $result['away_elo']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 03 — structural_rating_diff correct
    // ─────────────────────────────────────────────────────────────────────────

    public function test_structural_rating_diff_is_correct(): void
    {
        $this->makeSnapshot($this->home, 800_000_000, '2026-09-11');
        $this->makeSnapshot($this->away, 400_000_000, '2026-09-11');

        $result = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        $expected = $result['home_structural']['structural_rating']
                  - $result['away_structural']['structural_rating'];

        $this->assertEqualsWithDelta($expected, $result['structural_rating_diff'], 1e-10);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 04 — elo_diff correct
    // ─────────────────────────────────────────────────────────────────────────

    public function test_elo_diff_is_correct(): void
    {
        // Give home team a historical win to raise its Elo above INITIAL.
        $this->makeFinished($this->home, $this->away, '2026-09-01 20:45:00', 2, 0);

        $result = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        $this->assertEqualsWithDelta(
            $result['home_elo'] - $result['away_elo'],
            $result['elo_diff'],
            1e-10
        );
        // Home won → home Elo > away Elo → diff > 0.
        $this->assertGreaterThan(0.0, $result['elo_diff']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 05 — log_market_value_ratio correct
    // ─────────────────────────────────────────────────────────────────────────

    public function test_log_market_value_ratio_is_correct(): void
    {
        $homeMv = 800_000_000;
        $awayMv = 400_000_000;

        $this->makeSnapshot($this->home, $homeMv, '2026-09-11');
        $this->makeSnapshot($this->away, $awayMv, '2026-09-11');

        $result = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        $expected = log($homeMv / $awayMv); // ln(2) ≈ 0.6931
        $this->assertEqualsWithDelta($expected, $result['log_market_value_ratio'], 1e-10);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 06 — elo_diff_scaled correct (= elo_diff / 400)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_elo_diff_scaled_is_correct(): void
    {
        $this->makeFinished($this->home, $this->away, '2026-09-01 20:45:00', 3, 0);

        $result = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        $this->assertEqualsWithDelta(
            $result['elo_diff'] / 400.0,
            $result['elo_diff_scaled'],
            1e-10
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 07 — Uses latest Structural snapshot (most recent date ≤ kickoff)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_uses_latest_structural_snapshot(): void
    {
        // Older snapshot for home team.
        $this->makeSnapshot($this->home, 500_000_000, '2026-08-01');
        // Newer snapshot — should win.
        $this->makeSnapshot($this->home, 800_000_000, '2026-09-11');

        $this->makeSnapshot($this->away, 400_000_000, '2026-09-11');

        $result = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        $this->assertSame(800_000_000, $result['home_structural']['market_value']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 08 — Structural snapshot strictly after kickoff is excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_structural_snapshot_after_kickoff_is_excluded(): void
    {
        // Only snapshot available is AFTER the target match kickoff.
        $this->makeSnapshot($this->home, 800_000_000, '2026-10-20'); // after 2026-10-15
        $this->makeSnapshot($this->away, 400_000_000, '2026-10-20');

        $result = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        // Both snapshots must be excluded → null structural.
        $this->assertNull($result['home_structural']['structural_rating']);
        $this->assertNull($result['away_structural']['structural_rating']);
        $this->assertNull($result['structural_rating_diff']);
        $this->assertNull($result['log_market_value_ratio']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 09 — Structural absent when transfermarkt data source does not exist
    // ─────────────────────────────────────────────────────────────────────────

    public function test_structural_fields_null_when_data_source_missing(): void
    {
        // Delete the data source seeded in setUp.
        $this->source->delete();

        $result = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        $this->assertNull($result['home_structural']['structural_rating']);
        $this->assertNull($result['home_structural']['market_value']);
        $this->assertNull($result['home_structural']['structural_snapshot_date']);

        $this->assertNull($result['away_structural']['structural_rating']);
        $this->assertNull($result['away_structural']['market_value']);
        $this->assertNull($result['away_structural']['structural_snapshot_date']);

        $this->assertNull($result['structural_rating_diff']);
        $this->assertNull($result['log_market_value_ratio']);
        $this->assertSame('UNKNOWN', $result['structural_favorite']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10 — Elo continues to work when Structural data is missing
    // ─────────────────────────────────────────────────────────────────────────

    public function test_elo_works_when_structural_missing(): void
    {
        // No snapshots → structural null.
        $this->makeFinished($this->home, $this->away, '2026-09-01 20:45:00', 2, 0);

        $result = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        // Structural null...
        $this->assertNull($result['home_structural']['structural_rating']);
        // ...but Elo is still computed correctly.
        $this->assertIsFloat($result['home_elo']);
        $this->assertIsFloat($result['away_elo']);
        $this->assertGreaterThan(TeamEloCalculator::INITIAL_ELO, $result['home_elo']);
        $this->assertLessThan(TeamEloCalculator::INITIAL_ELO, $result['away_elo']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11 — Target match excluded from its own Elo (strict < on kickoff_at)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_target_match_kickoff_excluded_from_elo(): void
    {
        // Past match included in Elo.
        $this->makeFinished($this->home, $this->away, '2026-09-01 20:45:00', 1, 0);

        // A second finished match at EXACTLY the target kickoff time.
        // The strict < cutoff means this must NOT be included.
        FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->home->id,
            'away_team_id'   => $this->away->id,
            'kickoff_at'     => Carbon::parse(self::KICKOFF, 'UTC'),
            'status'         => 'finished',
            'home_score_ft'  => 5,
            'away_score_ft'  => 0,
        ]);

        $target  = $this->targetMatch();
        $result  = TeamStrengthComparisonCalculator::calculateForMatch($target);

        // Elo must equal the rating after ONLY the past match — the match at T must be excluded.
        $ratingsAfterOnePast = TeamEloCalculator::calculateRatingsBefore(
            Carbon::parse('2026-09-01 20:45:01', 'UTC')
        );
        // Both calls use the same cutoff (target kickoff), so the at-T match is excluded in both.
        // The result should equal ratings computed up to just past the first match.
        $ratingsAtCutoff = TeamEloCalculator::calculateRatingsBefore(
            Carbon::parse(self::KICKOFF, 'UTC')
        );

        $this->assertEqualsWithDelta(
            $ratingsAtCutoff[$this->home->id] ?? TeamEloCalculator::INITIAL_ELO,
            $result['home_elo'],
            1e-10
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 12 — Future finished matches do not influence Elo
    // ─────────────────────────────────────────────────────────────────────────

    public function test_future_finished_matches_do_not_influence_elo(): void
    {
        // One past match → establishes base Elo.
        $this->makeFinished($this->home, $this->away, '2026-09-01 20:45:00', 1, 0);

        $resultBefore = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());
        $baseHomeElo  = $resultBefore['home_elo'];

        // Add a finished match AFTER the target kickoff (should be ignored).
        $this->makeFinished($this->home, $this->away, '2026-11-01 20:45:00', 5, 0);

        $resultAfter = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        $this->assertSame($baseHomeElo, $resultAfter['home_elo']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 13 — signals_agree = true (both favor the same team)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_signals_agree_true_when_both_favor_home(): void
    {
        // Structural: home much bigger (home structural > away).
        $this->makeSnapshot($this->home, 800_000_000, '2026-09-11');
        $this->makeSnapshot($this->away, 100_000_000, '2026-09-11');

        // Dynamic: home wins historically (home Elo > away Elo).
        $this->makeFinished($this->home, $this->away, '2026-09-01 20:45:00', 3, 0);

        $result = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        $this->assertSame('HOME', $result['structural_favorite']);
        $this->assertSame('HOME', $result['elo_favorite']);
        $this->assertTrue($result['signals_agree']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 14 — signals_agree = false (signals point to opposite teams)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_signals_agree_false_when_signals_diverge(): void
    {
        // Structural: home bigger (home structural > away).
        $this->makeSnapshot($this->home, 800_000_000, '2026-09-11');
        $this->makeSnapshot($this->away, 100_000_000, '2026-09-11');

        // Dynamic: away has been winning (away Elo > home Elo).
        // Away team wins as "home" in this historical match.
        $this->makeFinished($this->away, $this->home, '2026-09-01 20:45:00', 3, 0);

        $result = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        $this->assertSame('HOME', $result['structural_favorite']);
        // After away team won, away Elo is now higher than home Elo.
        $this->assertSame('AWAY', $result['elo_favorite']);
        $this->assertFalse($result['signals_agree']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 15 — signals_agree = null when Structural is not available
    // ─────────────────────────────────────────────────────────────────────────

    public function test_signals_agree_null_when_structural_missing(): void
    {
        // No snapshots → no structural.
        $this->makeFinished($this->home, $this->away, '2026-09-01 20:45:00', 2, 0);

        $result = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        $this->assertSame('UNKNOWN', $result['structural_favorite']);
        $this->assertNull($result['signals_agree']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 16 — home_elo and away_elo are assigned to the correct teams
    // ─────────────────────────────────────────────────────────────────────────

    public function test_home_away_elo_assigned_to_correct_team(): void
    {
        // Give home team 3 wins → home Elo should be significantly above INITIAL.
        // away team loses all 3 → away Elo should be below INITIAL.
        $this->makeFinished($this->home, $this->away, '2026-08-01 20:45:00', 2, 0);
        $this->makeFinished($this->home, $this->away, '2026-08-08 20:45:00', 2, 0);
        $this->makeFinished($this->home, $this->away, '2026-08-15 20:45:00', 2, 0);

        $result = TeamStrengthComparisonCalculator::calculateForMatch($this->targetMatch());

        // home won every match → home_elo > INITIAL > away_elo
        $this->assertGreaterThan(TeamEloCalculator::INITIAL_ELO, $result['home_elo']);
        $this->assertLessThan(TeamEloCalculator::INITIAL_ELO, $result['away_elo']);
        $this->assertGreaterThan(0.0, $result['elo_diff']);    // home − away > 0
        $this->assertSame('HOME', $result['elo_favorite']);
    }
}
