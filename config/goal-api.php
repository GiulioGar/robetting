<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GOAL API — Internal League IDs
    |--------------------------------------------------------------------------
    |
    | These are the internal string IDs used as route parameters by the GOAL API
    | (e.g. GET /leagues/{id}/fixtures). They are NOT the numeric `apiId` values
    | returned in the league list — those do not work as route parameters.
    |
    | Each key is a canonical Robetting competition slug.
    | Values are verified from GET /leagues endpoint responses.
    |
    */

    'league_ids' => [
        'serie-a' => 'cmr77dvpd006yrx06zig7907g',
        // Remaining leagues: add after verifying via GET /leagues
        // 'premier-league' => '',
        // 'la-liga'        => '',
        // 'bundesliga'     => '',
        // 'ligue-1'        => '',
    ],

];
