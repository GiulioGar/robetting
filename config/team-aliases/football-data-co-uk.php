<?php

// Maps football-data.co.uk CSV team names (exact, case-sensitive)
// to the canonical teams.name in the database.
// Only teams whose CSV name differs from the canonical name need an entry.
// Teams with no alias are matched via exact teams.name lookup.

return [
    'Atalanta'   => 'Atalanta BC',
    'Bologna'    => 'Bologna FC 1909',
    'Cagliari'   => 'Cagliari Calcio',
    'Como'       => 'Como 1907',
    'Cremonese'  => 'US Cremonese',
    'Fiorentina' => 'ACF Fiorentina',
    'Genoa'      => 'Genoa CFC',
    'Inter'      => 'FC Internazionale Milano',
    'Juventus'   => 'Juventus FC',
    'Lazio'      => 'SS Lazio',
    'Lecce'      => 'US Lecce',
    'Milan'      => 'AC Milan',
    'Napoli'     => 'SSC Napoli',
    'Parma'      => 'Parma Calcio 1913',
    'Pisa'       => 'AC Pisa 1909',
    'Roma'       => 'AS Roma',
    'Sassuolo'   => 'US Sassuolo Calcio',
    'Torino'     => 'Torino FC',
    'Udinese'    => 'Udinese Calcio',
    'Verona'     => 'Hellas Verona FC',

    // Premier League (England)
    'Arsenal'        => 'Arsenal FC',
    'Aston Villa'    => 'Aston Villa FC',
    'Bournemouth'    => 'AFC Bournemouth',
    'Brentford'      => 'Brentford FC',
    'Brighton'       => 'Brighton & Hove Albion FC',
    'Chelsea'        => 'Chelsea FC',
    'Crystal Palace' => 'Crystal Palace FC',
    'Everton'        => 'Everton FC',
    'Fulham'         => 'Fulham FC',
    'Leeds'          => 'Leeds United FC',
    'Liverpool'      => 'Liverpool FC',
    'Man City'       => 'Manchester City FC',
    'Man United'     => 'Manchester United FC',
    'Newcastle'      => 'Newcastle United FC',
    "Nott'm Forest"  => 'Nottingham Forest FC',
    'Sunderland'     => 'Sunderland AFC',
    'Tottenham'      => 'Tottenham Hotspur FC',
    'Burnley'        => 'Burnley FC',
    'West Ham'       => 'West Ham United FC',
    'Wolves'         => 'Wolverhampton Wanderers FC',
];
