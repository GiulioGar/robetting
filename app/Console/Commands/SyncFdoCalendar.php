<?php

namespace App\Console\Commands;

use App\Services\Imports\FdoCalendarSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncFdoCalendar extends Command
{
    protected $signature = 'robetting:sync-fdo-calendar
                            {--dry-run : Simulate without persisting any changes}';

    protected $description = 'Daily FDO calendar sync: kickoffs, statuses, and missing-season bootstrap for the 5 core leagues';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[DRY RUN] No changes will be persisted.');
            DB::beginTransaction();
        }

        try {
            $report = (new FdoCalendarSyncService())->sync($this->output);
        } catch (\Throwable $e) {
            if ($dryRun) {
                DB::rollBack();
            }
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if ($dryRun) {
            DB::rollBack();
            $this->warn('[DRY RUN] All changes rolled back.');
        }

        $this->newLine();

        foreach ($report['leagues'] as $league) {
            $slug = $league['slug'];

            if (!empty($league['error'])) {
                $this->error("[{$slug}] ERROR: {$league['error']}");
                continue;
            }

            $action = $league['action'];
            $season = $league['season'] ?? '?';

            $this->line("[{$slug}] action={$action} season={$season}");

            if ($result = $league['result'] ?? null) {
                $m = $result['matches'] ?? [];
                $this->line(sprintf(
                    '  matches: created=%d linked=%d updated=%d skipped=%d',
                    $m['created'] ?? 0,
                    $m['linked']  ?? 0,
                    $m['updated'] ?? 0,
                    $m['skipped'] ?? 0,
                ));
            }

            if (($league['kickoff_changes'] ?? 0) > 0) {
                $this->warn("  kickoff changes: {$league['kickoff_changes']}");
                foreach ($league['kickoff_log'] ?? [] as $ch) {
                    $this->line("    match_id={$ch['match_id']}  {$ch['old']} → {$ch['new']}");
                }
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
