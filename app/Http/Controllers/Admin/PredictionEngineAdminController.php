<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Services\Analytics\TeamStructuralRatingCalculator;
use App\Services\Prediction\CandidateModelService;
use App\Services\Prediction\MatchPredictionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
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
        $prediction   = null;
        $comparison   = null;
        $matchContext = null;
        $error        = null;

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
            'matches'       => $matches,
            'selectedId'    => $matchId,
            'competitionId' => $competitionId,
            'showFinished'  => $showFinished,
            'prediction'    => $prediction,
            'comparison'    => $comparison,
            'matchContext'  => $matchContext,
            'error'         => $error,
        ]);
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
