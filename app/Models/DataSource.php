<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSource extends Model
{
    public $timestamps = false;

    protected $fillable = ['kind', 'description', 'url', 'collected_at'];

    protected $casts = [
        'collected_at' => 'date',
    ];

    public function routeStops(): HasMany
    {
        return $this->hasMany(RouteStop::class, 'source_id');
    }

    public function fares(): HasMany
    {
        return $this->hasMany(Fare::class, 'source_id');
    }
}
