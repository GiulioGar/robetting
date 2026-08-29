<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataSyncRun extends Model
{
    protected $fillable = [
        'data_source_id',
        'sync_type',
        'competition_id',
        'season_id',
        'mode',
        'started_at',
        'finished_at',
        'status',
        'created_count',
        'updated_count',
        'unchanged_count',
        'skipped_count',
        'warnings_count',
        'api_calls',
        'daily_remaining',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
            'details'     => 'array',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }
}
