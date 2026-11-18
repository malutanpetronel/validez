<?php

declare(strict_types=1);

namespace App\Subject;

use App\Geo\AdministrativeLevel;

/**
 * ADR-0012 p.4–5 (G.3): aria declarată a subiectului. NULL pe subiect = „arie nespecificată” (subiectele vechi).
 * - LOCAL: UAT-uri declarate (oricâte județe) ∪ UAT-ul pinului încadrat; cel puțin un UAT în aria efectivă.
 * - COUNTY: unul sau mai multe județe declarate.
 * - NATIONAL: fără unități; aria efectivă e țara.
 */
enum SubjectScope: string
{
    case LOCAL = 'LOCAL';
    case COUNTY = 'COUNTY';
    case NATIONAL = 'NATIONAL';

    /** Nivelul unităților declarate permise; null = niciuna. */
    public function declaredLevel(): ?AdministrativeLevel
    {
        return match ($this) {
            self::LOCAL => AdministrativeLevel::UAT,
            self::COUNTY => AdministrativeLevel::COUNTY,
            self::NATIONAL => null,
        };
    }
}
