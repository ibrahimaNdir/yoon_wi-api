<?php

namespace App\Enums;

enum ReportStatus: string
{
    case New = 'new';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
