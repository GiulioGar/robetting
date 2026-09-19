<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FootballMatch;
use App\Services\Prediction\MatchPredictionService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class PredictionEngineAdminController extends Controller
{
    private const CORE_COMPETITION_IDS = [15, 16, 17, 18, 19];

    public function __construct(private readonly MatchPredictionService $predictionService)
    {
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
        $prediction = null;
        $error      = null;

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
            'error'         => $error,
        ]);
    }
}
