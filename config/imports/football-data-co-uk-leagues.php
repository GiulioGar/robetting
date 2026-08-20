<?php

// Core-league mapping used by HistoricalSeasonImportService to orchestrate
// FDO + FDCUK imports for a season across the 5 core leagues. Keyed by the
// football-data.co.uk division code, since that's what identifies both the
// CSV file inside storage/app/imports/football-data-co-uk/{season}/ and the
// value of the CSV's own "Div" column.
//
// Not auto-loaded as a Laravel config namespace (this lives in a
// subdirectory of config/) — same convention as
// config/team-aliases/football-data-co-uk.php: load it with
// `require config_path('imports/football-data-co-uk-leagues.php')`.
return [
    'E0' => [
        'slug'       => 'premier-league',
        'fdo_code'   => 'PL',
        'fdcuk_file' => 'E0.csv',
    ],
    'I1' => [
        'slug'       => 'serie-a',
        'fdo_code'   => 'SA',
        'fdcuk_file' => 'I1.csv',
    ],
    'SP1' => [
        'slug'       => 'la-liga',
        'fdo_code'   => 'PD',
        'fdcuk_file' => 'SP1.csv',
    ],
    'D1' => [
        'slug'       => 'bundesliga',
        'fdo_code'   => 'BL1',
        'fdcuk_file' => 'D1.csv',
    ],
    'F1' => [
        'slug'       => 'ligue-1',
        'fdo_code'   => 'FL1',
        'fdcuk_file' => 'F1.csv',
    ],
];
