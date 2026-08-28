<?php

return [
    'kickoff_mismatch_warning_minutes' => 15,

    // LiveScoreSyncService::sync() — how long after kickoff a match is still
    // polled as "in progress". Kept short and frequent (scheduled every few
    // minutes) since it only fires for matches currently near kickoff.
    'live_sync_hours_after_kickoff'    => 3,

    // LiveScoreSyncService::catchUp() — how far back (from now) to look for
    // matches whose kickoff has passed but are still not finalized in DB
    // (status != finished or FT score missing). Deliberately short: this is
    // a reconciliation safety net for sync gaps, not a historical backfill
    // — older seasons are expected to be finalized via the FDCUK CSV import
    // instead (see HistoricalSeasonImportService).
    'catch_up_max_days'                => 7,

    // Ordered list: first data source slug in this array that has a
    // MatchStatistic row for a given match wins. Extend the array (do not
    // replace it) when a second statistics source is imported.
    // FDCUK stays first: it covers historical seasons and its shot counts
    // match the OPTA methodology used across the rest of the platform.
    // Highlightly covers the current season (2026/27) where FDCUK is absent.
    'statistics_source_priority'       => ['football_data_co_uk', 'highlightly'],
];
