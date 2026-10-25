<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteStop extends Model
{
    public $timestamps = false;
        public $incrementing = false;
    protected $primaryKey = null;

    protected $fillable = [
        'route_id', 'direction', 'seq', 'stop_id',
        'seconds_from_prev', 'seconds_from_prev_peak', 'source_id',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'source_id');
    }
}
