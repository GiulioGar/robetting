<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\PreMatchFeatureAggregator;
use App\Services\Prediction\HistoricalPredictionDatasetBuilder;
use App\Services\Prediction\TeamHistoryMatchEligibility;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step C1 — opt-in Team History recent window.
 *
 * Verifies:
 *  - legacy (default) is byte-identical to pre-C1 behaviour;
 *  - team_history includes CORE+TEAM_HISTORY matches cross-competition/season;
 *  - ELO_ONLY matches never leak into a core team's history;
 *  - the window is exactly the last 10 eligible matches, chronologically;
 *  - strict as-of (no match with kickoff >= target);
 *  - runtime (aggregate()) and training (HistoricalPredictionDatasetBuilder)
 *    produce identical core_recent_* values for the same target + mode.
 */
class PreMatchFeatureAggregatorTeamHistoryModeTest extends TestCase
{
    use RefreshDatabase;

    private Competition $serieA;
    private Competition $championsLeague;
    private Competition $coppaItalia;
    private Season $serieASeason2026;
    private Season $serieASeason2025;
    private Season $clSeason2026;
    private Season $ciSeason2026;
    private Team $napoli;
    private Team $opponentCore;

    protected function setUp(): void
    {
        parent::setUp();

        config(['api-football.core_leagues'            => [135 => 'serie-a']]);
        config(['api-football.additional_competitions'  => [2 => 'uefa-champions-league', 137 => 'coppa-italia']]);

        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);

        $this->serieA = Competition::create(['country_id' => $country->id, 'name' => 'Serie A', 'slug' => 'serie-a', 'format' => 'league', 'is_active' => true]);
        $this->championsLeague = Competition::create(['country_id' => $country->id, 'name' => 'UEFA Champions League', 'slug' => 'uefa-champions-league', 'format' => 'cup', 'is_active' => true]);
        $this->coppaItalia = Competition::create(['country_id' => $country->id, 'name' => 'Coppa Italia', 'slug' => 'coppa-italia', 'format' => 'cup', 'is_active' => true]);

