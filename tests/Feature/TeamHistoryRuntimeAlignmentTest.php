<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\PreMatchFeatureAggregator;
use App\Services\Prediction\MatchPredictionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Team History is the standard semantics of the team "Recent" metrics:
 *  - aggregate() defaults to team_history; legacy stays invocable explicitly;
 *  - the match page Recent blocks and the prediction read the same window
 *    (Serie A + Champions League, e.g. Monza-Napoli);
 *  - competition-specific blocks (season context, league context, H2H) stay
 *    competition-bound.
 */
class TeamHistoryRuntimeAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private Competition $serieA;
    private Competition $champions;
    private Season $serieASeason;
    private Season $clSeason;
    private Team $monza;
    private Team $napoli;
    private Team $other;

    protected function setUp(): void
    {
        parent::setUp();

        config(['api-football.core_leagues'           => [135 => 'serie-a']]);
        config(['api-football.additional_competitions' => [2 => 'uefa-champions-league']]);

        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);

        $this->serieA    = Competition::create(['country_id' => $country->id, 'name' => 'Serie A', 'slug' => 'serie-a', 'format' => 'league', 'is_active' => true]);
        $this->champions = Competition::create(['country_id' => $country->id, 'name' => 'UEFA Champions League', 'slug' => 'uefa-champions-league', 'format' => 'cup', 'is_active' => true]);

        $this->serieASeason = Season::create(['competition_id' => $this->serieA->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);
        $this->clSeason     = Season::create(['competition_id' => $this->champions->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);

        $this->monza  = Team::create(['name' => 'Monza', 'type' => 'club', 'is_active' => true]);
        $this->napoli = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->other  = Team::create(['name' => 'Verona', 'type' => 'club', 'is_active' => true]);

        $this->serieASeason->teams()->attach([$this->monza->id, $this->napoli->id, $this->other->id]);
    }

    private function finished(Competition $c, Season $s, Team $home, Team $away, string $kickoff, int $hg, int $ag): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $c->id, 'season_id' => $s->id,
            'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'kickoff_at' => Carbon::parse($kickoff), 'status' => 'finished',
            'home_score_ft' => $hg, 'away_score_ft' => $ag,
        ]);
    }

    /** Napoli: 2 Serie A + 1 Champions League before the target; Monza: 1 Serie A. */
    private function monzaNapoli(): FootballMatch
    {
        $this->finished($this->serieA, $this->serieASeason, $this->napoli, $this->other, '2026-09-13 18:00:00', 2, 0);
        $this->finished($this->champions, $this->clSeason, $this->napoli, $this->other, '2026-09-17 21:00:00', 1, 1);
        $this->finished($this->serieA, $this->serieASeason, $this->other, $this->napoli, '2026-09-27 18:00:00', 0, 3);
        $this->finished($this->serieA, $this->serieASeason, $this->monza, $this->other, '2026-09-20 18:00:00', 1, 0);

        return FootballMatch::create([
            'competition_id' => $this->serieA->id, 'season_id' => $this->serieASeason->id,
            'home_team_id' => $this->monza->id, 'away_team_id' => $this->napoli->id,
            'kickoff_at' => Carbon::now()->addDays(3), 'status' => 'scheduled',
        ]);
    }

    public function test_aggregate_default_recent_window_is_team_history_and_includes_champions(): void
    {
        $target = $this->monzaNapoli();

        $default = PreMatchFeatureAggregator::flatten(PreMatchFeatureAggregator::aggregate($target));
        $explicit = PreMatchFeatureAggregator::flatten(
            PreMatchFeatureAggregator::aggregate($target, null, PreMatchFeatureAggregator::RECENT_WINDOW_TEAM_HISTORY)
        );

        $this->assertSame(3, $default['core_recent_away_matches_considered']); // 2 Serie A + 1 Champions
        $this->assertSame($explicit['core_recent_away_matches_considered'], $default['core_recent_away_matches_considered']);
        $this->assertSame($explicit['core_recent_away_avg_goals_for'], $default['core_recent_away_avg_goals_for']);
    }

    public function test_legacy_remains_invocable_explicitly_and_excludes_champions(): void
    {
        $target = $this->monzaNapoli();

        $legacy = PreMatchFeatureAggregator::flatten(
            PreMatchFeatureAggregator::aggregate($target, null, PreMatchFeatureAggregator::RECENT_WINDOW_LEGACY)
        );

        $this->assertSame(2, $legacy['core_recent_away_matches_considered']); // Serie A only
    }

    public function test_prediction_service_keeps_legacy_defaults_for_legacy_model_family(): void
    {
        // 'features' (FULL59/C40/C44) stay legacy; Candidate47 gets 'features_candidate47'
        // (team_history + extended) — see Candidate47TeamHistoryRoutingTest.
        $params = (new ReflectionMethod(MatchPredictionService::class, 'predictWithDebug'))->getParameters();

        $this->assertSame(PreMatchFeatureAggregator::RECENT_WINDOW_LEGACY, $params[1]->getDefaultValue());
        $this->assertSame(PreMatchFeatureAggregator::ELO_MODE_LEGACY, $params[2]->getDefaultValue());
    }

    public function test_match_page_recent_blocks_use_the_same_team_history_as_the_prediction(): void
    {
        $target = $this->monzaNapoli();

        $flat = PreMatchFeatureAggregator::flatten(PreMatchFeatureAggregator::aggregate($target));

        $response = $this->get(route('matches.show', $target, absolute: false));
        $response->assertOk();

        // Page "Prestazione recente" / "Forma prima del match" (Ultime 10) for Napoli
        // = same 3 matches the prediction's core_recent_away_* is computed on.
        $awayLast10 = $response->viewData('awayLast10Analytics');
        $this->assertSame(3, $awayLast10['summary']['matches_played']);
        $this->assertSame($flat['core_recent_away_matches_considered'], $awayLast10['summary']['matches_played']);
        $this->assertSame(3, $response->viewData('awayLast5Analytics')['summary']['matches_played']);

        // Monza: Serie A only (no other official match).
        $this->assertSame(1, $response->viewData('homeLast10Analytics')['summary']['matches_played']);
    }

    public function test_competition_specific_blocks_stay_competition_bound(): void
    {
        $target = $this->monzaNapoli();

        $response = $this->get(route('matches.show', $target, absolute: false));
        $response->assertOk();

        // Season trend / league context are Serie A + this season only: Napoli's
        // Champions League match is excluded (2 Serie A matches).
        $this->assertSame(2, $response->viewData('awaySeasonAnalytics')['summary']['matches_played']);
        // League context: only Serie A finished matches of the season (3), no Champions.
        $this->assertSame(3, $response->viewData('leagueContext')['matches_considered']);
    }
}
