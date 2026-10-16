<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * ADR-0012 p.2: rezultatul verificării preciziei unui set de limite (cadrul de coordonate și transformarea în WGS84).
 * Se pot activa pentru derivare seturile ACCEPTED (precizie confirmată) și PROVISIONAL (decizie 16.10.2026:
 * precizie neconfirmată oficial, dar cu incertitudinea documentată și acoperită de distanța D). Un set confirmat
 * ulterior intră ca set nou, nu ca o corectură a celui provizoriu.
 */
enum BoundaryAccuracyStatus: string
{
    case UNVERIFIED = 'UNVERIFIED';
    case PROVISIONAL = 'PROVISIONAL';
    case ACCEPTED = 'ACCEPTED';
    case REJECTED = 'REJECTED';

    /** Precizia, D și justificarea sunt obligatorii, iar setul poate fi activat. */
    public function allowsActivation(): bool
    {
        return $this === self::ACCEPTED || $this === self::PROVISIONAL;
    }
}
