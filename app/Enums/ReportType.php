<?php

namespace App\Enums;

enum ReportType: string
{
    case WrongPrice = 'wrong_price';
    case LineNotExisting = 'line_not_existing';
    case StopMoved = 'stop_moved';
    case MissingCoverage = 'missing_coverage';
    case Other = 'other';
}
