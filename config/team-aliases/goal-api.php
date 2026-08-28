<?php

// Maps GOAL API homeTeamName/awayTeamName (exact, case-sensitive)
// to the canonical teams.name in the database.
// Only teams whose GOAL API name differs from the canonical name need an entry.
// Teams with no alias are matched via exact teams.name lookup.
//
// Verified against GET /leagues/{id}/fixtures responses for Serie A 2026/27.
// "AC Milan" and "AS Roma" are exact matches — no alias needed.

return [

    // Serie A (2026/27 and older seasons in the same league)
    'Atalanta'   => 'Atalanta BC',
    'Cremonese'  => 'US Cremonese',
    'Pisa'       => 'AC Pisa 1909',
    'Verona'     => 'Hellas Verona FC',
    'Bologna'    => 'Bologna FC 1909',
    'Cagliari'   => 'Cagliari Calcio',
    'Como'       => 'Como 1907',
    'Fiorentina' => 'ACF Fiorentina',
    'Frosinone'  => 'Frosinone Calcio',
    'Genoa'      => 'Genoa CFC',
    'Inter'      => 'FC Internazionale Milano',
    'Juventus'   => 'Juventus FC',
    'Lazio'      => 'SS Lazio',
    'Lecce'      => 'US Lecce',
    'Monza'      => 'AC Monza',
    'Napoli'     => 'SSC Napoli',
    'Parma'      => 'Parma Calcio 1913',
    'Sassuolo'   => 'US Sassuolo Calcio',
    'Torino'     => 'Torino FC',
    'Udinese'    => 'Udinese Calcio',
    'Venezia'    => 'Venezia FC',

];
