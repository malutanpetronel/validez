<?php

declare(strict_types=1);

namespace App\Tests\Geo;

use App\Entity\AdministrativeUnit;
use App\Entity\BoundarySet;
use App\Entity\CivicSubject;
use App\Entity\TreeNode;
use App\Entity\User;
use App\Geo\BoundaryAccuracyStatus;
use App\Geo\PinClassificationState;
use App\Geo\PinClassifier;
use App\Subject\SubjectLocation;
use App\Subject\SubjectType;
use App\Tests\Support\Database;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * G.2 (ADR-0012 p.6): încadrarea pinului. Trei UAT-uri dreptunghiulare în Stereo70, lângă București:
 * A [580000, 581000] și B [581000, 582000] cu frontieră comună, C [582005, 583000] la 5 m de B (gol), D = 7 m.
 */
final class PinClassifierTest extends KernelTestCase
{
    private Connection $db;
    private EntityManagerInterface $em;
    private BoundarySet $set;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        Database::reset($this->db, 'administrative_boundary', 'boundary_set', 'administrative_unit');

        $ro = AdministrativeUnit::country('România');
        $county = AdministrativeUnit::county($ro, 127, 40, 'JUDEŢUL TEST', 1, 'test');
        $units = ['A' => AdministrativeUnit::uat($county, 54975, 3, 'COMUNA A', 127, 'test'),
            'B' => AdministrativeUnit::uat($county, 55008, 3, 'COMUNA B', 127, 'test'),
            'C' => AdministrativeUnit::uat($county, 55017, 3, 'COMUNA C', 127, 'test')];
        $this->set = new BoundarySet('test', 'https://example.test', 'CC-BY-4.0', 'test', str_repeat('c', 64), 'Stereo70', 3844, 'PostGIS EPSG:3844');
        $this->set->recordAccuracy(BoundaryAccuracyStatus::PROVISIONAL, '6.80', '7.00', 'test');
        $this->set->activate();
        foreach ([$ro, $county, ...array_values($units), $this->set] as $e) {
            $this->em->persist($e);
        }
        $this->em->flush();

