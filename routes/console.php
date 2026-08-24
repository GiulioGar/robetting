<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Live/recent sync — polls football-data.org only for leagues with a match
// currently in progress (based on kickoff_at already stored) — no-ops
// otherwise, so the 5-minute cadence never actually calls the API when
// nothing is playing. This is the immediate-recovery path.
Schedule::command('robetting:sync-live-scores')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Catch-up safety net — daily reconciliation for the 5 core leagues'
// current season: finds matches whose kickoff has passed but that were
// never finalized in DB (see LiveScoreSyncService::catchUp(), bounded to
// imports.catch_up_max_days = 7). Covers the gap the live-sync's narrow
// window can leave behind (e.g. scheduler not yet installed, or a whole
// run missed) — not a historical backfill, older seasons stay on FDCUK CSV.
// 04:02 (not 04:00) so it never coincides with a live-sync tick, avoiding
// unnecessary overlap without a custom lock. Timezone matches
// config('app.timezone') (UTC) — the same clock the live-sync already runs
// on, nothing new introduced.
Schedule::command('robetting:sync-live-scores', ['--catch-up'])
    ->dailyAt('04:02')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();
