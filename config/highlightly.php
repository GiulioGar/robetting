<?php

return [

    'base_url' => 'https://soccer.highlightly.net',

    /*
    |--------------------------------------------------------------------------
    | Safety limit (requests/day)
    |--------------------------------------------------------------------------
    | The paid plan has 100 req/day. We reserve 25 as a buffer for manual
    | lookups and unexpected retries. Commands must stop before exceeding this.
    */
    'daily_safety_limit' => 75,

    /*
    |--------------------------------------------------------------------------
    | Highlightly league IDs for the 5 core competitions
    |--------------------------------------------------------------------------
    | Verified from GET /leagues on 2026-08-28. Keys are canonical competition
    | slugs; values are Highlightly's numeric league IDs.
    */
    'league_ids' => [
        'serie-a'        => 115669,
        'premier-league' => 33973,
        'la-liga'        => 119924,
        'bundesliga'     => 67162,
        'ligue-1'        => 52695,
    ],

];
