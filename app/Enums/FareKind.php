<?php

namespace App\Enums;

enum FareKind: string
{
    case Fixed = 'fixed';
    case PerSection = 'per_section';
    case Indicative = 'indicative';
}
