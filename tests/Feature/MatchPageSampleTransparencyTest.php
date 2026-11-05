<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\MatchPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-match page: size and scope of the samples behind the statistics are explicit (match 12031 audit),
 * and a fallback 1X2 estimate is flagged. No statistic or probability is changed.
 */
class MatchPageSampleTransparencyTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;
    private Competition $c;
    private Season $s;
    private Team $home;
    private Team $away;
    private Team $other;

    protected function setUp(): void
    {
        parent::setUp();
        $country = Country::create(['name' => 'Europe', 'code' => 'EU']);
        $this->c = Competition::create(['name' => 'UEFA Europa League', 'slug' => 'uefa-europa-league', 'country_id' => $country->id]);
        $this->s = Season::create(['competition_id' => $this->c->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        $this->home = Team::create(['name' => 'AC Milan', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Ferencvarosi TC', 'country_id' => $country->id]);
        $this->other = Team::create(['name' => 'Other FC', 'country_id' => $country->id]);

        $this->played($this->home, $this->other, '2026-10-22 19:00:00', 'League Stage - 3');
        $this->played($this->other, $this->away, '2026-07-09 18:00:00', '1st Qualifying Round');
        $this->played($this->away, $this->other, '2026-07-16 18:00:00', '1st Qualifying Round');
        $this->played($this->away, $this->other, '2026-10-15 19:00:00', 'League Stage - 2');

        $this->match = FootballMatch::create([
            'competition_id' => $this->c->id, 'season_id' => $this->s->id, 'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
            'kickoff_at' => '2026-11-05 17:45:00', 'status' => 'scheduled', 'round' => 'League Stage - 4',
        ]);
    }

    private function played(Team $h, Team $a, string $at, string $round): void
    {
        FootballMatch::create([
            'competition_id' => $this->c->id, 'season_id' => $this->s->id, 'home_team_id' => $h->id, 'away_team_id' => $a->id,
            'kickoff_at' => $at, 'status' => 'finished', 'round' => $round, 'home_score_ft' => 1, 'away_score_ft' => 0,
        ]);
    }

    private function mockPrediction(bool $candidate): void
    {
        $full59 = ['lambda_home' => 1.69, 'lambda_away' => 1.44, 'probability_home' => 0.437, 'probability_draw' => 0.235, 'probability_away' => 0.328];
        $pred = $this->createMock(MatchPredictionService::class);
        $pred->method('predictWithDebug')->willReturn($full59 + [
            'match_id' => $this->match->id, 'model_version' => '1.0.0', 'feature_set_version' => 'core_v1', 'feature_count' => 59,
            'null_count' => 0, 'features' => array_fill_keys(array_map(fn ($i) => "core_feature_{$i}", range(1, 59)), 1.0),
            'timing_agg_ms' => 1.0, 'timing_inf_ms' => 0.1, 'timing_total_ms' => 1.1,
        ]);
        $this->app->instance(MatchPredictionService::class, $pred);

        $v2 = $candidate ? ['lambda_home' => 1.77, 'lambda_away' => 1.27, 'lambda3' => 0.15, 'probability_home' => 0.49, 'probability_draw' => 0.25, 'probability_away' => 0.26] : null;
        $old = ['lambda_home' => 1.3, 'lambda_away' => 1.1, 'probability_home' => 0.40, 'probability_draw' => 0.30, 'probability_away' => 0.30];
        $inputs = ['structural_home' => 1.0, 'structural_away' => 1.0, 'structural_gap' => 0.0];
        $cand = $this->createMock(CandidateModelService::class);
        $cand->method('compare')->willReturn([
            'full59' => $full59, 'no_e9' => $old, 'no_e9_no_e10' => $old, 'candidate40' => $old,
            'candidate40_robust_bp' => $old + ['lambda3' => 0.15], 'candidate44_bp' => $old + ['lambda3' => 0.15],
            'candidate48_structural' => $v2, 'candidate48_structural_inputs' => $v2 ? $inputs : null,
            'candidate47_structural_log' => $v2, 'candidate47_structural_log_inputs' => $v2 ? $inputs : null,
            'no_e9_available' => true, 'no_e10_available' => true, 'candidate40_available' => true,
            'candidate40_robust_bp_available' => true, 'candidate44_bp_available' => true,
            'candidate48_structural_available' => $v2 !== null, 'candidate47_structural_log_available' => $v2 !== null,
        ]);
        $this->app->instance(CandidateModelService::class, $cand);
    }

    public function test_uefa_league_stage_season_blocks_use_only_same_phase_matches(): void
    {
        $this->mockPrediction(true);

        $r = $this->get(route('matches.show', $this->match));

        $r->assertOk();
        $r->assertSee('data-testid="season-sample-note-competition"', false);
        $r->assertSee('League Stage</strong> (stessa fase di questa partita)', false);
        $r->assertDontSee('tutte le fasi disputate');
        // Ferencvarosi: the 2 qualifying-round matches are excluded from the seasonal comparison (1 league-stage match left)
        $r->assertSee('AC Milan: 1 partita, dal 22/10/2026 al 22/10/2026', false);
        $r->assertSee('Ferencvarosi TC: 1 partita, dal 15/10/2026 al 15/10/2026', false);
    }

    public function test_recent_matches_list_follows_scope_with_phase_opponent_date_score_and_popover(): void
    {
        $this->mockPrediction(true);
        // a match AFTER the kickoff and a non-final one must never appear
        $this->played($this->away, $this->other, '2026-11-20 19:00:00', 'League Stage - 5');
        FootballMatch::create([
            'competition_id' => $this->c->id, 'season_id' => $this->s->id, 'home_team_id' => $this->away->id, 'away_team_id' => $this->other->id,
            'kickoff_at' => '2026-10-29 19:00:00', 'status' => 'scheduled', 'round' => 'League Stage - 4',
        ]);

        $r = $this->get(route('matches.show', $this->match));

        $r->assertSee('data-testid="recent-matches-total"', false);
        $r->assertSee('data-testid="recent-matches-competition"', false);
        $s = $r->viewData('scopes');
        // Totale: every competition/phase; Competizione: same phase (League Stage) only
        $total = $s['total']['recent']['away'];
        $this->assertSame(['qualification', 'qualification', 'league_stage'], array_column($total, 'phase'));
        $this->assertSame(['09/07/2026', '16/07/2026', '15/10/2026'], array_map(fn ($u) => $u['date']->format('d/m/Y'), $total));
        $this->assertSame('Other FC', $total[0]['opponent']);
        $this->assertSame('T', $total[0]['venue']);          // 09/07: Other FC - Ferencvarosi (away)
        $this->assertSame('C', $total[1]['venue']);
        $this->assertSame(['league_stage'], array_column($s['competition']['recent']['away'], 'phase'));
        $this->assertCount(1, $s['total']['recent']['home']);
        $r->assertSee('Qualificazioni / Play-off');
        // nice popover: title (date · competition · phase), body (teams, score, venue, verdict), focusable for touch
        $r->assertSee('data-tip-title="09/07/2026 · UEFA Europa League · Qualificazioni / Play-off"', false);
        $r->assertSee('data-tip-body="', false);
        $r->assertSee('Other FC', false);
        $r->assertSee('In trasferta · Sconfitta', false);   // 09/07: Other FC 1-0 Ferencvarosi (Ferencvarosi away, lost)
        $r->assertSee('tabindex="0" role="button" data-tip-title=', false);
        $r->assertDontSee('20/11/2026');
        $r->assertDontSee('29/10/2026');
    }

    public function test_domestic_match_keeps_full_season_set_and_has_no_uefa_blocks_and_form_tooltips(): void
    {
        $this->c->update(['slug' => 'bundesliga']);
        config(['api-football.core_leagues' => [78 => 'bundesliga']]);
        $this->mockPrediction(true);

        $r = $this->get(route('matches.show', $this->match));

        $r->assertOk();
        $r->assertDontSee('data-testid="uefa-form"', false);
        $r->assertSee('tutte le fasi disputate (comprese eventuali qualificazioni)');
        $r->assertSee('Ferencvarosi TC: 3 partite, dal 09/07/2026 al 15/10/2026', false);   // unfiltered
        // Team History form badges carry the match as tooltip (oldest first)
        $r->assertSee('data-tip-title="22/10/2026 · UEFA Europa League"', false);
        $r->assertSee('&lt;strong&gt;AC Milan&lt;/strong&gt;', false);   // the analysed team is highlighted inside the popover body
        $r->assertDontSee('data-testid="th-form-details"', false);   // domestic: Team History form stays the main (visible) form
    }

    public function test_uefa_phase_classification(): void
    {
        foreach (['1st Qualifying Round', '2nd Qualifying Round', '3rd Qualifying Round', 'Play-offs', 'Playoff round'] as $r) {
            $this->assertSame('qualification', \App\Services\Analytics\UefaPhase::fromRound($r), $r);
        }
        $this->assertSame('league_stage', \App\Services\Analytics\UefaPhase::fromRound('League Stage - 4'));
        foreach (['Knockout Round Play-offs', 'Round of 32', 'Round of 16', 'Quarter-finals', 'Semi-finals', 'Final'] as $r) {
            $this->assertSame('knockout', \App\Services\Analytics\UefaPhase::fromRound($r), $r);
        }
        $this->assertNull(\App\Services\Analytics\UefaPhase::fromRound(null));
        $this->assertNull(\App\Services\Analytics\UefaPhase::fromRound('Regular Season - 3'));
    }

    public function test_form_block_flags_reduced_sample_with_actual_size(): void
    {
        $this->mockPrediction(true);

        $r = $this->get(route('matches.show', $this->match));

        // fewer than 10 matches in both scopes -> flagged, with the actual size and period (Totale: all matches in the DB)
        $r->assertSee('data-testid="form-sample-note-home"', false);
        $r->assertSee('data-testid="form-sample-note-away"', false);
        $r->assertSee('campione ridotto');
        $r->assertSee('Campione: 3 partite, dal 09/07/2026 al 15/10/2026', false);   // Ferencvarosi, Totale
    }

    public function test_scope_selector_exposes_totale_and_competizione_with_their_own_samples(): void
    {
        $this->mockPrediction(true);

        $r = $this->get(route('matches.show', $this->match));

        $r->assertSee('data-testid="scope-selector"', false);
        $r->assertSee('id="scope-total"', false);
        $r->assertSee('id="scope-competition"', false);
        $s = $r->viewData('scopes');
        // Totale: every competition/phase of the season in the DB; Competizione: only the same phase (League Stage)
        $this->assertSame(1, $s['total']['sample']['season']['home']['n']);
        $this->assertSame(3, $s['total']['sample']['season']['away']['n']);
        $this->assertSame(1, $s['competition']['sample']['season']['away']['n']);
        $r->assertSee('data-testid="season-sample-note-total"', false);
        $r->assertSee('data-testid="season-sample-note-competition"', false);
        $r->assertSee('Ferencvarosi TC: 3 partite, dal 09/07/2026 al 15/10/2026', false);
        $r->assertSee('Ferencvarosi TC: 1 partita, dal 15/10/2026 al 15/10/2026', false);
    }

    public function test_total_scope_flags_missing_national_league_only_for_the_uncovered_team(): void
    {
        $this->mockPrediction(true);
        $country = Country::first();
        $bl = Competition::create(['name' => 'Serie A', 'slug' => 'serie-a', 'country_id' => $country->id]);
        $bs = Season::create(['competition_id' => $bl->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        FootballMatch::create([
            'competition_id' => $bl->id, 'season_id' => $bs->id, 'home_team_id' => $this->home->id, 'away_team_id' => $this->other->id,
            'kickoff_at' => '2026-10-25 18:00:00', 'status' => 'finished', 'round' => 'Regular Season - 9', 'home_score_ft' => 2, 'away_score_ft' => 2,
        ]);
        config(['api-football.core_leagues' => [135 => 'serie-a']]);

        $r = $this->get(route('matches.show', $this->match));

        $r->assertDontSee('data-testid="coverage-note-home"', false);           // AC Milan: Serie A is in the DB
        $r->assertSee('data-testid="coverage-note-away"', false);               // Ferencvarosi: national league missing
        $r->assertSee('non necessariamente tutte quelle disputate');
        // the Serie A match counts in Milan's Totale (2 matches), not in its Competizione (1)
        $s = $r->viewData('scopes');
        $this->assertSame(2, $s['total']['sample']['season']['home']['n']);
        $this->assertSame(1, $s['competition']['sample']['season']['home']['n']);
        $r->assertSee('data-tip-title="25/10/2026 · Serie A"', false);   // tooltip: date and competition (teams/result in the body)
    }

    public function test_results_only_league_match_appears_in_totale_but_not_in_competizione(): void
    {
        $this->mockPrediction(true);
        $hun = Competition::create(['name' => 'NB I', 'slug' => 'hungary-nb-i', 'country_id' => Country::first()->id]);
        $hs = Season::create(['competition_id' => $hun->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027]);
        FootballMatch::create([
            'competition_id' => $hun->id, 'season_id' => $hs->id, 'home_team_id' => $this->away->id, 'away_team_id' => $this->other->id,
            'kickoff_at' => '2026-10-25 17:00:00', 'status' => 'finished', 'round' => 'Regular Season - 12', 'home_score_ft' => 3, 'away_score_ft' => 0,
        ]);
        config(['api-football.results_only_competitions' => [271 => 'hungary-nb-i']]);

        $r = $this->get(route('matches.show', $this->match));

        $s = $r->viewData('scopes');
        $this->assertSame(4, $s['total']['sample']['season']['away']['n']);        // 3 UEFA + the Hungarian league match
        $this->assertSame(1, $s['competition']['sample']['season']['away']['n']);  // League Stage only: unchanged
        $this->assertContains('NB I', array_column($s['total']['recent']['away'], 'competition'));
        $this->assertNotContains('NB I', array_column($s['competition']['recent']['away'], 'competition'));
        $r->assertSee('data-tip-title="25/10/2026 · NB I"', false);
        // the imported national league now covers the team: no "Copertura parziale" note for it
        $this->assertTrue($s['total']['covered']['away']);
        $r->assertDontSee('data-testid="coverage-note-away"', false);
    }

    public function test_unavailable_statistics_are_not_replaced_by_zero(): void
    {
        $this->mockPrediction(true);

        $html = $this->get(route('matches.show', $this->match))->getContent();

        // no match_statistics in the fixture: technical averages stay "–" (never 0.00)
        $this->assertMatchesRegularExpression('#Media tiri fatti</td><td>–</td><td>–</td>#u', $html);
        $this->assertDoesNotMatchRegularExpression('#Media tiri fatti</td><td>0\.00#', $html);
    }

    public function test_fallback_estimate_is_flagged(): void
    {
        $this->mockPrediction(false);

        $this->get(route('matches.show', $this->match))
            ->assertSee('data-testid="public-prediction-fallback-note"', false)
            ->assertSee('modello di riserva FULL59')
            ->assertDontSee('Affidabilità', false);
    }

    public function test_candidate_estimate_is_not_flagged(): void
    {
        $this->c->update(['slug' => 'bundesliga']);   // UEFA club competitions use the artifact-based profile; domestic uses the mocked Candidate47
        $this->mockPrediction(true);

        $this->get(route('matches.show', $this->match))
            ->assertDontSee('public-prediction-fallback-note', false);
    }
}
