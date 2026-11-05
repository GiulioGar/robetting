<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One comparable selection of a PRE-MATCH GOAL evaluation (see the goal_market_evaluations migration).
 * Append-only: rows are only ever inserted.
 */
class GoalMarketEvaluation extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'line'                     => 'float',
            'bookmakers_json'          => 'array',
            'kickoff_at'               => 'datetime',
            'prediction_generated_at'  => 'datetime',
            'quotes_latest_fetched_at' => 'datetime',
            'quotes_oldest_fetched_at' => 'datetime',
            'evaluation_at'            => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('goal_market_evaluations is append-only: existing evaluations are never updated.');
        });
        static::deleting(function (): void {
            throw new LogicException('goal_market_evaluations is append-only: evaluations are never deleted.');
        });
    }
}
