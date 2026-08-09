<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchStatistic extends Model
{
    protected $fillable = [
        'match_id',
        'data_source_id',
        'home_shots',
        'away_shots',
        'home_shots_on_target',
        'away_shots_on_target',
        'home_fouls',
        'away_fouls',
        'home_corners',
        'away_corners',
        'home_yellow_cards',
        'away_yellow_cards',
        'home_red_cards',
        'away_red_cards',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
