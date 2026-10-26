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

    // Odds V1 — PRE-MATCH 1X2 (Match Winner) prospective collection, append-only.
    // Scope = core_leagues + the 3 UEFA club competitions (additional_competitions).
    //   match_winner_bet_id   : API-Football bet id of "Match Winner" (1 per the provider's
    //                           /odds/bets reference; verify with GET /odds/bets?search=Match Winner).
    //                           A bet is also accepted when its name equals match_winner_bet_name.
    //   throttle_minutes      : min interval between successful syncs of the same competition
    //                           (pre-match odds refresh ~every 3h at the provider).
    //   error_retry_minutes   : min interval after a failed attempt.
    //   horizon_days          : only competitions with a scheduled fixture within this window are queried.
    //   max_pages             : hard cap on /odds pages per (league, season) per run.
    'odds' => [
        'enabled'              => (bool) env('API_FOOTBALL_ODDS_ENABLED', true),
        'match_winner_bet_id'  => (int) env('API_FOOTBALL_ODDS_MATCH_WINNER_BET_ID', 1),
        'match_winner_bet_name' => 'Match Winner',
        'throttle_minutes'     => (int) env('API_FOOTBALL_ODDS_THROTTLE_MINUTES', 180),
        'error_retry_minutes'  => (int) env('API_FOOTBALL_ODDS_ERROR_RETRY_MINUTES', 60),
        'horizon_days'         => (int) env('API_FOOTBALL_ODDS_HORIZON_DAYS', 14),
        'max_pages'            => (int) env('API_FOOTBALL_ODDS_MAX_PAGES', 20),
    ],

    // Canonical slug for each core league ID. Used by the league importer to
    // map API-Football's numeric ID to the project's canonical competition slug.
    // core_leagues = competitions Robetting predicts on. Never mix with
    // additional_competitions below, even though both feed the same importer.
    'core_leagues' => [
        135 => 'serie-a',
        39  => 'premier-league',
        140 => 'la-liga',
        78  => 'bundesliga',
        61  => 'ligue-1',
    ],

    // Non-core competitions Robetting still imports — Team History / Elo
    // Network data only, never prediction-eligible (see CORE_COMPETITION_IDS
    // in OfficialPredictionCaptureService, which is unaffected by this list).
    // The 3 UEFA club competitions are imported in FULL (Elo Network, see
    // SyncAdditionalCompetition). The 6 national cups are imported
    // SELECTIVELY — fixtures only where a core team is involved (see
    // SyncSelectiveCompetition, Step B1) — both share this same config
    // bucket because both are equally "never core_leagues".
    'additional_competitions' => [
        2   => 'uefa-champions-league',
        3   => 'uefa-europa-league',
        848 => 'uefa-conference-league',
        137 => 'coppa-italia',
        45  => 'fa-cup',
        48  => 'league-cup',
        143 => 'copa-del-rey',
        81  => 'dfb-pokal',
        66  => 'coupe-de-france',
    ],
];
