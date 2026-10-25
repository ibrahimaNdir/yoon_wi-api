<?php

namespace App\Models;

use App\Enums\TransportMode;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'agency_id', 'mode', 'short_name', 'long_name',
        'headway_peak_min', 'headway_offpeak_min',
        'valid_from', 'valid_to', 'verification', 'verified_at',
    ];

    protected $casts = [
        'mode' => TransportMode::class,
        'verification' => VerificationStatus::class,
        'valid_from' => 'date',
        'valid_to' => 'date',
        'verified_at' => 'date',
    ];

    // Une ligne appartient à une agence
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    // Une ligne a plusieurs arrêts (via la table route_stops)
    public function stops(): BelongsToMany
    {
        return $this->belongsToMany(Stop::class, 'route_stops')
            ->withPivot('direction', 'seq', 'seconds_from_prev', 'seconds_from_prev_peak', 'source_id')
            ->orderBy('seq'); // On trie par ordre de passage
    }

    // Pour avoir les détails de la table pivot directement
    public function routeStops(): HasMany
    {
        return $this->hasMany(RouteStop::class);
    }

    // Une ligne a plusieurs tarifs
    public function fares(): HasMany
    {
        return $this->hasMany(Fare::class);
    }
}
