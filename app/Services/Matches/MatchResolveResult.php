<?php

namespace App\Services\Matches;

use App\Models\FootballMatch;

readonly class MatchResolveResult
{
    public function __construct(
        public string         $action, // 'created' | 'linked' | 'updated' | 'skipped'
        public ?FootballMatch $match,
    ) {}
}
