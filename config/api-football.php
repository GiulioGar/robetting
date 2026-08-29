<?php

return [
    'api_key'  => env('API_FOOTBALL_KEY'),
    'base_url' => env('API_FOOTBALL_BASE_URL', 'https://v3.football.api-sports.io'),

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
