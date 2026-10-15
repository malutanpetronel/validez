<?php

declare(strict_types=1);

namespace App\Subject;

/** ADR-0011 p.9: pin pus manual pe hartă sau din locația dispozitivului (cu permisiunea utilizatorului). */
enum LocationSource: string
{
    case MANUAL = 'MANUAL';
    case DEVICE = 'DEVICE';
}
