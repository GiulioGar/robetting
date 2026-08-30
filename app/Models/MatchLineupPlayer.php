<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchLineupPlayer extends Model
{
    protected $fillable = [
        'match_lineup_id',
        'player_external_id',
        'player_name',
        'shirt_number',
        'position',
        'grid',
        'is_starter',
    ];

    protected $casts = [
        'is_starter' => 'boolean',
    ];

    public function lineup(): BelongsTo
    {
        return $this->belongsTo(MatchLineup::class, 'match_lineup_id');
    }
}