        $boxes = ['A' => [580000, 581000], 'B' => [581000, 582000], 'C' => [582005, 583000]];
        foreach ($boxes as $k => [$x1, $x2]) {
            $wkt = "MULTIPOLYGON((($x1 325000, $x1 326000, $x2 326000, $x2 325000, $x1 325000)))";
            $this->db->executeStatement('INSERT INTO administrative_boundary (boundary_set_id, administrative_unit_id, source_srid, geom_source, geom)
                VALUES (?, ?, 3844, ST_GeomFromText(?, 3844), ST_Transform(ST_GeomFromText(?, 3844), 4326))',
                [$this->set->getId()->toRfc4122(), $units[$k]->getId()->toRfc4122(), $wkt, $wkt]);
        }
    }

    /** @return array{0: float, 1: float} lat, lng ale unui punct Stereo70 */
    private function latLng(float $x, float $y): array
    {
        $r = $this->db->fetchNumeric('SELECT ST_Y(p), ST_X(p) FROM (SELECT ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), 3844), 4326) AS p) q', [$x, $y]);

        return [(float) $r[0], (float) $r[1]];
    }

    private function evaluate(float $x, float $y): array
    {
        [$lat, $lng] = $this->latLng($x, $y);
        [$state, $unit, $candidates] = static::getContainer()->get(PinClassifier::class)->evaluate($lat, $lng, $this->set);

        return [$state, array_column($candidates, 'code'), $candidates];
    }

    public function testStatesFollowContainmentAndTheUncertaintyDistance(): void
    {
        self::assertSame([PinClassificationState::RESOLVED, ['54975']], \array_slice($this->evaluate(580500, 325500), 0, 2));

        // la 3 m de frontiera A|B: cade în A, dar B e în zona de incertitudine → AMBIGUOUS, A primul
        [$state, $codes, $candidates] = $this->evaluate(580997, 325500);
        self::assertSame([PinClassificationState::AMBIGUOUS, ['54975', '55008']], [$state, $codes]);
        self::assertTrue($candidates[0]['contains']);
        self::assertEqualsWithDelta(3.0, $candidates[1]['distanceM'], 0.5);

        // exact pe frontieră: ambele îl conțin
        [$state, $codes] = $this->evaluate(581000, 325500);
        self::assertSame(PinClassificationState::AMBIGUOUS, $state);
        self::assertEqualsCanonicalizing(['54975', '55008'], $codes);

        // în golul de 5 m dintre B și C: niciun UAT nu-l conține
        [$state, $codes] = $this->evaluate(582002.5, 325500);
        self::assertSame(PinClassificationState::UNMATCHED, $state);
        self::assertEqualsCanonicalizing(['55008', '55017'], $codes);

        self::assertSame([PinClassificationState::OUTSIDE, []], \array_slice($this->evaluate(590000, 325500), 0, 2));
    }

    private function subject(): CivicSubject
    {
        $user = new User('ion@validez.test', 'Ion');
        $node = new TreeNode('Drumuri', $user, null, 0);
        $subject = new CivicSubject($node, $user, SubjectType::ISSUE, 'Groapă', 'Descriere');
        foreach ([$user, $node, $subject] as $e) {
            $this->em->persist($e);
        }

        return $subject;
    }

    public function testPinChangesRecomputeAndPersistTheClassification(): void
    {
        $location = static::getContainer()->get(SubjectLocation::class);
        $subject = $this->subject();
        [$lat, $lng] = $this->latLng(580500, 325500);
        $location->apply($subject, ['lat' => $lat, 'lng' => $lng]);
        $this->em->flush();
        self::assertSame(PinClassificationState::RESOLVED, $subject->getPinAdminState());
        self::assertSame('54975', $subject->getPinAdminUnit()->getCode());
        $admin = $subject->getLocation()['administrative'];
        self::assertSame(['RESOLVED', 'COMUNA A', 'JUDEȚUL TEST', [], true], [$admin['state'], $admin['unit']['name'], $admin['county']['name'], $admin['candidates'], $admin['provisional']]);

        // același pin retrimis: nu se recalculează
        $computed = $this->db->fetchOne('SELECT pin_admin_computed_at FROM civic_subject');
        $location->apply($subject, ['lat' => $lat, 'lng' => $lng]);
        $this->em->flush();
        self::assertSame($computed, $this->db->fetchOne('SELECT pin_admin_computed_at FROM civic_subject'));

        // mutat lângă frontieră → AMBIGUOUS, fără UAT
        [$lat, $lng] = $this->latLng(581003, 325500);
        $location->apply($subject, ['lat' => $lat, 'lng' => $lng]);
        $this->em->flush();
        self::assertSame(['AMBIGUOUS', null], $this->db->fetchNumeric('SELECT pin_admin_state, pin_admin_unit_id FROM civic_subject'));
        self::assertCount(2, $subject->getLocation()['administrative']['candidates']);

        // pin scos → nicio încadrare
        $location->apply($subject, null);
        $this->em->flush();
        self::assertSame([null, null], $this->db->fetchNumeric('SELECT pin_admin_state, pin_admin_boundary_set_id FROM civic_subject'));
    }

    public function testNoActiveSetMeansNoClassificationAndTheDatabaseGuardsTheStates(): void
    {
        $this->set->deactivate();
        $this->em->flush();
        $subject = $this->subject();
        [$lat, $lng] = $this->latLng(580500, 325500);
        static::getContainer()->get(SubjectLocation::class)->apply($subject, ['lat' => $lat, 'lng' => $lng]);
        $this->em->flush();
        self::assertNull($subject->getPinAdminState());

        $id = $subject->getId()->toRfc4122();
        $set = $this->set->getId()->toRfc4122();
        foreach ([
            "UPDATE civic_subject SET pin_admin_state = 'RESOLVED', pin_admin_boundary_set_id = '$set', pin_admin_computed_at = now(), pin_admin_candidates = '[]' WHERE id = '$id'", // RESOLVED fără UAT
            "UPDATE civic_subject SET pin_admin_state = 'AMBIGUOUS', pin_admin_candidates = '[]' WHERE id = '$id'",                                                                   // fără set
            "UPDATE civic_subject SET pin_admin_state = 'OUTSIDE', pin_admin_boundary_set_id = '$set', pin_admin_computed_at = now(), pin_admin_candidates = '{}' WHERE id = '$id'",  // candidații nu sunt listă
        ] as $sql) {
            try {
                $this->db->executeStatement($sql);
                self::fail("Trebuia respins: $sql");
            } catch (\Doctrine\DBAL\Exception) {
                self::assertTrue(true);
            }
        }
    }
}
