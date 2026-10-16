<?php

declare(strict_types=1);

namespace App\Tests\Geo;

use App\Entity\AdministrativeUnit;
use App\Entity\BoundarySet;
use App\Geo\AdministrativeLevel;
use App\Geo\BoundaryAccuracyStatus;
use App\Tests\Support\Database;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** G.1b (ADR-0012 p.2, p.6, p.7): modelul geografiei administrative și constrângerile lui în PostgreSQL. */
final class AdministrativeGeographySchemaTest extends KernelTestCase
{
    private const CHECKSUM = 'b978117c278583a8688f3fea9cfe9a42c9eee42fbd115a0a2ddeda3ae2956330';

    private Connection $db;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        Database::reset($this->db, 'administrative_boundary', 'boundary_set', 'administrative_unit');
    }

    /** @return array{AdministrativeUnit, AdministrativeUnit, AdministrativeUnit} țara, județul 403, sectorul 1 */
    private function bucharest(): array
    {
        $ro = AdministrativeUnit::country('România', ['ancpi_natcode' => '1']);
        $b = AdministrativeUnit::county($ro, 403, 40, 'MUNICIPIUL BUCUREŞTI', 1, 'SIRUTA_s1_2026');
        // decizie 16.10.2026: sectorul direct sub 403; SIRSUP original (179132) păstrat ca proveniență
        $s1 = AdministrativeUnit::uat($b, 179141, 6, 'BUCUREŞTI SECTORUL 1', 179132, 'SIRUTA_s1_2026');
        foreach ([$ro, $b, $s1] as $u) {
            $this->em->persist($u);
        }
        $this->em->flush();

        return [$ro, $b, $s1];
    }

    private function assertRejected(string $sql, array $params = []): void
    {
        try {
            $this->db->executeStatement($sql, $params);
        } catch (DBALException) {
            self::assertTrue(true);

            return;
        }
        self::fail("Baza de date trebuia să respingă: $sql");
    }

    public function testCountryHasOwnCodeAndNoSirutaAndSectorsSitUnderTheCounty(): void
    {
        [$ro, $b, $s1] = $this->bucharest();

        self::assertSame(['RO', null, null], [$ro->getCode(), $ro->getSiruta(), $ro->getParent()]);
        self::assertSame(['ancpi_natcode' => '1'], $ro->getExternalIds());
        self::assertSame('RO.403.179141', $s1->getPath());
        self::assertSame($b, $s1->getParent());
        self::assertSame(179132, $s1->getSirutaParentCode());
        self::assertSame('BUCUREŞTI SECTORUL 1', $s1->getOfficialName());
        self::assertSame('BUCUREȘTI SECTORUL 1', $s1->getDisplayName());

        $this->em->clear();
        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM administrative_unit WHERE path <@ 'RO.403'"));
        self::assertSame('{"ancpi_natcode": "1"}', $this->db->fetchOne("SELECT external_ids::text FROM administrative_unit WHERE code = 'RO'"));
    }

    public function testEntityGuardsTheHierarchyAndTheSirutaContract(): void
    {
        $ro = AdministrativeUnit::country('România');
        $cases = [
            'UAT direct sub țară' => fn () => AdministrativeUnit::uat($ro, 54975, 1, 'CLUJ-NAPOCA', 127, null),
            'județ sub județ' => fn () => AdministrativeUnit::county(AdministrativeUnit::county($ro, 127, 40, 'CLUJ', 1, null), 10, 40, 'ALBA', 1, null),
            'SIRUTA zero' => fn () => AdministrativeUnit::county($ro, 0, 40, 'X', 1, null),
            'denumire goală' => fn () => AdministrativeUnit::county($ro, 127, 40, '  ', 1, null),
        ];
        foreach ($cases as $label => $make) {
            try {
                $make();
                self::fail("Trebuia respins: $label");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(AdministrativeLevel::COUNTRY, $ro->getLevel());
    }

    public function testDatabaseRejectsUnitsOutsideTheContract(): void
    {
        [$ro, $b] = $this->bucharest();
        $insert = 'INSERT INTO administrative_unit (id, code, siruta, level, siruta_type, path, official_name, display_name, external_ids, created_at, updated_at, parent_id)
                   VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, \'X\', \'X\', ?::jsonb, now(), now(), ?)';
        $country = $ro->getId()->toRfc4122();
        $county = $b->getId()->toRfc4122();

        $this->assertRejected($insert, ['MD', 1, 'COUNTRY', null, 'MD', '{}', null]);          // țară cu SIRUTA
        $this->assertRejected($insert, ['127', 127, 'COUNTY', 40, 'RO.127', '{}', null]);      // județ fără părinte
        $this->assertRejected($insert, ['128', 127, 'COUNTY', 40, 'RO.128', '{}', $country]);  // cod diferit de SIRUTA
        $this->assertRejected($insert, ['127', 127, 'COUNTY', 40, 'RO.127', '[]', $country]);  // external_ids nu e obiect
        $this->assertRejected($insert, ['403', 403, 'COUNTY', 40, 'RO.403', '{}', $country]);  // SIRUTA/cod duplicat
        $this->assertRejected($insert, ['54975', 54975, 'CITY', 1, 'RO.403.54975', '{}', $county]); // nivel necunoscut
        $this->assertRejected('DELETE FROM administrative_unit WHERE code = \'403\'');         // părinte referit (RESTRICT)
    }

    private function boundarySet(string $checksum = self::CHECKSUM): BoundarySet
    {
        return new BoundarySet('ANCPI — AU Unități administrative', 'https://example.test/layer', 'CC-BY-4.0', '20250324', $checksum,
            'PROJCS["Romania_double_stereo",…]', 3844, '+proj=sterea … +towgs84=2.329,-147.042,-92.08,0.309,-0.325,-0.497,5.69');
    }

    public function testBoundarySetActivatesOnlyAfterAcceptedAccuracyAndOnlyOneIsActive(): void
    {
        $set = $this->boundarySet();
        self::assertSame(BoundaryAccuracyStatus::UNVERIFIED, $set->getAccuracyStatus());
        try {
            $set->activate();
            self::fail('Un set neverificat nu se activează.');
        } catch (\LogicException) {
        }
        try {
            $set->recordAccuracy(BoundaryAccuracyStatus::ACCEPTED, null, null, 'fără valori');
            self::fail('Acceptarea cere precizia și D.');
        } catch (\InvalidArgumentException) {
        }
        $set->recordAccuracy(BoundaryAccuracyStatus::ACCEPTED, '1.50', '5.00', 'test');
        $set->activate();
        $this->em->persist($set);
        $this->em->flush();
        self::assertTrue($set->isActive());

        $id = $set->getId()->toRfc4122();
        $this->assertRejected("UPDATE boundary_set SET accuracy_status = 'UNVERIFIED' WHERE id = ?", [$id]);       // activ, dar neverificat
        $this->assertRejected('UPDATE boundary_set SET uncertainty_distance_meters = NULL WHERE id = ?', [$id]);  // acceptat fără D
        $this->assertRejected("UPDATE boundary_set SET artifact_checksum = 'abc' WHERE id = ?", [$id]);

        $second = $this->boundarySet(str_repeat('a', 64));
        $second->recordAccuracy(BoundaryAccuracyStatus::ACCEPTED, '1.00', '3.00', 'test');
        $this->em->persist($second);
        $this->em->flush();
        $this->assertRejected('UPDATE boundary_set SET activated_at = now() WHERE id = ?', [$second->getId()->toRfc4122()]); // al doilea activ

        $this->assertRejected('INSERT INTO boundary_set SELECT gen_random_uuid(), source, source_url, license, data_version, artifact_checksum, declared_crs, source_srid,
            transformation, \'UNVERIFIED\', NULL, NULL, NULL, NULL, 0, now(), NULL, NULL FROM boundary_set WHERE id = ?', [$id]); // același artefact
    }

    public function testBoundaryKeepsSourceGeometryAndRejectsInvalidOrMismatchedOnes(): void
    {
        [, , $s1] = $this->bucharest();
        $set = $this->boundarySet();
        $this->em->persist($set);
        $this->em->flush();
        $insert = 'INSERT INTO administrative_boundary (boundary_set_id, administrative_unit_id, source_srid, geom_source, geom)
                   VALUES (?, ?, ?, ST_GeomFromText(?, ?), ST_Transform(ST_GeomFromText(?, ?), 4326))';
        $params = fn (string $wkt, int $srid = 3844, int $declared = 3844) => [$set->getId()->toRfc4122(), $s1->getId()->toRfc4122(), $declared, $wkt, $srid, $wkt, $srid];
        $square = 'MULTIPOLYGON(((580000 325000, 581000 325000, 581000 326000, 580000 326000, 580000 325000)))';
        $bowtie = 'MULTIPOLYGON(((580000 325000, 581000 326000, 581000 325000, 580000 326000, 580000 325000)))';

        $this->assertRejected($insert, $params($bowtie));             // geometrie invalidă în geom
        $this->assertRejected($insert, $params($square, 4326, 3844)); // SRID diferit de cel declarat

        $this->db->executeStatement($insert, $params($square));
        $row = $this->db->fetchAssociative('SELECT ST_SRID(geom_source) AS s, ST_SRID(geom) AS w, repaired, ST_Y(ST_Centroid(geom)) BETWEEN 43 AND 49 AS in_ro FROM administrative_boundary');
        self::assertSame([3844, 4326, false, true], [$row['s'], $row['w'], $row['repaired'], $row['in_ro']]);

        $this->assertRejected('DELETE FROM boundary_set WHERE id = ?', [$set->getId()->toRfc4122()]); // referit (RESTRICT)
    }
}
