<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Services\Analytics\TeamStructuralRatingCalculator;
use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\MatchPredictionService;
use App\Services\Prediction\OfficialPredictionRecorder;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class PredictionEngineAdminController extends Controller
{
    private const CORE_COMPETITION_IDS = [15, 16, 17, 18, 19];

    public function __construct(
        private readonly MatchPredictionService  $predictionService,
        private readonly CandidateModelService   $candidateService,
    ) {
        abort_if(! app()->isLocal() && ! app()->runningUnitTests(), 404);
    }

    public function index(Request $request): View
    {
        $competitionId = $request->integer('competition_id') ?: null;
        $showFinished  = $request->boolean('finished', false);
        $matchId       = $request->integer('match_id') ?: null;

        // Match list: upcoming (or recent if requested)
        $matchQuery = FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name', 'competition:id,name'])
            ->whereIn('competition_id', self::CORE_COMPETITION_IDS)
            ->whereNotNull('kickoff_at');

        if ($competitionId) {
            $matchQuery->where('competition_id', $competitionId);
        }

        if ($showFinished) {
            $matchQuery->where('status', 'finished')
                ->orderByDesc('kickoff_at')
                ->limit(60);
        } else {
            $matchQuery->whereNotIn('status', ['finished', 'awarded', 'walkover'])
                ->orderBy('kickoff_at')
                ->limit(60);
        }

        $matches = $matchQuery->get();

        // Prediction for selected match
        $prediction             = null;
        $comparison             = null;
        $matchContext           = null;
        $error                  = null;
        $lastOfficialPrediction = null;

        if ($matchId) {
            $lastOfficialPrediction = Prediction::where('match_id', $matchId)
                ->where('model_key', 'candidate47_structural_log')
                ->orderByDesc('generated_at')
                ->first();
        }

        if ($matchId) {
            $match = FootballMatch::with([
                'homeTeam:id,name',
                'awayTeam:id,name',
                'competition:id,name',
                'season',
            ])->find($matchId);

            if (! $match) {
                $error = "Match #{$matchId} non trovato.";
            } else {
                try {
                    $prediction = $this->predictionService->predictWithDebug($match);
                    $prediction['match'] = $match;

                    try {
                        $comparison = $this->candidateService->compare(
                            $prediction['features'],
                            (int) $match->home_team_id,
                            (int) $match->away_team_id,
                            (string) $match->kickoff_at,
                        );
                    } catch (Throwable) {
                        // Candidate artifacts not available — comparison stays null.
                    }

                    try {
                        $matchContext = $this->buildMatchContext($match, $prediction['features']);
                    } catch (Throwable) {
                        // Diagnostic context failed — non-critical, skip silently.
                    }
                } catch (Throwable $e) {
                    $error = "Errore prediction match #{$matchId}: " . $e->getMessage();
                }
            }
        }

        return view('admin.prediction-engine.index', [
            'matches'                => $matches,
            'selectedId'             => $matchId,
            'competitionId'          => $competitionId,
            'showFinished'           => $showFinished,
            'prediction'             => $prediction,
            'comparison'             => $comparison,
            'matchContext'           => $matchContext,
            'error'                  => $error,
            'lastOfficialPrediction' => $lastOfficialPrediction,
        ]);
    }

    /**
     * Explicit, intentional action: (re)generate ROBETTING CANDIDATE V2 LOG
     * (candidate47_structural_log) for this match and persist it as an
     * official, immutable snapshot.
     *
     * predictWithDebug() is used ONLY to obtain the extracted 59-feature
     * vector (same pattern as PublicMatchPredictionService) — its own FULL59
     * lambda/probability output is never saved here; every number persisted
     * (lambda, probabilities, model/feature-set version, the 47-feature
     * vector, both snapshot timestamps) comes from
     * CandidateModelService::officialPredictionData(), the exact same
     * runtime pipeline the admin comparison page and the public match page
     * already use for Candidate V2 LOG — no duplicated math.
     *
     * Never triggered by index()/compare() — only by this POST action.
     */
    public function saveOfficial(FootballMatch $match): RedirectResponse
    {
        $redirectBack = redirect()
            ->route('admin.prediction-engine.index', ['match_id' => $match->id]);

        if ($match->kickoff_at === null || Carbon::parse($match->kickoff_at)->isPast()) {
            return $redirectBack->with(
                'official_prediction_error',
                "Match #{$match->id}: kickoff_at non è nel futuro — salvataggio rifiutato."
            );
        }

        try {
            $base = $this->predictionService->predictWithDebug($match);

            $official = $this->candidateService->officialPredictionData(
                $base['features'],
                (int) $match->home_team_id,
                (int) $match->away_team_id,
                (string) $match->kickoff_at,
            );

            if ($official === null) {
                return $redirectBack->with(
                    'official_prediction_error',
                    'Candidate V2 LOG non disponibile per questo match (Structural/Latent snapshot mancante o squadra non presente) — salvataggio rifiutato.'
                );
            }

            OfficialPredictionRecorder::record([
                'match_id'             => $match->id,
                'model_key'            => $official['model_key'],
                'model_version'        => $official['model_version'],
                'feature_set_version'  => $official['feature_set_version'],
                'artifact_path'        => $official['artifact_path'],
                'generated_at'         => now(),
                'kickoff_at'           => $match->kickoff_at,
                'lambda_home'          => $official['lambda_home'],
                'lambda_away'          => $official['lambda_away'],
                'lambda3'              => $official['lambda3'],
                'probability_home'     => $official['probability_home'],
                'probability_draw'     => $official['probability_draw'],
                'probability_away'     => $official['probability_away'],
                'features_json'        => $official['features_json'],
                'structural_snapshot_version'  => $official['structural_snapshot_generated_at'],
                'latent_snapshot_generated_at' => $official['latent_snapshot_generated_at'],
            ]);
        } catch (InvalidArgumentException $e) {
            return $redirectBack->with('official_prediction_error', 'Prediction non valida: ' . $e->getMessage());
        } catch (Throwable $e) {
            return $redirectBack->with('official_prediction_error', 'Errore salvataggio: ' . $e->getMessage());
        }

        return $redirectBack->with('official_prediction_saved', true);
    }

    /**
     * Build diagnostic context for the match: Elo, structural strength, market value,
     * and number of RECENT matches considered (last-10 window, same comp+season).
     *
     * Reference date for structural lookup: match kickoff_at (consistent with prediction).
     *
     * @param  array<string, float|null>  $features59
     */
    private function buildMatchContext(FootballMatch $match, array $features59): array
    {
        $tmDsId = DataSource::where('slug', 'transfermarkt')->value('id');

        $homeStruct = $tmDsId
            ? TeamStructuralRatingCalculator::calculateForTeamAtDate(
                (int) $match->home_team_id,
                Carbon::parse($match->kickoff_at),
                (int) $tmDsId
            )
            : null;

        $awayStruct = $tmDsId
            ? TeamStructuralRatingCalculator::calculateForTeamAtDate(
                (int) $match->away_team_id,
                Carbon::parse($match->kickoff_at),
                (int) $tmDsId
            )
            : null;

        $prevBase = FootballMatch::where('competition_id', $match->competition_id)
            ->where('season_id', $match->season_id)
            ->where('status', 'finished')
            ->whereNotNull('home_score_ft')
            ->whereNotNull('away_score_ft')
            ->where('kickoff_at', '<', $match->kickoff_at);

        $homeRecentN = min(10, (clone $prevBase)
            ->where(fn ($q) => $q
                ->where('home_team_id', $match->home_team_id)
                ->orWhere('away_team_id', $match->home_team_id))
            ->count());

        $awayRecentN = min(10, (clone $prevBase)
            ->where(fn ($q) => $q
                ->where('home_team_id', $match->away_team_id)
                ->orWhere('away_team_id', $match->away_team_id))
            ->count());

        return [
            'home_elo'          => $features59['core_elo_home_pre_match_elo'] ?? null,
            'away_elo'          => $features59['core_elo_away_pre_match_elo'] ?? null,
            'home_structural'   => $homeStruct ? round((float) $homeStruct['structural_rating'], 1) : null,
            'home_market_value' => $homeStruct ? (int) $homeStruct['market_value'] : null,
            'away_structural'   => $awayStruct ? round((float) $awayStruct['structural_rating'], 1) : null,
            'away_market_value' => $awayStruct ? (int) $awayStruct['market_value'] : null,
            'home_recent_n'     => $homeRecentN,
            'away_recent_n'     => $awayRecentN,
        ];
    }
}
