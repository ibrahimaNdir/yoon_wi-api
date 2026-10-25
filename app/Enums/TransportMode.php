<?php

namespace App\Enums;

enum TransportMode: string
{
    case RAIL = 'rail';
    case BRT = 'brt';
    case BUS = 'bus';
    case MINIBUS = 'minibus';
}
