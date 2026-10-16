<?php

declare(strict_types=1);

namespace App\Geo;

/** ADR-0012 p.2: nivelurile ierarhiei administrative (țară → județ → UAT; sectoarele Bucureștiului sunt UAT-uri sub 403). */
enum AdministrativeLevel: string
{
    case COUNTRY = 'COUNTRY';
    case COUNTY = 'COUNTY';
    case UAT = 'UAT';

    /** Nivelul părintelui; null pentru țară. */
    public function parentLevel(): ?self
    {
        return match ($this) {
            self::COUNTRY => null,
            self::COUNTY => self::COUNTRY,
            self::UAT => self::COUNTY,
        };
    }
}
