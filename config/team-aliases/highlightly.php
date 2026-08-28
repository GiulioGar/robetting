<?php

// Maps Highlightly homeTeam.name / awayTeam.name (exact, case-sensitive)
// to the canonical teams.name in the database.
// Only teams whose Highlightly name differs from the canonical name need an entry.
// Teams whose Highlightly name already matches exactly are resolved via the
// exact-name fallback — no alias needed (e.g. "AC Milan", "Sevilla FC").
//
// Verified entries (★) observed in actual API responses (2026-08-28 audit).
// Inferred entries (◌) follow the pattern: Highlightly uses short names without
// legal suffixes (FC, AFC, SV, etc.) or uses English rather than full local names.
// Inferred entries are safe to include because a mismatch only causes a skip/warn,
// never data corruption.

return [

    // ── Serie A ───────────────────────────────────────────────────────────
    'Atalanta'          => 'Atalanta BC',           // ★
    'Sassuolo'          => 'US Sassuolo Calcio',    // ★
    'Torino'            => 'Torino FC',             // ★
    'Udinese'           => 'Udinese Calcio',        // ★
    'Como'              => 'Como 1907',             // ★
    'Inter'             => 'FC Internazionale Milano', // ★
    'Monza'             => 'AC Monza',              // ★
    'Parma'             => 'Parma Calcio 1913',     // ★
    'Cagliari'          => 'Cagliari Calcio',       // ★
    'Genoa'             => 'Genoa CFC',             // ★
    'Napoli'            => 'SSC Napoli',            // ★
    'Lazio'             => 'SS Lazio',              // ◌
    'Fiorentina'        => 'ACF Fiorentina',        // ◌
    'Juventus'          => 'Juventus FC',           // ◌
    'Bologna'           => 'Bologna FC 1909',       // ◌
    'Lecce'             => 'US Lecce',              // ◌
    'Verona'            => 'Hellas Verona FC',      // ◌
    'Empoli'            => 'Empoli FC',             // ◌
    'Pisa'              => 'AC Pisa 1909',          // ◌
    'Cremonese'         => 'US Cremonese',          // ◌
    'Venezia'           => 'Venezia FC',            // ◌

    // ── Premier League ────────────────────────────────────────────────────
    'Brentford'         => 'Brentford FC',          // ★
    'Tottenham'         => 'Tottenham Hotspur FC',  // ★
    'Hull City'         => 'Hull City AFC',         // ★
    'Manchester United' => 'Manchester United FC',  // ★
    'Arsenal'           => 'Arsenal FC',            // ◌
    'Chelsea'           => 'Chelsea FC',            // ◌
    'Liverpool'         => 'Liverpool FC',          // ◌
    'Manchester City'   => 'Manchester City FC',    // ◌
    'Everton'           => 'Everton FC',            // ◌
    'Newcastle'         => 'Newcastle United FC',   // ◌
    'Fulham'            => 'Fulham FC',             // ◌
    'Brighton'          => 'Brighton & Hove Albion FC', // ◌
    'Crystal Palace'    => 'Crystal Palace FC',     // ◌
    'Aston Villa'       => 'Aston Villa FC',        // ◌
    'Nottm Forest'      => 'Nottingham Forest FC',  // ◌
    'Bournemouth'       => 'AFC Bournemouth',       // ◌
    'Sunderland'        => 'Sunderland AFC',        // ◌
    'Leeds'             => 'Leeds United FC',       // ◌
    'Leeds United'      => 'Leeds United FC',       // ◌
    'Ipswich'           => 'Ipswich Town FC',       // ◌
    'Coventry'          => 'Coventry City FC',      // ◌

    // ── La Liga ───────────────────────────────────────────────────────────
    'Rayo Vallecano'    => 'Rayo Vallecano de Madrid', // ★
    'Real Madrid'       => 'Real Madrid CF',        // ◌
    'Barcelona'         => 'FC Barcelona',          // ◌
    'Atletico Madrid'   => 'Club Atlético de Madrid', // ◌
    'Real Betis'        => 'Real Betis Balompié',   // ◌
    'Real Sociedad'     => 'Real Sociedad de Fútbol', // ◌
    'Villarreal'        => 'Villarreal CF',         // ◌
    'Athletic Club'     => 'Athletic Club',         // ◌ (may already match exactly)
    'Valencia'          => 'Valencia CF',           // ◌
    'Osasuna'           => 'CA Osasuna',            // ◌
    'Getafe'            => 'Getafe CF',             // ◌
    'Celta Vigo'        => 'RC Celta de Vigo',      // ◌
    'Celta'             => 'RC Celta de Vigo',      // ◌
    'Espanyol'          => 'RCD Espanyol de Barcelona', // ◌
    'Alaves'            => 'Deportivo Alavés',      // ◌
    'Deportivo Alaves'  => 'Deportivo Alavés',      // ◌

    // ── Ligue 1 ───────────────────────────────────────────────────────────
    'Toulouse'          => 'Toulouse FC',           // ★
    'Lyon'              => 'Olympique Lyonnais',    // ★
    'PSG'               => 'Paris Saint-Germain FC', // ◌
    'Paris Saint-Germain' => 'Paris Saint-Germain FC', // ◌
    'Marseille'         => 'Olympique de Marseille', // ◌
    'Monaco'            => 'AS Monaco FC',          // ◌
    'Nice'              => 'OGC Nice',              // ◌
    'Lens'              => 'Racing Club de Lens',   // ◌
    'Rennes'            => 'Stade Rennais FC 1901', // ◌
    'Brest'             => 'Stade Brestois 29',     // ◌
    'Lille'             => 'Lille OSC',             // ◌
    'Strasbourg'        => 'RC Strasbourg Alsace',  // ◌
    'Le Havre'          => 'Le Havre AC',           // ◌
    'Angers'            => 'Angers SCO',            // ◌
    'Auxerre'           => 'AJ Auxerre',            // ◌
    'Lorient'           => 'FC Lorient',            // ◌
    'Troyes'            => 'ES Troyes AC',          // ◌

    // ── Bundesliga ────────────────────────────────────────────────────────
    'Bayern Munich'     => 'FC Bayern München',     // ★
    'Dortmund'          => 'Borussia Dortmund',     // ◌
    'Borussia Dortmund' => 'Borussia Dortmund',     // ◌ (may already match)
    'Leverkusen'        => 'Bayer 04 Leverkusen',   // ◌
    'Bayer Leverkusen'  => 'Bayer 04 Leverkusen',   // ◌
    'Leipzig'           => 'RB Leipzig',            // ◌
    'RB Leipzig'        => 'RB Leipzig',            // ◌ (may already match)
    'Frankfurt'         => 'Eintracht Frankfurt',   // ◌
    'Eintracht Frankfurt' => 'Eintracht Frankfurt', // ◌ (may already match)
    'Hoffenheim'        => 'TSG 1899 Hoffenheim',   // ◌
    'Freiburg'          => 'SC Freiburg',           // ◌
    'M\'gladbach'       => 'Borussia Mönchengladbach', // ◌
    'Monchengladbach'   => 'Borussia Mönchengladbach', // ◌
    'Werder Bremen'     => 'SV Werder Bremen',      // ◌
    'Union Berlin'      => '1. FC Union Berlin',    // ◌
    'Mainz'             => '1. FSV Mainz 05',       // ◌
    'Mainz 05'          => '1. FSV Mainz 05',       // ◌
    'Augsburg'          => 'FC Augsburg',           // ◌
    'Cologne'           => '1. FC Köln',            // ◌
    'Hamburg'           => 'Hamburger SV',          // ◌
    'Schalke'           => 'FC Schalke 04',         // ◌
    'Paderborn'         => 'SC Paderborn 07',       // ◌
    'Stuttgart'         => 'VfB Stuttgart',         // ◌ (Highlightly says "VfB Stuttgart" so probably already exact)
    'Elversberg'        => 'SV 07 Elversberg',      // ◌

];
