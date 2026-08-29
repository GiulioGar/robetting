<?php

namespace App\Services\Matches;

readonly class MatchFieldPolicy
{
    public function __construct(
        public string $kickoff = 'fill_only',           // 'fill_only' | 'overwrite'
        public string $status  = 'fill_if_unknown',     // 'fill_if_unknown' | 'overwrite'
        public bool   $kickoffSkipMidnightOverwrite = false,
        // When true: if FDO returns 00:00:00 UTC (date-only placeholder, time TBD)
        // and the canonical DB already has a confirmed non-midnight time, the kickoff
        // update is skipped. Prevents replacing confirmed kickoffs with FDO's
        // "not-yet-scheduled" sentinel, which is normal for matches 6+ weeks out.
        // Once FDO confirms the real time, the next sync will apply it correctly.
        public bool   $noScores = false,
        // When true: all score fields (HT, FT, ET, penalties) are skipped entirely
        // in applyFieldUpdates — neither filled nor overwritten. Use for jobs that
        // handle calendar/fixture structure only (kickoff, status, rescheduling).
        // Score responsibility stays with the live sync and catch-up reconciliation.
    ) {}

}
