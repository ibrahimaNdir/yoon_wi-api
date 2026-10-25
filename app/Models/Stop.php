<?php

namespace App\Models;

use App\Casts\PostgisPoint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stop extends Model
{
    public $timestamps = false;

    // On autorise l'assignation de masse pour ces champs
    protected $fillable = ['name', 'aliases', 'geom', 'is_station', 'verified_at'];

    protected $casts = [
        'geom' => PostgisPoint::class,
        'is_station' => 'boolean',
        'verified_at' => 'date',
    ];

    // Un arrêt est desservi par plusieurs lignes
    public function routes(): BelongsToMany
    {
        return $this->belongsToMany(Route::class, 'route_stops')
            ->withPivot('direction', 'seq', 'seconds_from_prev', 'seconds_from_prev_peak', 'source_id');
    }

    // Pour les correspondances à pied
    public function walkLinksFrom(): HasMany
    {
        return $this->hasMany(WalkLink::class, 'from_stop_id');
    }

    public function walkLinksTo(): HasMany
    {
        return $this->hasMany(WalkLink::class, 'to_stop_id');
    }
}
