<?php

namespace App\Services\Matches;

readonly class MatchFieldPolicy
{
    public function __construct(
        public string $kickoff = 'fill_only',       // 'fill_only' | 'overwrite'
        public string $status  = 'fill_if_unknown', // 'fill_if_unknown' | 'overwrite'
    ) {}

    public static function forFdo(): self
    {
        return new self(kickoff: 'overwrite', status: 'overwrite');
    }

    public static function forFdcuk(): self
    {
        return new self();
    }
}
