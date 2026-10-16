<?php

declare(strict_types=1);

namespace App\Tests\Geo;

use App\Entity\AdministrativeUnit;
use App\Entity\BoundarySet;
use App\Geo\BoundaryAccuracyStatus;
use App\Geo\BoundaryImporter;
use App\Geo\SirutaImporter;
use App\Tests\Support\Database;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** G.1c-a/b (ADR-0012): importul SIRUTA și al limitelor dintr-un artefact local. */
final class GeoImportTest extends KernelTestCase
{
    private const HEADER = "\u{FEFF}SIRUTA;DENLOC;CODP;JUD;SIRSUP;TIP;NIV;MED;REGIUNE;FSJ;NUTS;LAU";
    private const ROWS = [
        '403;MUNICIPIUL BUCUREŞTI;0;40;1;40;1;0;8;1;RO321;',
        '179132;MUNICIPIUL BUCUREŞTI;0;40;403;9;2;1;8;1;RO321;',
        '179141;BUCUREŞTI SECTORUL 1;0;40;179132;6;3;1;8;1;RO321;RO_179141',
        '179150;BUCUREŞTI SECTORUL 2;0;40;179132;6;3;1;8;1;RO321;RO_179150',
        '127;JUDEŢUL CLUJ;0;12;1;40;1;0;6;1;RO113;',
        '54975;MUNICIPIUL  CLUJ-NAPOCA;0;12;127;1;2;1;6;1;RO113;RO_54975',
        '54984;CLUJ-NAPOCA;400001;12;54975;9;3;1;6;1;RO113;',
    ];

    private Connection $db;
    private EntityManagerInterface $em;
    private string $tmp;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        Database::reset($this->db, 'administrative_boundary', 'boundary_set', 'administrative_unit');
        $this->tmp = sys_get_temp_dir().'/validez-geo-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp.'/*') ?: []);
        rmdir($this->tmp);
        parent::tearDown();
    }

    private function csv(array $rows): string
    {
        $file = $this->tmp.'/siruta.csv';
        file_put_contents($file, implode("\r\n", [self::HEADER, ...$rows])."\r\n");

        return $file;
    }

    private function siruta(): SirutaImporter
    {
        return static::getContainer()->get(SirutaImporter::class);
    }

    private function unit(string $code): ?AdministrativeUnit
    {
        return $this->em->getRepository(AdministrativeUnit::class)->findOneBy(['code' => $code]);
    }

    public function testSirutaImportBuildsTheHierarchyAndIsIdempotent(): void
    {
        $r = $this->siruta()->import($this->csv(self::ROWS), 'SIRUTA_test', false);
        self::assertSame([1, 2, 3, 2, 5], [$r['counts']['country_created'], $r['counts']['counties'], $r['counts']['uats'], $r['counts']['sectors'], $r['counts']['created']]);

        $this->em->clear();
        $sector = $this->unit('179141');
        self::assertSame('RO.403.179141', $sector->getPath());
        self::assertSame(179132, $sector->getSirutaParentCode());
        self::assertNull($this->unit('179132'), 'nivelul intermediar 179132 nu se importă');
        self::assertNull($this->unit('54984'), 'localitățile componente nu se importă');
        $cluj = $this->unit('54975');
        self::assertSame(['MUNICIPIUL  CLUJ-NAPOCA', 'MUNICIPIUL CLUJ-NAPOCA', 'RO.127.54975'], [$cluj->getOfficialName(), $cluj->getDisplayName(), $cluj->getPath()]);
        self::assertSame('JUDEȚUL CLUJ', $this->unit('127')->getDisplayName());
        self::assertNull($this->unit('RO')->getSiruta());

        $again = $this->siruta()->import($this->csv(self::ROWS), 'SIRUTA_test', false);
        self::assertSame([0, 0, 0, 5, 0], [$again['counts']['country_created'], $again['counts']['created'], $again['counts']['renamed'], $again['counts']['unchanged'], $again['counts']['retired']]);
        self::assertSame(6, (int) $this->db->fetchOne('SELECT COUNT(*) FROM administrative_unit'));
    }

    public function testRenameAndRetirementOnANewVersion(): void
    {
        $this->siruta()->import($this->csv(self::ROWS), 'SIRUTA_v1', false);
        $rows = self::ROWS;
        $rows[5] = '54975;MUNICIPIUL CLUJ-NAPOCA;0;12;127;1;2;1;6;1;RO113;RO_54975';
        unset($rows[3]); // sectorul 2 dispare
        $r = $this->siruta()->import($this->csv(array_values($rows)), 'SIRUTA_v2', false);

        self::assertSame([1, 1], [$r['counts']['renamed'], $r['counts']['retired']]);
        $this->em->clear();
        self::assertNotNull($this->unit('179150')->getRetiredAt(), 'rândul rămâne, marcat retras');
        self::assertSame('SIRUTA_v2', $this->unit('54975')->getNomenclatureVersion());
    }

    public function testUnexpectedStructureStopsWithoutWritingAndDryRunWritesNothing(): void
    {
        $rows = self::ROWS;
        $rows[5] = '54975;MUNICIPIUL CLUJ-NAPOCA;0;12;403;1;2;1;6;1;RO113;RO_54975'; // SIRSUP ≠ județul
        try {
            $this->siruta()->import($this->csv($rows), 'SIRUTA_test', false);
            self::fail('SIRSUP diferit de județ trebuia să oprească importul.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('SIRSUP 403', $e->getMessage());
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM administrative_unit'));

        $r = $this->siruta()->import($this->csv(self::ROWS), 'SIRUTA_test', true);
        self::assertSame(5, $r['counts']['created']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM administrative_unit'));
    }

    /** Artefact minimal în formatul tools/geo/export_ancpi_arcgis.py: două UAT-uri, pătrate în Stereo70, lângă București. */
    private function artifact(array $codes): string
    {
        $dir = $this->tmp.'/export';
        @mkdir($dir);
        $features = [];
        foreach ($codes as $i => $code) {
            $x = 580000 + $i * 2000;
            // inel exterior în sens orar (regula Esri)
            $ring = [[$x, 325000], [$x, 326000], [$x + 1000, 326000], [$x + 1000, 325000], [$x, 325000]];
            $features[] = ['attributes' => ['OBJECTID' => $i + 1, 'nationalCode' => $code, 'name_1' => "UAT $code"], 'geometry' => ['rings' => [$ring]]];
        }
        file_put_contents("$dir/page-000.json", json_encode(['features' => $features]));
        file_put_contents("$dir/manifest.json", json_encode(['source' => 'test', 'license' => 'CC-BY-4.0', 'layer_url' => 'https://example.test',
            'feature_count' => \count($features), 'data_version_ids' => ['20250324'], 'problems' => [],
            'spatial_reference' => ['wkt' => 'PROJCS["Romania_double_stereo"]'], 'pages' => [['file' => 'page-000.json']]]));
        file_put_contents("$dir/SHA256SUMS", implode('', array_map(fn ($f) => hash_file('sha256', "$dir/$f")."  $f\n", ['manifest.json', 'page-000.json'])));

        return $dir;
    }

