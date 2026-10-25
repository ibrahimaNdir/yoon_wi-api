<?php

namespace App\Models;

use App\Enums\NetworkStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agency extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'status', 'url'];

    protected $casts = [
        'status' => NetworkStatus::class,
    ];

    // Une agence (ex: DDD) a plusieurs lignes
    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }
}
