<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitionSeasonZone extends Model
{
    protected $fillable = [
        'season_id',
        'from_position',
        'to_position',
        'type',
        'label',
        'css_class',
        'color',
        'status',
        'sort_order',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }
}