        $this->serieASeason2025 = Season::create(['competition_id' => $this->serieA->id, 'name' => '2025/26', 'year_start' => 2025, 'year_end' => 2026, 'is_current' => false]);
        $this->serieASeason2026 = Season::create(['competition_id' => $this->serieA->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);
        $this->clSeason2026     = Season::create(['competition_id' => $this->championsLeague->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);
        $this->ciSeason2026     = Season::create(['competition_id' => $this->coppaItalia->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);

        $this->napoli       = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->opponentCore = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);

        // Napoli is core in BOTH 2025 and 2026 Serie A seasons (year_start matters, not season_id).
        $this->serieASeason2025->teams()->attach([$this->napoli->id, $this->opponentCore->id]);
        $this->serieASeason2026->teams()->attach([$this->napoli->id, $this->opponentCore->id]);
    }

    private function makeMatch(Competition $comp, Season $season, Team $home, Team $away, string $kickoff, int $hg = 1, int $ag = 0): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $comp->id,
            'season_id'      => $season->id,
            'home_team_id'   => $home->id,
            'away_team_id'   => $away->id,
            'kickoff_at'     => Carbon::parse($kickoff),
            'status'         => 'finished',
            'home_score_ft'  => $hg,
            'away_score_ft'  => $ag,
        ]);
    }

    private function makeTargetMatch(string $kickoff): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $this->serieA->id,
            'season_id'      => $this->serieASeason2026->id,
            'home_team_id'   => $this->napoli->id,
            'away_team_id'   => $this->opponentCore->id,
            'kickoff_at'     => Carbon::parse($kickoff),
            'status'         => 'scheduled',
        ]);
    }

    // -------------------------------------------------------------------------
    // Legacy invariato
    // -------------------------------------------------------------------------

    public function test_legacy_mode_ignores_other_competitions_and_previous_season(): void
    {
        $opp = Team::create(['name' => 'Verona', 'type' => 'club', 'is_active' => true]);

        // 1 Serie A 2026 match (counts), 1 Champions League match (must be ignored),
        // 1 Coppa Italia match (must be ignored), 1 Serie A 2025 match (must be ignored — different season_id).
        $this->makeMatch($this->serieA, $this->serieASeason2026, $this->napoli, $opp, '2026-09-01 18:00:00');
        $this->makeMatch($this->championsLeague, $this->clSeason2026, $this->napoli, $opp, '2026-09-05 20:00:00');
        $this->makeMatch($this->coppaItalia, $this->ciSeason2026, $this->napoli, $opp, '2026-09-10 20:00:00');
        $this->makeMatch($this->serieA, $this->serieASeason2025, $this->napoli, $opp, '2026-05-01 18:00:00');

        $target = $this->makeTargetMatch('2026-10-01 20:45:00');

        $snapshot = PreMatchFeatureAggregator::aggregate($target); // default = legacy
        $flat     = PreMatchFeatureAggregator::flatten($snapshot);

        $this->assertSame(1, $flat['core_recent_home_matches_considered']);
    }

    // -------------------------------------------------------------------------
    // Team History: cross-competition, cross-season sequence
    // -------------------------------------------------------------------------

    public function test_team_history_mode_includes_cross_competition_cross_season_sequence(): void
    {
        // Sequence: Serie A (2025, previous season) -> Champions -> Coppa Italia -> Serie A (2026).
        $this->makeMatch($this->serieA, $this->serieASeason2025, $this->napoli, $this->opponentCore, '2026-05-01 18:00:00', 2, 0);
        $this->makeMatch($this->championsLeague, $this->clSeason2026, $this->napoli, $this->opponentCore, '2026-09-05 20:00:00', 1, 1);
        $this->makeMatch($this->coppaItalia, $this->ciSeason2026, $this->napoli, $this->opponentCore, '2026-09-10 20:00:00', 3, 0);
        $this->makeMatch($this->serieA, $this->serieASeason2026, $this->napoli, $this->opponentCore, '2026-09-20 18:00:00', 0, 0);

        $target = $this->makeTargetMatch('2026-10-01 20:45:00');

        $snapshot = PreMatchFeatureAggregator::aggregate($target, null, PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY);
        $flat     = PreMatchFeatureAggregator::flatten($snapshot);

        $this->assertSame(4, $flat['core_recent_home_matches_considered']);
        // avg_goals_for = (2+1+3+0)/4 = 1.5
        $this->assertSame(1.5, $flat['core_recent_home_avg_goals_for']);
    }

    // -------------------------------------------------------------------------
    // Cross-season alone
    // -------------------------------------------------------------------------

    public function test_team_history_mode_crosses_season_boundary_for_august_target(): void
    {
        $this->makeMatch($this->serieA, $this->serieASeason2025, $this->napoli, $this->opponentCore, '2026-05-10 18:00:00');
        $this->makeMatch($this->serieA, $this->serieASeason2025, $this->napoli, $this->opponentCore, '2026-05-17 18:00:00');

        // Target in August 2026 — new season hasn't produced any matches yet.
        $target = $this->makeTargetMatch('2026-08-15 20:45:00');

        $legacySnapshot = PreMatchFeatureAggregator::aggregate($target); // legacy: season-bound
        $legacyFlat      = PreMatchFeatureAggregator::flatten($legacySnapshot);
        $this->assertSame(0, $legacyFlat['core_recent_home_matches_considered'], 'legacy must NOT see the previous season');

        $thSnapshot = PreMatchFeatureAggregator::aggregate($target, null, PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY);
        $thFlat     = PreMatchFeatureAggregator::flatten($thSnapshot);
        $this->assertSame(2, $thFlat['core_recent_home_matches_considered'], 'team_history must see end-of-previous-season matches');
    }

    // -------------------------------------------------------------------------
    // ELO_ONLY never leaks in
    // -------------------------------------------------------------------------

    public function test_elo_only_match_never_appears_in_core_team_history(): void
    {
        $psv     = Team::create(['name' => 'PSV', 'type' => 'club', 'is_active' => true]);
        $benfica = Team::create(['name' => 'Benfica', 'type' => 'club', 'is_active' => true]);

        // PSV vs Benfica: neither is core -> ELO_ONLY. Unrelated to Napoli.
        $this->makeMatch($this->championsLeague, $this->clSeason2026, $psv, $benfica, '2026-09-05 20:00:00');

        $this->makeMatch($this->serieA, $this->serieASeason2026, $this->napoli, $this->opponentCore, '2026-09-20 18:00:00');

        $target = $this->makeTargetMatch('2026-10-01 20:45:00');

        $history = TeamHistoryMatchEligibility::matchesBefore((int) $this->napoli->id, $target->kickoff_at);

        $this->assertCount(1, $history);
        $this->assertFalse($history->pluck('home_team_id')->merge($history->pluck('away_team_id'))->contains($psv->id));
    }

    // -------------------------------------------------------------------------
    // Window: exactly the last 10
    // -------------------------------------------------------------------------

    public function test_team_history_window_is_exactly_last_10_chronological(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $day = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->makeMatch($this->serieA, $this->serieASeason2026, $this->napoli, $this->opponentCore, "2026-08-{$day} 18:00:00", $i, 0);
        }

        $target = $this->makeTargetMatch('2026-10-01 20:45:00');

        $snapshot = PreMatchFeatureAggregator::aggregate($target, null, PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY);
        $flat     = PreMatchFeatureAggregator::flatten($snapshot);

        $this->assertSame(10, $flat['core_recent_home_matches_considered']);
        // matches #6..#15 (goals 6..15) are the last 10 -> avg = (6+...+15)/10 = 10.5
        $this->assertSame(10.5, $flat['core_recent_home_avg_goals_for']);
    }

    // -------------------------------------------------------------------------
    // Strict as-of
    // -------------------------------------------------------------------------

    public function test_no_match_with_kickoff_at_or_after_target_is_included(): void
    {
        $target = $this->makeTargetMatch('2026-10-01 20:45:00');

        // Exactly at target kickoff, and after — both must be excluded.
        $this->makeMatch($this->serieA, $this->serieASeason2026, $this->napoli, $this->opponentCore, '2026-10-01 20:45:00');
        $this->makeMatch($this->serieA, $this->serieASeason2026, $this->napoli, $this->opponentCore, '2026-10-02 20:45:00');

        $history = TeamHistoryMatchEligibility::matchesBefore((int) $this->napoli->id, $target->kickoff_at);

        $this->assertCount(0, $history);
    }

    // -------------------------------------------------------------------------
    // Runtime / training parity
    // -------------------------------------------------------------------------

    public function test_runtime_and_training_produce_identical_team_history_values(): void
    {
        $this->makeMatch($this->serieA, $this->serieASeason2025, $this->napoli, $this->opponentCore, '2026-05-01 18:00:00', 2, 1);
        $this->makeMatch($this->championsLeague, $this->clSeason2026, $this->napoli, $this->opponentCore, '2026-09-05 20:00:00', 1, 1);
        $this->makeMatch($this->serieA, $this->serieASeason2026, $this->napoli, $this->opponentCore, '2026-09-20 18:00:00', 0, 0);

        // Target must itself be a finished, valid-score match for
        // HistoricalPredictionDatasetBuilder to produce a row (it skips
        // matches without a valid label) — parity is about the SAME inputs
        // producing the SAME core_recent_* output in both paths.
        $target = $this->makeMatch($this->serieA, $this->serieASeason2026, $this->napoli, $this->opponentCore, '2026-10-01 20:45:00', 2, 2);

        $runtimeSnapshot = PreMatchFeatureAggregator::aggregate($target, null, PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY);
        $runtimeFlat     = PreMatchFeatureAggregator::flatten($runtimeSnapshot);

        $builderResult = (new HistoricalPredictionDatasetBuilder())->build(
            collect([$target]),
            'core_only',
            PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY,
        );

        $this->assertCount(1, $builderResult['rows']);
        $trainingRow = $builderResult['rows'][0];

        $this->assertSame(3, $runtimeFlat['core_recent_home_matches_considered']);
        $this->assertSame(
            $runtimeFlat['core_recent_home_matches_considered'],
            $trainingRow['core_recent_home_matches_considered'],
        );
        $this->assertSame(
            $runtimeFlat['core_recent_home_avg_goals_for'],
            $trainingRow['core_recent_home_avg_goals_for'],
        );
    }
}
