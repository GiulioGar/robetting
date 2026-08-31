<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchPlayerStatistic extends Model
{
    protected $fillable = [
        'match_id',
        'player_id',
        'team_id',
        'data_source_id',
        // utilization
        'games_minutes',
        'games_number',
        'games_position',
        'games_rating',
        'games_captain',
        'games_substitute',
        // shots
        'shots_total',
        'shots_on_target',
        // goals
        'goals_scored',
        'goals_conceded',
        'goals_assists',
        'goals_saves',
        // passes
        'passes_total',
        'passes_key',
        'passes_accuracy',
        // tackles
        'tackles_total',
        'tackles_blocks',
        'tackles_interceptions',
        // duels
        'duels_total',
        'duels_won',
        // dribbling
        'dribbles_attempts',
        'dribbles_success',
        'dribbles_past',
        // fouls
        'fouls_drawn',
        'fouls_committed',
        // discipline
        'cards_yellow',
        'cards_red',
        // penalties
        'penalty_won',
        'penalty_committed',
        'penalty_scored',
        'penalty_missed',
        'penalty_saved',
        // raw
        'raw_stats',
    ];

    protected function casts(): array
    {
        return [
            'games_rating'     => 'float',
            'passes_accuracy'  => 'float',
            'games_captain'    => 'boolean',
            'games_substitute' => 'boolean',
            'raw_stats'        => 'array',
        ];
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
