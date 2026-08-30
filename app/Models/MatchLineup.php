<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MatchLineup extends Model
{
    protected $fillable = [
        'match_id',
        'team_id',
        'data_source_id',
        'formation',
        'coach_external_id',
        'coach_name',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(MatchLineupPlayer::class);
    }
}
