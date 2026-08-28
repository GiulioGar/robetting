<?php

namespace App\Services\DataSources\GoalApi;

/**
 * Parses GOAL API fixture event arrays (goals/cards/substitutions) into
 * MatchEvent-compatible row arrays.
 *
 * Caller passes the raw sub-arrays from getFixture()['data'] separately so
 * the parser is free of HTTP / DB concerns and fully testable in isolation.
 */
class GoalApiEventParser
{
    /**
     * @param  array  $goals          $fixtureData['events'] — GOAL type
     * @param  array  $cards          $fixtureData['cards']
     * @param  array  $substitutions  $fixtureData['substitutions']
     * @param  int    $homeTeamId     Canonical teams.id for home team
     * @param  int    $awayTeamId     Canonical teams.id for away team
     * @return array  Flat list of event arrays ready for MatchEvent::updateOrCreate
     */
    public function parse(
        array $goals,
        array $cards,
        array $substitutions,
        int $homeTeamId,
        int $awayTeamId,
    ): array {
        $events = [];

        foreach ($goals as $raw) {
            $event = $this->parseGoal($raw, $homeTeamId, $awayTeamId);
            if ($event) {
                $events[] = $event;
            }
        }

        foreach ($cards as $raw) {
            $event = $this->parseCard($raw, $homeTeamId, $awayTeamId);
            if ($event) {
                $events[] = $event;
            }
        }

        foreach ($substitutions as $raw) {
            $event = $this->parseSub($raw, $homeTeamId, $awayTeamId);
            if ($event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function parseGoal(array $raw, int $homeTeamId, int $awayTeamId): ?array
    {
        $id = $raw['id'] ?? null;
        if (!$id) {
            return null;
        }

        $isHome = isset($raw['homeScorer']) && $raw['homeScorer'] !== null;

        $teamId          = $isHome ? $homeTeamId : $awayTeamId;
        $playerName      = $isHome ? ($raw['homeScorer'] ?? null) : ($raw['awayScorer'] ?? null);
        $playerExtId     = $isHome ? ($raw['homeScorerId'] ?? null) : ($raw['awayScorerId'] ?? null);
        $assistName      = $isHome ? ($raw['homeAssist'] ?? null) : ($raw['awayAssist'] ?? null);
        $assistExtId     = $isHome ? ($raw['homeAssistId'] ?? null) : ($raw['awayAssistId'] ?? null);

        [$minute, $minuteLabel] = $this->parseTime((string) ($raw['time'] ?? ''));

        return [
            'event_type'                  => 'goal',
            'minute'                      => $minute,
            'minute_label'                => $minuteLabel,
            'team_id'                     => $teamId,
            'player_external_id'          => $playerExtId ? (string) $playerExtId : null,
            'player_name'                 => $playerName ?: null,
            'related_player_external_id'  => $assistExtId ? (string) $assistExtId : null,
            'related_player_name'         => $assistName ?: null,
            'detail'                      => array_filter([
                'score' => $raw['score'] ?? null,
                'info'  => $raw['info'] ?? null,
            ]),
            'source_event_key'            => (string) $id,
        ];
    }

    private function parseCard(array $raw, int $homeTeamId, int $awayTeamId): ?array
    {
        $id = $raw['id'] ?? null;
        if (!$id) {
            return null;
        }

        $isHome = isset($raw['homeFault']) && $raw['homeFault'] !== null;

        $teamId      = $isHome ? $homeTeamId : $awayTeamId;
        $playerName  = $isHome ? ($raw['homeFault'] ?? null) : ($raw['awayFault'] ?? null);
        $playerExtId = $isHome ? ($raw['homePlayerId'] ?? null) : ($raw['awayPlayerId'] ?? null);

        [$minute, $minuteLabel] = $this->parseTime((string) ($raw['time'] ?? ''));

        $eventType = match (strtolower(trim($raw['card'] ?? ''))) {
            'yellow card'     => 'yellow_card',
            'red card'        => 'red_card',
            'yellow-red card' => 'yellow_red_card',
            default           => 'card',
        };

        return [
            'event_type'                  => $eventType,
            'minute'                      => $minute,
            'minute_label'                => $minuteLabel,
            'team_id'                     => $teamId,
            'player_external_id'          => $playerExtId ? (string) $playerExtId : null,
            'player_name'                 => $playerName ?: null,
            'related_player_external_id'  => null,
            'related_player_name'         => null,
            'detail'                      => ['card' => $raw['card'] ?? null],
            'source_event_key'            => (string) $id,
        ];
    }

    private function parseSub(array $raw, int $homeTeamId, int $awayTeamId): ?array
    {
        $id = $raw['id'] ?? null;
        if (!$id) {
            return null;
        }

        $teamId = ($raw['team'] ?? '') === 'home' ? $homeTeamId : $awayTeamId;

        // "T. Baldanzi | Junior Messias" and "3673830821 | 3661972414"
        $names = array_map('trim', explode('|', $raw['substitution'] ?? '', 2));
        $ids   = array_map('trim', explode('|', $raw['substitutionPlayerId'] ?? '', 2));

        $playerOut   = $names[0] ?? null;
        $playerIn    = $names[1] ?? null;
        $playerOutId = $ids[0] ?? null;
        $playerInId  = $ids[1] ?? null;

        [$minute, $minuteLabel] = $this->parseTime((string) ($raw['time'] ?? ''));

        return [
            'event_type'                  => 'substitution',
            'minute'                      => $minute,
            'minute_label'                => $minuteLabel,
            'team_id'                     => $teamId,
            'player_external_id'          => $playerOutId ?: null,
            'player_name'                 => $playerOut ?: null,
            'related_player_external_id'  => $playerInId ?: null,
            'related_player_name'         => $playerIn ?: null,
            'detail'                      => null,
            'source_event_key'            => (string) $id,
        ];
    }

    /**
     * Parses "82", "45+3" etc. into [minute, minuteLabel].
     * Returns [null, null] if $time is empty.
     */
    private function parseTime(string $time): array
    {
        if ($time === '') {
            return [null, null];
        }

        if (str_contains($time, '+')) {
            [$base] = explode('+', $time, 2);
            return [(int) $base, $time];
        }

        return [(int) $time, $time];
    }
}