    private function boundaries(): BoundaryImporter
    {
        return static::getContainer()->get(BoundaryImporter::class);
    }

    public function testBoundaryImportStoresSourceAndWgs84GeometryAsAnInactiveUnverifiedSet(): void
    {
        $this->siruta()->import($this->csv(self::ROWS), 'SIRUTA_test', false);
        $dir = $this->artifact(['179141', '179150', '54975']);

        $r = $this->boundaries()->import($dir, 3844, false, false);
        self::assertSame(['importat', 3, 0], [$r['status'], $r['features'], $r['repaired']]);
        $set = $r['set'];
        self::assertSame(BoundaryAccuracyStatus::UNVERIFIED, $set->getAccuracyStatus());
        self::assertFalse($set->isActive());
        self::assertStringContainsString('+towgs84=', $set->getTransformation());

        $row = $this->db->fetchAssociative("SELECT ST_SRID(b.geom_source) AS s, ST_SRID(b.geom) AS w, ST_AsText(b.geom_source) AS src,
            ST_X(ST_Centroid(b.geom)) BETWEEN 25 AND 27 AND ST_Y(ST_Centroid(b.geom)) BETWEEN 44 AND 45 AS bucuresti
            FROM administrative_boundary b JOIN administrative_unit u ON u.id = b.administrative_unit_id WHERE u.code = '179141'");
        self::assertSame([3844, 4326, true], [(int) $row['s'], (int) $row['w'], (bool) $row['bucuresti']]);
        self::assertSame('MULTIPOLYGON(((580000 325000,580000 326000,581000 326000,581000 325000,580000 325000)))', $row['src']);

        $again = $this->boundaries()->import($dir, 3844, false, false);
        self::assertSame('deja importat', $again['status']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM boundary_set'));
    }

    public function testBoundaryImportRefusesTamperedUnknownOrIncompleteArtifacts(): void
    {
        $this->siruta()->import($this->csv(self::ROWS), 'SIRUTA_test', false);
        $cases = [
            'pagină modificată' => function () { $d = $this->artifact(['179141', '179150', '54975']); file_put_contents("$d/page-000.json", ' ', FILE_APPEND); return $d; },
            'cod fără UAT' => fn () => $this->artifact(['179141', '179150', '54975', '99999']),
            'UAT fără poligon' => fn () => $this->artifact(['179141', '54975']),
        ];
        foreach ($cases as $label => $make) {
            try {
                $this->boundaries()->import($make(), 3844, false, false);
                self::fail("Trebuia refuzat: $label");
            } catch (\RuntimeException) {
                self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM boundary_set'), $label);
            }
        }
        $r = $this->boundaries()->import($this->artifact(['179141', '54975']), 3844, true, false);
        self::assertSame(['179150 BUCUREŞTI SECTORUL 2'], $r['missing']);
    }

    public function testProvisionalSetCanBeActivatedWithDocumentedUncertainty(): void
    {
        $this->siruta()->import($this->csv(self::ROWS), 'SIRUTA_test', false);
        $set = $this->boundaries()->import($this->artifact(['179141', '179150', '54975']), 3844, false, false)['set'];
        $set = $this->em->find(BoundarySet::class, $set->getId());
        $set->recordAccuracy(BoundaryAccuracyStatus::PROVISIONAL, '6.80', '7.00', 'test');
        $set->activate();
        $this->em->flush();
        self::assertSame('PROVISIONAL', $this->db->fetchOne('SELECT accuracy_status FROM boundary_set WHERE activated_at IS NOT NULL'));
    }
}
