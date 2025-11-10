<?php

namespace App\Enums;

enum AccuracyLevelEnum: string
{
    case ROOFTOP = 'ROOFTOP';
    case RANGE_INTERPOLATED = 'RANGE_INTERPOLATED';
    case GEOMETRIC_CENTER = 'GEOMETRIC_CENTER';
    case APPROXIMATE = 'APPROXIMATE';
    case UNKNOWN = 'UNKNOWN';
}
