<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerAbsence extends Model
{
    protected $fillable = [
        'match_id',
        'player_id',
        'team_id',
        'data_source_id',
        'absence_type',
        'reason',
        'raw_data',
    ];

    protected function casts(): array
    {
        return ['raw_data' => 'array'];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
