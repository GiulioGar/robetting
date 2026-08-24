<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Imports\LiveScoreSyncService;
use Illuminate\Http\RedirectResponse;

/**
 * Manual "Aggiorna ora" trigger for LiveScoreSyncService — the same sync the
 * scheduled `robetting:sync-live-scores` command runs, exposed here as a
 * stand-in until the OS-level scheduler task is wired up.
 */
class LiveScoreSyncController extends Controller
{
    public function __invoke(LiveScoreSyncService $service): RedirectResponse
    {
        try {
            $report = $service->sync();
        } catch (\Throwable $e) {
            return back()->withErrors(['sync' => "Errore inatteso durante la sincronizzazione: " . $e->getMessage()]);
        }

        session()->flash('sync_report', $report);

        return back();
    }
}
