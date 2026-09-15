<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchStatistic extends Model
{
    protected $fillable = [
        'match_id',
        'data_source_id',
        'fetched_at',
        // shots
        'home_shots',
        'away_shots',
        'home_shots_on_target',
        'away_shots_on_target',
        'home_shots_off_target',
        'away_shots_off_target',
        'home_blocked_shots',
        'away_blocked_shots',
        'home_shots_insidebox',
        'away_shots_insidebox',
        'home_shots_outsidebox',
        'away_shots_outsidebox',
        // discipline
        'home_fouls',
        'away_fouls',
        'home_yellow_cards',
        'away_yellow_cards',
        'home_red_cards',
        'away_red_cards',
        // set pieces
        'home_corners',
        'away_corners',
        'home_offsides',
        'away_offsides',
        // possession / saves
        'home_possession',
        'away_possession',
        'home_goalkeeper_saves',
        'away_goalkeeper_saves',
        // passes
        'home_passes_total',
        'away_passes_total',
        'home_passes_accurate',
        'away_passes_accurate',
        'home_passes_percentage',
        'away_passes_percentage',
        // raw payload
        'raw_stats',
        // xG
        'home_expected_goals',
        'away_expected_goals',
        'home_goals_prevented',
        'away_goals_prevented',
        // schema version
        'stats_schema_version',
        // late enrichment throttle
        'advanced_stats_checked_at',
    ];

    protected $casts = [
        'fetched_at'                 => 'datetime',
        'advanced_stats_checked_at'  => 'datetime',
        'home_possession'        => 'float',
        'away_possession'        => 'float',
        'home_passes_percentage' => 'float',
        'away_passes_percentage' => 'float',
        'raw_stats'              => 'array',
        'home_expected_goals'    => 'float',
        'away_expected_goals'    => 'float',
        'home_goals_prevented'   => 'float',
        'away_goals_prevented'   => 'float',
        'stats_schema_version'   => 'integer',
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
