<?php

namespace App\Services\DataSources\Highlightly;

/**
 * Parses a Highlightly /statistics/{matchId} response into match_statistics columns.
 *
 * Additional metrics available in HL but not yet stored (extend schema when needed):
 *   xG, xA, possession, offsides, goalkeeper saves, passes (total/successful/failed),
 *   tackles, interceptions, clearances, aerial duels, dribbles, crosses, big chances.
 */
class HighlightlyStatsParser
{
    /**
     * @param  array  $apiResponse  Raw JSON from GET /statistics/{matchId}: [homeTeamObj, awayTeamObj]
     * @return array|null  Keyed by match_statistics column names, or null if response is invalid.
     */
    public function parse(array $apiResponse): ?array
    {
        if (count($apiResponse) < 2 || !isset($apiResponse[0], $apiResponse[1])) {
            return null;
        }

        $home = $apiResponse[0];
        $away = $apiResponse[1];

        $hSoT     = $this->stat($home, 'Shots on target');
        $hSoFF    = $this->stat($home, 'Shots off target');
        $hBlocked = $this->stat($home, 'Blocked shots');

        $aSoT     = $this->stat($away, 'Shots on target');
        $aSoFF    = $this->stat($away, 'Shots off target');
        $aBlocked = $this->stat($away, 'Blocked shots');

        // Compute total only when all three components are present.
        $hTotal = ($hSoT !== null && $hSoFF !== null && $hBlocked !== null)
            ? $hSoT + $hSoFF + $hBlocked
            : null;

        $aTotal = ($aSoT !== null && $aSoFF !== null && $aBlocked !== null)
            ? $aSoT + $aSoFF + $aBlocked
            : null;

        return [
            'home_shots'            => $hTotal,
            'away_shots'            => $aTotal,
            'home_shots_on_target'  => $hSoT,
            'away_shots_on_target'  => $aSoT,
            'home_fouls'            => $this->stat($home, 'Fouls'),
            'away_fouls'            => $this->stat($away, 'Fouls'),
            'home_corners'          => $this->stat($home, 'Corners'),
            'away_corners'          => $this->stat($away, 'Corners'),
            'home_yellow_cards'     => $this->stat($home, 'Yellow cards'),
            'away_yellow_cards'     => $this->stat($away, 'Yellow cards'),
            'home_red_cards'        => $this->stat($home, 'Red cards'),
            'away_red_cards'        => $this->stat($away, 'Red cards'),
        ];
    }

    private function stat(array $teamData, string $displayName): ?int
    {
        foreach (($teamData['statistics'] ?? []) as $s) {
            if (($s['displayName'] ?? '') === $displayName) {
                $v = $s['value'] ?? null;
                return $v !== null ? (int) $v : null;
            }
        }

        return null;
    }
}
