<?php

/**
 * Competition zone configuration.
 *
 * Structure: [competition_slug][season_year_start]['zones'][...]
 *
 * Zones are listed in visual priority order (first = highest).
 * Overlapping ranges are intentional: position 1 is both 'champion' and
 * 'champions_league'. resolveZones() assigns the first matching zone as
 * the primary (row CSS class); the legend lists all distinct zone types.
 *
 * Fields per zone:
 *   from      int    first position (inclusive)
 *   to        int    last position (inclusive)
 *   type      string semantic identifier (champion|champions_league|europa_league|
 *                    conference_league|relegation|promotion|playoff_relegation …)
 *   label     string display label (localised)
 *   css_class string CSS class applied to the standing row
 *   color     string hex color for the row indicator and legend dot
 *
 * Add only seasons where rules are confirmed.
 * Missing slug or year_start → no zones shown, no error.
 */
return [

    'serie-a' => [

        // 2026/27 — confirmed zones only.
        // UEFA slots (UCL/UEL/UECL) depend on coefficient rankings, Coppa Italia
        // outcome and European Performance Spots — add them once officially confirmed.
        2026 => [
            'zones' => [
                [
                    'from'      => 1,
                    'to'        => 1,
                    'type'      => 'champion',
                    'label'     => "Campione d'Italia",
                    'css_class' => 'zone-champion',
                    'color'     => '#f59e0b',
                ],
                [
                    'from'      => 18,
                    'to'        => 20,
                    'type'      => 'relegation',
                    'label'     => 'Retrocessione',
                    'css_class' => 'zone-relegation',
                    'color'     => '#dc2626',
                ],
            ],
        ],

    ],

];
