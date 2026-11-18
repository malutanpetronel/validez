<?php

declare(strict_types=1);

namespace App\Subject;

/**
 * ADR-0011 p.9: pin pus manual pe hartă sau din locația dispozitivului (cu permisiunea utilizatorului).
 * ADR-0014 p.10: PHOTO — din GPS-ul EXIF al fotografiei, citit în browser și confirmat de autor.
 */
enum LocationSource: string
{
    case MANUAL = 'MANUAL';
    case DEVICE = 'DEVICE';
    case PHOTO = 'PHOTO';
}
