<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    protected $fillable = [
        'name',
        'firstname',
        'lastname',
        'birth_date',
        'nationality',
        'height_cm',
        'weight_kg',
        'position',
        'photo_url',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }

    public function externalIds(): HasMany
    {
        return $this->hasMany(PlayerExternalId::class);
    }

    public function seasonPlayers(): HasMany
    {
        return $this->hasMany(SeasonPlayer::class);
    }
}
