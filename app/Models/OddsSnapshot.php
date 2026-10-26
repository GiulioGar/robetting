<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One append-only PRE-MATCH 1X2 observation (see the odds_snapshots migration).
 * Rows are only ever inserted; updating an existing snapshot is rejected.
 */
class OddsSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'match_id',
        'data_source_id',
        'bookmaker_id',
        'bookmaker_name',
        'bet_id',
        'bet_name',
        'home_odd',
        'draw_odd',
        'away_odd',
        'provider_updated_at',
        'fetched_at',
        'kickoff_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'home_odd'            => 'float',
            'draw_odd'            => 'float',
            'away_odd'            => 'float',
            'provider_updated_at' => 'datetime',
            'fetched_at'          => 'datetime',
            'kickoff_at'          => 'datetime',
            'raw_data'            => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('odds_snapshots is append-only: existing snapshots are never updated.');
        });
    }

    /** Observations certified as acquired strictly before the kickoff known at fetch time. */
    public function scopeCertifiedPreMatch($query)
    {
        return $query->whereColumn('fetched_at', '<', 'kickoff_at');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }
}
