<?php

namespace App\Models;

use App\Enums\FareKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fare extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'route_id', 'kind', 'from_stop_id', 'to_stop_id',
        'min_stops', 'max_stops', 'amount_fcfa',
        'source_id', 'verified_at', 'valid_from', 'valid_to',
    ];

    protected $casts = [
        'kind' => FareKind::class,
        'verified_at' => 'date',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function fromStop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'from_stop_id');
    }

    public function toStop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'to_stop_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'source_id');
    }
}
