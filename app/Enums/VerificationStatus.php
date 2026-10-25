<?php

namespace App\Enums;

enum VerificationStatus: string
{
    case ToVerify = 'to_verify';
    case Verified = 'verified';
}
