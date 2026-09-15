<?php

return [
    'api_key'  => env('API_FOOTBALL_KEY'),
    'base_url' => env('API_FOOTBALL_BASE_URL', 'https://v3.football.api-sports.io'),

    // Late Enrichment Refresh — retry policy for v2 rows missing advanced statistics.
    // API-Football adds expected_goals / goals_prevented retroactively.
    //   initial_delay_days : minimum wait after first fetch before enrichment is attempted,
    //                        to avoid a double-fetch immediately after a normal sync.
    //   retry_days         : minimum interval between subsequent enrichment re-checks.
    //   max_age_days       : stop enriching matches older than this (API backfill window).
    'late_stats_initial_delay_days' => (int) env('API_FOOTBALL_LATE_STATS_INITIAL_DELAY_DAYS', 2),
    'late_stats_retry_days'         => (int) env('API_FOOTBALL_LATE_STATS_RETRY_DAYS', 7),
    'late_stats_max_age_days'       => (int) env('API_FOOTBALL_LATE_STATS_MAX_AGE_DAYS', 180),

    // Canonical slug for each core league ID. Used by the league importer to
    // map API-Football's numeric ID to the project's canonical competition slug.
    'core_leagues' => [
        135 => 'serie-a',
        39  => 'premier-league',
        140 => 'la-liga',
        78  => 'bundesliga',
        61  => 'ligue-1',
    ],
];
