<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prediction extends Model
{
    protected $fillable = [
        'match_id',
        'model_key',
        'model_version',
        'feature_set_version',
        'artifact_sha256',
        'generated_at',
        'kickoff_at',
        'lambda_home',
        'lambda_away',
        'lambda3',
        'probability_home',
        'probability_draw',
        'probability_away',
        'features_json',
        'structural_snapshot_version',
        'latent_snapshot_generated_at',
        'home_goals',
        'away_goals',
        'outcome',
        'match_status_at_result',
        'result_recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at'                  => 'datetime',
            'kickoff_at'                    => 'datetime',
            'latent_snapshot_generated_at'  => 'datetime',
            'result_recorded_at'            => 'datetime',
            'lambda_home'                   => 'float',
            'lambda_away'                   => 'float',
            'lambda3'                       => 'float',
            'probability_home'              => 'float',
            'probability_draw'              => 'float',
            'probability_away'              => 'float',
            'features_json'                 => 'array',
            'home_goals'                    => 'integer',
            'away_goals'                    => 'integer',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }
}
