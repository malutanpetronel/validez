<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * ADR-0012 p.2: rezultatul verificării preciziei unui set de limite (cadrul de coordonate și transformarea în WGS84).
 * Doar un set ACCEPTED poate fi activat pentru derivare.
 */
enum BoundaryAccuracyStatus: string
{
    case UNVERIFIED = 'UNVERIFIED';
    case ACCEPTED = 'ACCEPTED';
    case REJECTED = 'REJECTED';
}
