<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Imports\HistoricalSeasonImportService;
use Illuminate\Http\RedirectResponse;

/**
 * Triggers a real HistoricalSeasonImportService run for one season from the
 * Upload Manager UI.
 *
 * Deliberately thin: this is the only place the UI is allowed to call the
 * orchestrator, and it calls it exactly the way the `robetting:import-season`
 * command does (service → service, no CLI shell-out). No FDO/FDCUK logic and
 * no season-format validation lives here — HistoricalSeasonImportService owns
 * all of that already.
 */
class FootballDataCoUkSeasonImportController extends Controller
{
    public function __invoke(string $season, HistoricalSeasonImportService $service): RedirectResponse
    {
        // Synchronous local import: 5 leagues x FDO dry-run+real can exceed the
        // default 30s max_execution_time, especially if FDO rate-limits (429 ->
        // sleep(Retry-After)). Raised only for this one request, not php.ini —
        // temporary until this moves to a queued job.
        set_time_limit(900);

        try {
            $report = $service->import($season, false);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['import' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return back()->withErrors(['import' => "Errore inatteso durante l'import: " . $e->getMessage()]);
        }

        session()->flash('import_report', $report);

        return redirect()->route('admin.imports.football-data-co-uk.index', ['season' => $season]);
    }
}
