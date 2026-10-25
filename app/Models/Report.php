<?php

namespace App\Models;

use App\Enums\ReportStatus;
use App\Enums\ReportType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    // La table n'a pas de 'updated_at', seulement un 'created_at'
    const UPDATED_AT = null;

    protected $fillable = [
        'type', 'route_id', 'stop_id', 'reported_value',
        'search_origin', 'search_destination', 'comment',
        'status', 'handled_at',
    ];

    protected $casts = [
        'type' => ReportType::class,
        'status' => ReportStatus::class,
        'handled_at' => 'datetime',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class);
    }
}
