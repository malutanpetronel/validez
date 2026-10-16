<?php

declare(strict_types=1);

namespace App\Geo;

use App\Entity\AdministrativeUnit;
use App\Entity\BoundarySet;
use App\Entity\CivicSubject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * G.2 (ADR-0012 p.6): încadrarea administrativă a pinului față de setul de limite activ.
 *
 * Candidații sunt UAT-urile care conțin punctul (ST_Covers, deci și frontiera) sau sunt la cel mult D metri de el
 * (distanța D a setului; distanță geodezică pe WGS84). Stări:
 *   0 candidați                          → OUTSIDE (în lipsa unui poligon de țară, „la peste D de orice UAT”);
 *   1 candidat care conține punctul      → RESOLVED;
 *   niciun candidat nu conține punctul   → UNMATCHED (gol în limite), cu candidații din jur;
 *   altfel (≥ 2 candidați)               → AMBIGUOUS — frontieră, suprapunere sau zona de incertitudine.
 * Candidații: întâi cei care conțin punctul, apoi după distanță.
 */
final class PinClassifier
{
    /** Grade de longitudine/latitudine per metru, cu marjă: 1° de longitudine are cel puțin ~74 km în România. */
    private const DEGREES_PER_METRE = 1 / 50000;

    private const CANDIDATES = 'WITH p AS (SELECT ST_SetSRID(ST_MakePoint(:lng, :lat), 4326) AS g),
        c AS (SELECT b.administrative_unit_id AS unit, ST_Covers(b.geom, p.g) AS contains,
                     ST_Distance(b.geom::geography, p.g::geography) AS distance
              FROM administrative_boundary b, p
              WHERE b.boundary_set_id = :set AND b.geom && ST_Expand(p.g, :deg))
        SELECT c.unit, c.contains, c.distance, u.code, u.display_name, county.display_name AS county
        FROM c JOIN administrative_unit u ON u.id = c.unit LEFT JOIN administrative_unit county ON county.id = u.parent_id
        WHERE c.contains OR c.distance <= :d
        ORDER BY c.contains DESC, c.distance ASC, u.code';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function activeSet(): ?BoundarySet
    {
        return $this->em->getRepository(BoundarySet::class)->createQueryBuilder('s')
            ->where('s.activatedAt IS NOT NULL AND s.deactivatedAt IS NULL')->getQuery()->getOneOrNullResult();
    }

    /** Recalculează încadrarea pinului; fără pin sau fără set activ, o șterge. Rezolvarea manuală anterioară se pierde. */
    public function classify(CivicSubject $subject): void
    {
        $set = $subject->hasLocation() ? $this->activeSet() : null;
        if ($set === null) {
            $subject->clearPinClassification();

            return;
        }
        ['locationLat' => $lat, 'locationLng' => $lng] = $subject->storedLocation();
        [$state, $unitId, $candidates] = $this->evaluate((float) $lat, (float) $lng, $set);
        $unit = $unitId === null ? null : $this->em->getReference(AdministrativeUnit::class, Ulid::fromString($unitId));
        $subject->applyPinClassification($state, $unit, $candidates, $set);
    }

    /**
     * @return array{0: PinClassificationState, 1: ?string, 2: list<array{code: string, name: string, county: ?string, contains: bool, distanceM: float}>}
     */
    public function evaluate(float $lat, float $lng, BoundarySet $set): array
    {
        $d = (float) ($set->getUncertaintyDistanceMeters() ?? throw new \LogicException('Setul activ nu are distanța D.'));
        $rows = $this->em->getConnection()->fetchAllAssociative(self::CANDIDATES, [
            'lat' => $lat, 'lng' => $lng, 'set' => $set->getId()->toRfc4122(), 'd' => $d, 'deg' => $d * self::DEGREES_PER_METRE,
        ]);
        $candidates = array_map(fn (array $r) => [
            'code' => (string) $r['code'], 'name' => (string) $r['display_name'], 'county' => $r['county'],
            'contains' => (bool) $r['contains'], 'distanceM' => round((float) $r['distance'], 1),
        ], $rows);
        $containing = array_values(array_filter($rows, fn (array $r) => (bool) $r['contains']));

        $state = match (true) {
            $rows === [] => PinClassificationState::OUTSIDE,
            \count($rows) === 1 && $containing !== [] => PinClassificationState::RESOLVED,
            $containing === [] => PinClassificationState::UNMATCHED,
            default => PinClassificationState::AMBIGUOUS,
        };

        return [$state, $state === PinClassificationState::RESOLVED ? (string) $rows[0]['unit'] : null, $candidates];
    }
}
