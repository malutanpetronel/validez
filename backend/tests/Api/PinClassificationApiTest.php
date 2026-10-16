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
 * G.2b (ADR-0012 p.6): rezolvarea manuală a încadrării pinului și coada de moderare.
 * Limite de test în Stereo70: A [580000, 581000] și B [581000, 582000] cu frontieră comună, C [582005, 583000]; D = 7 m.
 */
final class PinClassificationApiTest extends WebTestCase
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
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $users = [new User('admin@validez.test', 'Admina', [User::ROLE_ADMIN]), new User('ion@validez.test', 'Ion'), new User('maria@validez.test', 'Maria')];
        foreach ($users as $u) {
            $u->setPassword($hasher->hashPassword($u, self::PAROLA));
            $em->persist($u);
        }
        $this->node = new TreeNode('Drumuri', $users[0], null, 0);
        $em->persist($this->node);

        $ro = AdministrativeUnit::country('România');
        $county = AdministrativeUnit::county($ro, 127, 40, 'JUDEŢUL TEST', 1, 'test');
        $units = ['A' => AdministrativeUnit::uat($county, 54975, 3, 'COMUNA A', 127, 'test'),
            'B' => AdministrativeUnit::uat($county, 55008, 3, 'COMUNA B', 127, 'test'),
            'C' => AdministrativeUnit::uat($county, 55017, 3, 'COMUNA C', 127, 'test')];
        $set = new BoundarySet('test', 'https://example.test', 'CC-BY-4.0', 'test', str_repeat('d', 64), 'Stereo70', 3844, 'PostGIS EPSG:3844');
        $set->recordAccuracy(BoundaryAccuracyStatus::PROVISIONAL, '6.80', '7.00', 'test');
        $set->activate();
        foreach ([$ro, $county, ...array_values($units), $set] as $e) {
            $em->persist($e);
        }
        $em->flush();
        foreach (['A' => [580000, 581000], 'B' => [581000, 582000], 'C' => [582005, 583000]] as $k => [$x1, $x2]) {
            $wkt = "MULTIPOLYGON((($x1 325000, $x1 326000, $x2 326000, $x2 325000, $x1 325000)))";
            $this->db->executeStatement('INSERT INTO administrative_boundary (boundary_set_id, administrative_unit_id, source_srid, geom_source, geom)
                VALUES (?, ?, 3844, ST_GeomFromText(?, 3844), ST_Transform(ST_GeomFromText(?, 3844), 4326))',
                [$set->getId()->toRfc4122(), $units[$k]->getId()->toRfc4122(), $wkt, $wkt]);
        }
    }

    private function token(string $who): string
    {
        return $this->tokens[$who] ??= $this->call('POST', '/api/auth', ['email' => "$who@validez.test", 'password' => self::PAROLA])['token'];
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $uri, ?array $body = null, ?string $who = null, string $type = 'application/json'): array
    {
        if ($method === 'POST' && $uri === '/api/auth') {
            $body['altcha'] = $this->solveAltcha($this->client);
        }
        $server = ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => $type === 'application/json' ? 'application/json' : 'application/ld+json'];
        if ($who !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token($who);
        }
        $this->client->request($method, $uri, server: $server, content: $body === null ? null : json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array{lat: float, lng: float} */
    private function point(float $x, float $y): array
    {
        $r = $this->db->fetchNumeric('SELECT ST_Y(p), ST_X(p) FROM (SELECT ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), 3844), 4326) AS p) q', [$x, $y]);

        return ['lat' => (float) $r[0], 'lng' => (float) $r[1]];
    }

    /** @return array<string, mixed> */
    private function create(string $who, array $location): array
    {
        $r = $this->call('POST', '/api/civic_subjects', ['node' => $this->node->getId()->toBase32(), 'type' => 'ISSUE', 'title' => 'Groapă', 'description' => 'd', 'location' => $location], $who, 'application/ld+json');
        self::assertSame(201, $this->code(), json_encode($r));

        return $r;
    }

    private function resolve(string $id, mixed $code, string $who): array
    {
        return $this->call('POST', "/api/civic_subjects/$id/pin-classification", ['code' => $code], $who);
    }

    public function testAuthorPicksACandidateWithoutANewRevisionAndMovingThePinCancelsIt(): void
    {
        $s = $this->create('ion', $this->point(580997, 325500));
        self::assertSame('AMBIGUOUS', $s['location']['administrative']['state']);
        self::assertSame(['54975', '55008'], array_column($s['location']['administrative']['candidates'], 'code'));
        $id = $s['id'];

        $r = $this->resolve($id, '55008', 'ion');
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame(['RESOLVED_MANUALLY', 'COMUNA B', 'JUDEȚUL TEST'], [$r['administrative']['state'], $r['administrative']['unit']['name'], $r['administrative']['county']['name']]);
        self::assertSame([1, 'RESOLVED_MANUALLY'], array_map(fn ($v) => is_numeric($v) ? (int) $v : $v,
            $this->db->fetchNumeric('SELECT revision_number, pin_admin_state FROM civic_subject')));
        self::assertNotNull($this->db->fetchOne('SELECT pin_admin_resolved_by_id FROM civic_subject'));

        // o a doua alegere: încadrarea e deja determinată
        $this->resolve($id, '54975', 'ion');
        self::assertSame(409, $this->code());

        // mutarea pinului anulează alegerea și recalculează
        $r = $this->call('PATCH', "/api/civic_subjects/$id", ['location' => $this->point(580500, 325500)], 'ion', 'application/merge-patch+json');
        self::assertSame(['RESOLVED', 'COMUNA A'], [$r['location']['administrative']['state'], $r['location']['administrative']['unit']['name']]);
        self::assertNull($this->db->fetchOne('SELECT pin_admin_resolved_by_id FROM civic_subject'));
    }

    public function testOnlyAuthorOrAdminAndOnlyACandidate(): void
    {
        $id = $this->create('ion', $this->point(582002.5, 325500))['id']; // golul B|C → UNMATCHED
        self::assertSame('UNMATCHED', $this->db->fetchOne('SELECT pin_admin_state FROM civic_subject'));

        $this->client->request('POST', "/api/civic_subjects/$id/pin-classification", server: ['CONTENT_TYPE' => 'application/json'], content: '{"code":"55008"}');
        self::assertSame(401, $this->code(), 'vizitator');

        $this->resolve($id, '55008', 'maria');
        self::assertContains($this->code(), [403, 404], 'alt utilizator (subiectul lui Ion e în așteptare → 404)');
        $this->resolve($id, '54975', 'ion');
        self::assertSame(422, $this->code(), 'A nu e candidat');
        self::assertSame('not_a_candidate', json_decode((string) $this->client->getResponse()->getContent(), true)['reason']);
        $this->resolve($id, 55008, 'ion');
        self::assertSame(422, $this->code(), 'codul trebuie să fie text');

        $r = $this->resolve($id, '55017', 'admin');
        self::assertSame([200, 'COMUNA C'], [$this->code(), $r['administrative']['unit']['name']]);
    }

    public function testResolvedPinsCannotBeOverriddenAndTheModerationQueueListsOnlyOpenCases(): void
    {
        $resolved = $this->create('admin', $this->point(580500, 325500))['id'];
        $this->resolve($resolved, '54975', 'admin');
        self::assertSame(409, $this->code(), 'RESOLVED nu se suprascrie manual');

        $open = $this->create('admin', $this->point(581000, 325500))['id']; // pe frontieră → AMBIGUOUS
        $this->create('admin', $this->point(600000, 325500)); // OUTSIDE

        $this->call('GET', '/api/moderation/pin-classifications', null, 'ion');
        self::assertSame(403, $this->code());
        $items = $this->call('GET', '/api/moderation/pin-classifications', null, 'admin')['items'];
        self::assertEqualsCanonicalizing(['AMBIGUOUS', 'OUTSIDE'], array_column(array_column($items, 'administrative'), 'state'));

        $this->resolve($open, '55008', 'admin');
        $items = $this->call('GET', '/api/moderation/pin-classifications', null, 'admin')['items'];
        self::assertSame(['OUTSIDE'], array_column(array_column($items, 'administrative'), 'state'));
    }
}
