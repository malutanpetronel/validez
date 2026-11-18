<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AdministrativeUnit;
use App\Entity\BoundarySet;
use App\Entity\TreeNode;
use App\Entity\User;
use App\Geo\BoundaryAccuracyStatus;
use App\Tests\Support\AltchaPayload;
use App\Tests\Support\Database;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * G.3 (ADR-0012 p.4–5, p.7): aria declarată, contractul `scope`, instantaneul din revizie și căutarea unităților.
 * Limite de test în Stereo70: A [580000, 581000] și B [581000, 582000] cu frontieră comună; D = 7 m.
 */
final class SubjectAreaTest extends WebTestCase
{
    use AltchaPayload;

    private const PAROLA = 'parola-de-test-123';

    private KernelBrowser $client;
    private Connection $db;
    private TreeNode $node;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $c = static::getContainer();
        $c->get('limiter.captcha_challenge')->create('127.0.0.1')->reset();
        $c->get('cache.rate_limiter')->clear();
        $this->db = $c->get(Connection::class);
        Database::reset($this->db, 'administrative_boundary', 'boundary_set', 'administrative_unit');
        $em = $c->get(EntityManagerInterface::class);
        $admin = new User('admin@validez.test', 'Admina', [User::ROLE_ADMIN]);
        $admin->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($admin, self::PAROLA));
        $em->persist($admin);
        $this->node = new TreeNode('Drumuri', $admin, null, 0);
        $em->persist($this->node);

        $ro = AdministrativeUnit::country('România');
        $county = AdministrativeUnit::county($ro, 127, 40, 'JUDEŢUL TEST', 1, 'test');
        $a = AdministrativeUnit::uat($county, 54975, 3, 'COMUNA A', 127, 'test');
        $b = AdministrativeUnit::uat($county, 55008, 3, 'COMUNA BĂRBĂTEŞTI', 127, 'test');
        $old = AdministrativeUnit::uat($county, 55017, 3, 'COMUNA VECHE', 127, 'test');
        $old->retire();
        $set = new BoundarySet('test', 'https://example.test', 'CC-BY-4.0', 'test', str_repeat('d', 64), 'Stereo70', 3844, 'PostGIS EPSG:3844');
        $set->recordAccuracy(BoundaryAccuracyStatus::PROVISIONAL, '6.80', '7.00', 'test');
        $set->activate();
        foreach ([$ro, $county, $a, $b, $old, $set] as $e) {
            $em->persist($e);
        }
        $em->flush();
        foreach ([[$a, 580000, 581000], [$b, 581000, 582000]] as [$unit, $x1, $x2]) {
            $wkt = "MULTIPOLYGON((($x1 325000, $x1 326000, $x2 326000, $x2 325000, $x1 325000)))";
            $this->db->executeStatement('INSERT INTO administrative_boundary (boundary_set_id, administrative_unit_id, source_srid, geom_source, geom)
                VALUES (?, ?, 3844, ST_GeomFromText(?, 3844), ST_Transform(ST_GeomFromText(?, 3844), 4326))',
                [$set->getId()->toRfc4122(), $unit->getId()->toRfc4122(), $wkt, $wkt]);
        }
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $uri, ?array $body = null, bool $auth = true, string $type = 'application/json'): array
    {
        if ($method === 'POST' && $uri === '/api/auth') {
            $body['altcha'] = $this->solveAltcha($this->client);
        }
        $server = ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => $type === 'application/json' ? 'application/json' : 'application/ld+json'];
        if ($auth) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.($this->tokens['admin'] ??= $this->call('POST', '/api/auth', ['email' => 'admin@validez.test', 'password' => self::PAROLA], false)['token']);
        }
        $this->client->request($method, $uri, server: $server, content: $body === null ? null : json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function code(): int { return $this->client->getResponse()->getStatusCode(); }

    /** @return array{lat: float, lng: float} */
    private function point(float $x, float $y): array
    {
        $r = $this->db->fetchNumeric('SELECT ST_Y(p), ST_X(p) FROM (SELECT ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), 3844), 4326) AS p) q', [$x, $y]);

        return ['lat' => (float) $r[0], 'lng' => (float) $r[1]];
    }

    /** @return array<string, mixed> */
    private function create(array $extra): array
    {
        return $this->call('POST', '/api/civic_subjects', $extra + ['node' => $this->node->getId()->toBase32(), 'type' => 'ISSUE', 'title' => 'Groapă', 'description' => 'd'], true, 'application/ld+json');
    }

    /** @return array<string, mixed> */
    private function patch(string $id, array $body): array
    {
        return $this->call('PATCH', "/api/civic_subjects/$id", $body, true, 'application/merge-patch+json');
    }

    public function testContractulScope(): void
    {
        $this->create([]);
        self::assertSame(422, $this->code(), 'subiect nou fără arie');
        $this->create(['scope' => 'GLOBAL']);
        self::assertSame(422, $this->code(), 'scope necunoscut');
        $this->create(['scope' => 'LOCAL']);
        self::assertSame(422, $this->code(), 'LOCAL fără UAT și fără pin');
        $this->create(['scope' => 'LOCAL', 'areaUnits' => ['127']]);
        self::assertSame(422, $this->code(), 'LOCAL cu județ');
        $this->create(['scope' => 'COUNTY']);
        self::assertSame(422, $this->code(), 'COUNTY fără județ');
        $this->create(['scope' => 'COUNTY', 'areaUnits' => ['54975']]);
        self::assertSame(422, $this->code(), 'COUNTY cu UAT');
        $this->create(['scope' => 'NATIONAL', 'areaUnits' => ['127']]);
        self::assertSame(422, $this->code(), 'NATIONAL cu unități');
        $this->create(['scope' => 'LOCAL', 'areaUnits' => ['99999']]);
        self::assertSame(422, $this->code(), 'cod necunoscut');
        $r = $this->create(['scope' => 'LOCAL', 'areaUnits' => ['55017']]);
        self::assertSame(422, $this->code(), 'unitate retrasă');
        self::assertStringContainsString('nu mai există', $r['detail'] ?? '');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM civic_subject'));

        $r = $this->create(['scope' => 'LOCAL', 'areaUnits' => ['55008', '54975', '55008']]);
        self::assertSame(201, $this->code(), json_encode($r));
        self::assertSame(['54975', '55008'], array_column($r['area']['units'], 'code'), 'fără duplicate, ordonate');
        self::assertSame('JUDEȚUL TEST', $r['area']['units'][0]['county'] ?? null);
        $r = $this->create(['scope' => 'COUNTY', 'areaUnits' => ['127']]);
        self::assertSame([201, ['127']], [$this->code(), array_column($r['area']['effective'], 'code')]);
        $r = $this->create(['scope' => 'NATIONAL']);
        self::assertSame([201, [['code' => 'RO', 'name' => 'România', 'level' => 'COUNTRY', 'county' => null, 'fromPin' => false]], []],
            [$this->code(), $r['area']['effective'], $r['area']['units']]);
    }

    public function testPinulIntraInAriaLocalaDoarCandEIncadrat(): void
    {
        $r = $this->create(['scope' => 'LOCAL', 'location' => $this->point(580500, 325500)]);
        self::assertSame(201, $this->code(), json_encode($r));
        self::assertSame([], $r['area']['units']);
        self::assertSame([['54975', true]], array_map(static fn ($u) => [$u['code'], $u['fromPin']], $r['area']['effective']));

        $this->create(['scope' => 'LOCAL', 'location' => $this->point(581000, 325500)]); // pe frontieră → AMBIGUOUS
        self::assertSame(422, $this->code(), 'pin nedeterminat fără UAT declarat');
        $r = $this->create(['scope' => 'LOCAL', 'areaUnits' => ['55008'], 'location' => $this->point(581000, 325500)]);
        self::assertSame([201, ['55008']], [$this->code(), array_column($r['area']['effective'], 'code')]);

        // La NATIONAL pinul nu intră în aria efectivă.
        $r = $this->create(['scope' => 'NATIONAL', 'location' => $this->point(580500, 325500)]);
        self::assertSame(['RO'], array_column($r['area']['effective'], 'code'));
    }

    public function testScoatereaPinuluiPeSubiectLocalFaraLocalitate(): void
    {
        $onlyPin = $this->create(['scope' => 'LOCAL', 'location' => $this->point(580500, 325500)])['id'];
        $r = $this->patch($onlyPin, ['location' => null]);
        self::assertSame(422, $this->code());
        self::assertStringContainsString('«Aria subiectului»', $r['detail'] ?? '');
        $r = $this->patch($onlyPin, ['location' => null, 'areaUnits' => ['54975']]);
        self::assertSame([200, null, ['54975']], [$this->code(), $r['location'], array_column($r['area']['effective'], 'code')]);
    }

    public function testAriaFaceParteDinRevizie(): void
    {
        $id = $this->create(['scope' => 'LOCAL', 'areaUnits' => ['54975']])['id'];
        $r = $this->patch($id, ['scope' => 'LOCAL', 'areaUnits' => ['54975']]);
        self::assertSame([200, 1], [$this->code(), $r['revisionNumber']], 'aceeași arie nu face revizie');
        $r = $this->patch($id, ['areaUnits' => ['54975', '55008']]);
        self::assertSame([200, 2], [$this->code(), $r['revisionNumber']]);
        $r = $this->patch($id, ['scope' => 'NATIONAL']);
        self::assertSame([200, 3, []], [$this->code(), $r['revisionNumber'], $r['area']['units']], 'NATIONAL golește unitățile');
        $this->patch($id, ['scope' => null]);
        self::assertSame(422, $this->code(), 'aria nu mai revine la NULL');

        $revisions = $this->call('GET', "/api/civic_subjects/$id/revisions", null, false)['revisions'];
        self::assertSame(['LOCAL', 'LOCAL', 'NATIONAL'], array_column(array_column($revisions, 'area'), 'scope'));
        self::assertSame(['54975', '55008'], array_column($revisions[1]['area']['units'], 'code'));
        self::assertSame('COMUNA BĂRBĂTEȘTI', $revisions[1]['area']['units'][1]['name']);

        // Instantaneul nu se schimbă când unitatea e redenumită ulterior în nomenclator.
        $this->db->executeStatement("UPDATE administrative_unit SET display_name = 'COMUNA REDENUMITĂ' WHERE code = '55008'");
        $revisions = $this->call('GET', "/api/civic_subjects/$id/revisions", null, false)['revisions'];
        self::assertSame('COMUNA BĂRBĂTEȘTI', $revisions[1]['area']['units'][1]['name']);
    }

    public function testSubiectulVechiFaraArieRamaneEditabil(): void
    {
        $id = $this->create(['scope' => 'NATIONAL'])['id'];
        $this->db->executeStatement('UPDATE civic_subject SET scope = NULL');
        $r = $this->patch($id, ['title' => 'Groapă mare']);
        self::assertSame([200, null], [$this->code(), $r['area']]);
        $this->patch($id, ['areaUnits' => ['54975']]);
        self::assertSame(422, $this->code(), 'unități fără arie');
        $r = $this->patch($id, ['scope' => 'COUNTY', 'areaUnits' => ['127']]);
        self::assertSame([200, 'COUNTY'], [$this->code(), $r['area']['scope']]);
    }

    public function testCautareaUnitatilor(): void
    {
        $items = $this->call('GET', '/api/administrative_units?level=UAT&q=barbatesti', null, false)['items'];
        self::assertSame([['code' => '55008', 'name' => 'COMUNA BĂRBĂTEȘTI', 'level' => 'UAT', 'county' => 'JUDEȚUL TEST']], $items, 'fără diacritice');
        self::assertStringContainsString('public', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $items = $this->call('GET', '/api/administrative_units?level=UAT&q=comuna', null, false)['items'];
        self::assertSame(['54975', '55008'], array_column($items, 'code'), 'fără unitatea retrasă');
        $items = $this->call('GET', '/api/administrative_units?level=COUNTY&q=TEST', null, false)['items'];
        self::assertSame([['127', null]], array_map(static fn ($u) => [$u['code'], $u['county']], $items));
        // Fără text: toate județele, alfabetic (nu doar primele 20 după lungimea numelui).
        $ro = $this->db->fetchOne("SELECT id FROM administrative_unit WHERE level = 'COUNTRY'");
        foreach (range(1, 25) as $i) {
            $this->db->executeStatement("INSERT INTO administrative_unit (id, code, siruta, level, parent_id, path, official_name, display_name, external_ids, created_at, updated_at)
                SELECT gen_random_uuid(), :c, :s, 'COUNTY', id, path || :seg, :n, :n, '{}', now(), now() FROM administrative_unit WHERE id = :ro",
                ['c' => (string) (900 + $i), 's' => 900 + $i, 'seg' => 'c'.$i, 'n' => \sprintf('JUDEȚUL Z%02d', $i), 'ro' => $ro]);
        }
        $this->db->executeStatement("UPDATE administrative_unit SET display_name = 'JUDEȚUL BISTRIȚA-NĂSĂUD-FOARTE-LUNG' WHERE code = '127'");
        $names = array_column($this->call('GET', '/api/administrative_units?level=COUNTY&q=', null, false)['items'], 'name');
        self::assertCount(26, $names);
        self::assertSame('JUDEȚUL BISTRIȚA-NĂSĂUD-FOARTE-LUNG', $names[0]);
        $this->call('GET', '/api/administrative_units?level=COUNTRY', null, false);
        self::assertSame(400, $this->code());
    }

    public function testShellulSeoAreLoculInTitluSiJsonLd(): void
    {
        $r = $this->create(['scope' => 'LOCAL', 'areaUnits' => ['55008']]);
        $this->client->request('GET', $r['canonicalPath']);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(200, $this->code());
        self::assertStringContainsString('<title>Groapă — Bărbătești', $html);
        self::assertStringContainsString('"spatialCoverage":{"@type":"AdministrativeArea","name":"Comuna Bărbătești","containedInPlace":{"@type":"AdministrativeArea","name":"Județul Test"}}', $html);
    }
}
