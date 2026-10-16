<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * ADR-0012 p.6: starea încadrării administrative a pinului, față de setul de limite activ.
 *
 * - RESOLVED: un singur UAT conține punctul și niciun alt UAT nu e la mai puțin de D de el.
 * - AMBIGUOUS: mai multe UAT-uri sunt candidate — punctul e pe frontieră, într-o suprapunere sau în zona de
 *   incertitudine D de lângă frontieră (chiar dacă geometric cade într-un singur poligon).
 * - UNMATCHED: niciun UAT nu conține punctul, dar există UAT-uri la cel mult D (gol în limite).
 * - OUTSIDE: niciun UAT la cel mult D (în afara României, în lipsa unui poligon de țară).
 * - RESOLVED_MANUALLY: un candidat ales de autor sau de un administrator (G.2, rezolvarea manuală).
 */
enum PinClassificationState: string
{
    case RESOLVED = 'RESOLVED';
    case RESOLVED_MANUALLY = 'RESOLVED_MANUALLY';
    case AMBIGUOUS = 'AMBIGUOUS';
    case UNMATCHED = 'UNMATCHED';
    case OUTSIDE = 'OUTSIDE';

    public function isDetermined(): bool
    {
        return $this === self::RESOLVED || $this === self::RESOLVED_MANUALLY;
    }
}
