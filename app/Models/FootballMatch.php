<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FootballMatch extends Model
{
    protected $table = 'matches';

    protected $fillable = [
        'competition_id',
        'season_id',
        'home_team_id',
        'away_team_id',
        'kickoff_at',
        'kickoff_timezone',
        'round',
        'matchday',
        'status',
        'venue_name',
        'home_score_ht',
        'away_score_ht',
        'home_score_ft',
        'away_score_ft',
        'home_score_et',
        'away_score_et',
        'home_score_penalties',
        'away_score_penalties',
        'current_home_score',
        'current_away_score',
        'live_minute',
        'live_status',
        'definitive_at',
        'events_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'kickoff_at'        => 'datetime',
            'definitive_at'     => 'datetime',
            'events_fetched_at' => 'datetime',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function statistics(): HasMany
    {
        return $this->hasMany(MatchStatistic::class, 'match_id');
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }
}
