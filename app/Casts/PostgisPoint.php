<?php

namespace App\Casts;

use Clickbar\Magellan\Cast\GeometryCast;
use Clickbar\Magellan\Data\Geometries\Point;

class PostgisPoint extends GeometryCast
{
    public function __construct()
    {
        parent::__construct(Point::class);
    }
}
