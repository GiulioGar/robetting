<?php

namespace App\Services\DataSources\ApiFootball;

/**
 * Single source of truth for the API-Football short status code -> Robetting
 * canonical status mapping. Previously duplicated identically in
 * ApiFootballFixtureSyncService and ApiFootballResultRefreshService (each
 * carried a comment asking to keep the two in sync by hand); extracted here
 * so a third consumer (ApiFootballDataSyncStatusService) never has to
 * duplicate it a third time, and the two existing services can never drift.
 */
class ApiFootballStatusMapper
{
    public const MAP = [
        'TBD'  => 'tbd',
        'NS'   => 'scheduled',
        '1H'   => 'live',
        'HT'   => 'live',
        '2H'   => 'live',
        'ET'   => 'live',
        'BT'   => 'live',
        'P'    => 'live',
        'LIVE' => 'live',
        'FT'   => 'finished',
        'AET'  => 'finished',
        'PEN'  => 'finished',
        'SUSP' => 'suspended',
        'INT'  => 'interrupted',
        'PST'  => 'postponed',
        'CANC' => 'cancelled',
        'ABD'  => 'abandoned',
        'AWD'  => 'awarded',
        'WO'   => 'walkover',
    ];

    public static function map(string $apiShort): string
    {
        return self::MAP[$apiShort] ?? 'unknown';
    }
}
