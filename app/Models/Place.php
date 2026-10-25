<?php

namespace App\Models;

use App\Casts\PostgisPoint;
use Illuminate\Database\Eloquent\Model;

class Place extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'aliases', 'category', 'geom'];

    protected $casts = [
        'geom' => PostgisPoint::class,
    ];
}
