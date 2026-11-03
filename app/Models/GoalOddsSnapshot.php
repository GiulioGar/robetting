<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One selection of one append-only PRE-MATCH goal-market observation
 * (see the goal_odds_snapshots migration). Rows are only ever inserted.
 */
class GoalOddsSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'match_id',
        'data_source_id',
        'observation_id',
        'bookmaker_id',
        'bookmaker_name',
        'bet_id',
        'bet_name',
        'market',
        'selection',
        'line',
        'provider_label',
        'odd',
        'provider_updated_at',
        'fetched_at',
        'kickoff_at',
    ];

    protected function casts(): array
    {
        return [
            'line'                => 'float',
            'odd'                 => 'float',
            'provider_updated_at' => 'datetime',
            'fetched_at'          => 'datetime',
            'kickoff_at'          => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('goal_odds_snapshots is append-only: existing snapshots are never updated.');
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
