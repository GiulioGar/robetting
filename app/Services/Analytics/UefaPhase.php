<?php

namespace App\Services\Analytics;

/**
 * Phase of a UEFA club competition match, derived from the provider `round` string (descriptive use only: never a
 * model input, never changes MatchDataScope classification).
 *
 *   qualification : "1st/2nd/3rd Qualifying Round", "Play-offs" (qualifying play-offs), "Playoff round" (older format)
 *   league_stage  : "League Stage - N"
 *   knockout      : "Knockout Round Play-offs", "Round of 32/16", "Quarter-finals", "Semi-finals", "Final"
 *
 * Unknown / empty rounds return null (never guessed).
 */
final class UefaPhase
{
    public const QUALIFICATION = 'qualification';
    public const LEAGUE_STAGE  = 'league_stage';
    public const KNOCKOUT      = 'knockout';

    public const CLUB_SLUGS = ['uefa-champions-league', 'uefa-europa-league', 'uefa-conference-league'];

    public static function isUefaClubSlug(?string $slug): bool
    {
        return $slug !== null && in_array($slug, self::CLUB_SLUGS, true);
    }

    public static function fromRound(?string $round): ?string
    {
        $r = strtolower(trim((string) $round));
        if ($r === '') {
            return null;
        }

        return match (true) {
            str_starts_with($r, 'knockout round') => self::KNOCKOUT,
            str_contains($r, 'qualifying'), $r === 'play-offs', $r === 'playoff round' => self::QUALIFICATION,
            str_starts_with($r, 'league stage') => self::LEAGUE_STAGE,
            str_starts_with($r, 'round of'), str_contains($r, 'quarter'), str_contains($r, 'semi'), $r === 'final' => self::KNOCKOUT,
            default => null,
        };
    }

    public static function label(?string $phase): string
    {
        return match ($phase) {
            self::QUALIFICATION => 'Qualificazioni / Play-off',
            self::LEAGUE_STAGE  => 'League Stage',
            self::KNOCKOUT      => 'Fase a eliminazione',
            default             => 'Fase n.d.',
        };
    }
}
