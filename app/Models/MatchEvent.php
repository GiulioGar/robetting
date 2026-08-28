<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchEvent extends Model
{
    protected $fillable = [
        'match_id',
        'data_source_id',
        'event_type',
        'minute',
        'minute_label',
        'team_id',
        'player_external_id',
        'player_name',
        'related_player_external_id',
        'related_player_name',
        'detail',
        'source_event_key',
    ];

    protected $casts = [
        'detail' => 'array',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
