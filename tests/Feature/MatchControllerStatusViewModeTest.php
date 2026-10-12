<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\MatchLineupPlayer;
use App\Models\MatchStatistic;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Step 1B.2/1C/1C.1 — /matches/{match} status-based view modes.
 *
 * MatchController::show() classifies every match into 'pre_match'
 * (status=scheduled), 'finished' (status=finished), or 'other' (anything
 * else — today: awarded, abandoned). 'finished'/'other' must never run the
 * pre-match analytics pipeline (13 calculators + previousMatches()/
 * allCompetitionPreviousMatches()), not merely hide it in the view.
 *
 * Step 1C.1 additionally hides the REAL match-data blocks (Statistiche
 * match/Eventi/Formazioni) for 'pre_match' — those belong to the played
 * match, never to a pre-match preview — while 'finished'/'other' keep
 * showing them exactly as before.
 */
class MatchControllerStatusViewModeTest extends TestCase
{
    use RefreshDatabase;

    private Competition $comp;
    private Season $season;
    private Team $home;
    private Team $away;

    protected function setUp(): void
    {
        parent::setUp();

        $country    = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->comp = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a-1b2',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $this->season = Season::create([
            'competition_id' => $this->comp->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
        ]);
        $this->home = Team::create(['name' => 'Inter', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Udinese', 'country_id' => $country->id]);
    }

    private function makeMatch(array $attributes): FootballMatch
    {
        return FootballMatch::create(array_merge([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $this->home->id,
            'away_team_id'   => $this->away->id,
            'kickoff_at'     => now()->addDay(),
            'status'         => 'scheduled',
        ], $attributes));
    }

    /** A handful of distinct prior finished matches so pre-match analytics have real history to crunch. */
    private function seedHistory(int $n = 3): void
    {
        for ($i = 1; $i <= $n; $i++) {
            FootballMatch::create([
                'competition_id' => $this->comp->id,
                'season_id'      => $this->season->id,
                'home_team_id'   => $this->home->id,
                'away_team_id'   => $this->away->id,
                'kickoff_at'     => now()->subDays($i * 10),
                'status'         => 'finished',
                'home_score_ft'  => 1,
                'away_score_ft'  => 0,
            ]);
        }
    }

    private const PRE_MATCH_HEADERS = [
        'Carico recente',
        'Forza dinamica Elo',
        'Confronto forza squadre',
        'Qualità avversari recenti',
        'Contesto campionato',
        'Precedenti',
    ];

    private const PRE_MATCH_VIEW_KEYS = [
        'eloData', 'headToHead', 'leagueContext', 'homeTimeDecay',
        'homeOpponentQuality', 'homeSeasonAnalytics', 'strengthComparison',
    ];

    // 1. scheduled: prediction/pre-match analytics presenti, comportamento invariato.
    public function test_scheduled_match_shows_pre_match_analytics_sections(): void
    {
        $this->seedHistory();
        $match = $this->makeMatch(['kickoff_at' => now()->addDay()]);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        foreach (self::PRE_MATCH_HEADERS as $header) {
            $response->assertSee($header);
        }
        foreach (self::PRE_MATCH_VIEW_KEYS as $key) {
            $response->assertViewHas($key);
        }
    }

    // Step 1C.1 — scheduled: Statistiche match/Eventi/Formazioni REALI non
    // renderizzati, anche quando i dati esistono già (es. lineup pubblicata
    // in anticipo) — appartengono semanticamente alla partita giocata.
    public function test_scheduled_match_hides_real_match_data_blocks_even_when_data_exists(): void
    {
        $this->seedHistory();
        $match = $this->makeMatch(['kickoff_at' => now()->addDay()]);

        $ds = DataSource::create(['slug' => 'api-football', 'name' => 'API-Football', 'source_type' => 'api']);

        MatchStatistic::create([
            'match_id' => $match->id, 'data_source_id' => $ds->id, 'fetched_at' => now(),
            'home_shots' => 3,
        ]);
        MatchEvent::create([
            'match_id' => $match->id, 'data_source_id' => $ds->id, 'event_type' => 'goal',
            'minute' => 5, 'team_id' => $match->home_team_id, 'player_name' => 'Early Scorer',
            'source_event_key' => 'test-event-scheduled',
        ]);
        $lineup = MatchLineup::create([
            'match_id' => $match->id, 'team_id' => $match->home_team_id, 'data_source_id' => $ds->id,
            'formation' => '4-4-2',
        ]);
        MatchLineupPlayer::create([
            'match_lineup_id' => $lineup->id, 'player_external_id' => 'test-player-scheduled',
            'player_name' => 'Pre-announced Starter', 'shirt_number' => 7, 'is_starter' => true,
        ]);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $response->assertDontSee('Statistiche match');
        $response->assertDontSee('Eventi partita');
        $response->assertDontSee('Formazioni');
        $response->assertDontSee('Early Scorer');
        $response->assertDontSee('Pre-announced Starter');
    }

    // Step 1C — scheduled: blocchi PORTALE (Confronto pre-match/Forma/Split/H2H)
    // compaiono PRIMA del separatore "Dati analisi / verifica", i blocchi
    // tecnici (Elo/Structural/Qualità avversari/...) DOPO. Nessun dato perso.
    public function test_scheduled_match_separates_portale_from_analisi_section(): void
    {
        $this->seedHistory();
        $match = $this->makeMatch(['kickoff_at' => now()->addDay()]);

        $response = $this->get(route('matches.show', $match));
        $response->assertOk();
        $html = $response->getContent();

        $response->assertSee('Dati analisi / verifica');

        $posConfronto = strpos($html, 'Confronto pre-match');
        $posSplit     = strpos($html, 'Split casa');
        $posH2H       = strpos($html, 'Precedenti');
        $posAnalisi   = strpos($html, 'Dati analisi / verifica');
        $posElo       = strpos($html, 'Forza dinamica Elo');
        $posOpponentQ = strpos($html, 'Qualità avversari recenti');

        $this->assertNotFalse($posConfronto);
        $this->assertNotFalse($posSplit);
        $this->assertNotFalse($posAnalisi);
        $this->assertNotFalse($posElo);

        // Portale blocks all precede the Analisi/Verifica separator.
        $this->assertLessThan($posAnalisi, $posConfronto);
        $this->assertLessThan($posAnalisi, $posSplit);
        $this->assertLessThan($posAnalisi, $posH2H);
        // Technical blocks all follow it.
        $this->assertGreaterThan($posAnalisi, $posElo);
        $this->assertGreaterThan($posAnalisi, $posOpponentQ);
    }

    // 2. finished: risultato/statistiche reali/eventi/formazioni presenti;
    //    prediction assente; blocchi pre-match non renderizzati.
    // 5. eventi/lineup/statistiche reali continuano a funzionare sul finished.
    public function test_finished_match_shows_real_data_and_hides_pre_match_blocks(): void
    {
        $this->seedHistory();
        $match = $this->makeMatch([
            'status'        => 'finished',
            'kickoff_at'    => now()->subDay(),
            'home_score_ft' => 2,
            'away_score_ft' => 1,
            'home_score_ht' => 1,
            'away_score_ht' => 0,
        ]);

        $ds = DataSource::create(['slug' => 'api-football', 'name' => 'API-Football', 'source_type' => 'api']);

        MatchStatistic::create([
            'match_id'       => $match->id,
            'data_source_id' => $ds->id,
            'fetched_at'     => now(),
            'home_shots'     => 12,
            'away_shots'     => 7,
            'home_corners'   => 5,
            'away_corners'   => 2,
        ]);

        MatchEvent::create([
            'match_id'         => $match->id,
            'data_source_id'   => $ds->id,
            'event_type'       => 'goal',
            'minute'           => 23,
            'team_id'          => $match->home_team_id,
            'player_name'      => 'Test Scorer',
            'source_event_key' => 'test-event-1',
        ]);

        $homeLineup = MatchLineup::create([
            'match_id'       => $match->id,
            'team_id'        => $match->home_team_id,
            'data_source_id' => $ds->id,
            'formation'      => '4-3-3',
        ]);
        MatchLineupPlayer::create([
            'match_lineup_id'     => $homeLineup->id,
            'player_external_id'  => 'test-player-1',
            'player_name'         => 'Test Starter',
            'shirt_number'        => 10,
            'is_starter'          => true,
        ]);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        // Real post-match content.
        $response->assertSee('Terminata');
        $response->assertSee('2'); // FT score
        $response->assertSee('12'); // home_shots
        $response->assertSee('Test Scorer');
        $response->assertSee('Test Starter');
        $response->assertSee('4-3-3');

        // No prediction.
        $this->assertNull($response->viewData('publicPrediction'));
        $response->assertDontSee('Pronostico Robetting');

        // No pre-match analytics blocks/view data.
        foreach (self::PRE_MATCH_HEADERS as $header) {
            $response->assertDontSee($header);
        }
        foreach (self::PRE_MATCH_VIEW_KEYS as $key) {
            $response->assertViewMissing($key);
        }

        // Step 1C — no artificial "Portale"/"Dati analisi / verifica"
        // separators either: there is nothing pre-match to separate.
        $response->assertDontSee('Dati analisi / verifica');
    }

    // 3. finished: i calculator/pre-match query pesanti non vengono eseguiti —
    //    verifica quantitativa via query log, non solo assenza in view.
    public function test_finished_match_does_not_run_pre_match_queries(): void
    {
        $this->seedHistory(5);

        $scheduled = $this->makeMatch(['kickoff_at' => now()->addDay()]);
        $finished  = $this->makeMatch([
            'status'        => 'finished',
            'kickoff_at'    => now()->subDay(),
            'home_score_ft' => 1,
            'away_score_ft' => 0,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('matches.show', $scheduled))->assertOk();
        $scheduledQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->get(route('matches.show', $finished))->assertOk();
        $finishedQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The pre-match pipeline (13 calculators + 2 historical scans) makes
        // the scheduled request run many more queries than the finished one,
        // which only fetches result/statistics/events/lineups.
        $this->assertLessThan($scheduledQueryCount, $finishedQueryCount);
        $this->assertLessThan(15, $finishedQueryCount);
    }

    // 4. other (awarded/abandoned): nessun errore, nessuna prediction, nessun
    //    analytics pre-match pesante — fallback conservativo, non per-status.
    public function test_other_statuses_render_safely_without_prediction_or_heavy_analytics(): void
    {
        foreach (['awarded', 'abandoned'] as $status) {
            $match = $this->makeMatch([
                'status'        => $status,
                'kickoff_at'    => now()->subDay(),
                'home_score_ft' => 3,
                'away_score_ft' => 0,
            ]);

            $response = $this->get(route('matches.show', $match));

            $response->assertOk();
            $this->assertNull($response->viewData('publicPrediction'));
            $response->assertDontSee('Pronostico Robetting');
            $response->assertDontSee('Dati analisi / verifica');
            foreach (self::PRE_MATCH_VIEW_KEYS as $key) {
                $response->assertViewMissing($key);
            }
        }
    }
}
