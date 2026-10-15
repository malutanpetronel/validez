<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\TreeNode;
use App\Entity\User;
use App\Tests\Support\AltchaPayload;
use App\Tests\Support\Database;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Step 3 M.2 (ADR-0011 p.9, p.11, p.12): pinul face parte din revizie; „confirmată” vine de la admin sau din votul
 * LOCATION al utilizatorilor (prag LOCATION_CONFIRM_MIN = 3 implicit, fără votul autorului) și nu creează revizie.
 */
final class SubjectLocationTest extends WebTestCase
{
    use AltchaPayload;

    private const PAROLA = 'parola-de-test-123';
    private const CLUJ = ['lat' => 46.7712345678, 'lng' => 23.6236, 'source' => 'DEVICE'];
    private const DEJ = ['lat' => 47.1416, 'lng' => 23.8756];

    private KernelBrowser $client;
    private Connection $db;
    private TreeNode $node;
    private string $notice;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $c = static::getContainer();
        $c->get('limiter.captcha_challenge')->create('127.0.0.1')->reset();
        $c->get('cache.rate_limiter')->clear();
        $this->db = $c->get(Connection::class);
        Database::reset($this->db);
        $this->notice = $c->getParameter('vote_consent_notice_version_default');
        $em = $c->get(EntityManagerInterface::class);
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $users = [new User('admin@validez.test', 'Admina', [User::ROLE_ADMIN])];
        foreach (['ion', 'maria', 'vlad', 'ana'] as $name) {
            $users[] = new User("$name@validez.test", ucfirst($name));
        }
        foreach ($users as $u) {
            $u->setPassword($hasher->hashPassword($u, self::PAROLA));
            $em->persist($u);
        }
        $this->node = new TreeNode('Drumuri', $users[0], null, 0);
        $em->persist($this->node);
        $em->flush();
    }

    private function token(string $who): string
    {
        if (!isset($this->tokens[$who])) {
            $r = $this->call('POST', '/api/auth', ['email' => "$who@validez.test", 'password' => self::PAROLA]);
            $this->tokens[$who] = $r['token'];
        }

        return $this->tokens[$who];
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

    /** @return array<string, mixed> subiectul creat */
    private function create(string $who, ?array $location = null): array
    {
        $body = ['node' => $this->node->getId()->toBase32(), 'type' => 'ISSUE', 'title' => 'Groapă', 'description' => 'Pe DN1'];
        if ($location !== null) {
            $body['location'] = $location;
        }
        $r = $this->call('POST', '/api/civic_subjects', $body, $who, 'application/ld+json');
        self::assertSame(201, $this->code(), json_encode($r));

        return $r;
    }

    /** @return array<string, mixed> */
    private function patch(string $id, array $body, string $who): array
    {
        return $this->call('PATCH', "/api/civic_subjects/$id", $body, $who, 'application/merge-patch+json');
    }

    private function vote(string $id, int $value, string $who): void
    {
        $this->call('PUT', '/api/me/vote-consent', ['noticeVersion' => $this->notice], $who);
        $this->call('PUT', "/api/civic_subjects/$id/votes/LOCATION", ['value' => $value], $who);
        self::assertSame(200, $this->code(), "vot LOCATION $who");
    }

    /** @return array<string, mixed>|null */
    private function location(string $id): ?array
    {
        return $this->call('GET', "/api/civic_subjects/$id", null, null, 'application/ld+json')['location'] ?? null;
    }

    public function testPinulLaCreareIntraInRevizia1(): void
    {
        $s = $this->create('admin', self::CLUJ);
        self::assertSame(['lat' => 46.771235, 'lng' => 23.6236, 'source' => 'DEVICE', 'confirmed' => false, 'confirmedBy' => null, 'confirmations' => 0], $s['location']);
        $revisions = $this->call('GET', "/api/civic_subjects/{$s['id']}/revisions")['revisions'];
        self::assertSame(['lat' => 46.771235, 'lng' => 23.6236, 'source' => 'DEVICE'], $revisions[0]['location']);
        $types = array_column($this->call('GET', "/api/civic_subjects/{$s['id']}/votes")['types'], 'active', 'code');
        self::assertTrue($types['LOCATION']);

        $fara = $this->create('admin');
        self::assertNull($fara['location']);
        self::assertArrayNotHasKey('LOCATION', array_column($this->call('GET', "/api/civic_subjects/{$fara['id']}/votes")['types'], 'active', 'code'));
        $this->call('PUT', '/api/me/vote-consent', ['noticeVersion' => $this->notice], 'ion');
        $this->call('PUT', "/api/civic_subjects/{$fara['id']}/votes/LOCATION", ['value' => 1], 'ion');
        self::assertGreaterThanOrEqual(400, $this->code(), 'fără pin nu se votează locul');
    }

    public function testMutareaSiScoatereaPinuluiCreeazaRevizie(): void
    {
        $id = $this->create('admin', self::CLUJ)['id'];
        // Același punct (alt format, altă sursă): fără revizie.
        $r = $this->patch($id, ['location' => ['lat' => '46.771235', 'lng' => 23.6236, 'source' => 'MANUAL']], 'admin');
        self::assertSame([1, 'MANUAL'], [$r['revisionNumber'], $r['location']['source']]);

        $r = $this->patch($id, ['location' => self::DEJ], 'admin');
        self::assertSame(2, $r['revisionNumber']);
        $revisions = $this->call('GET', "/api/civic_subjects/$id/revisions")['revisions'];
        self::assertSame([46.771235, 47.1416], array_map(static fn (array $rev): float => $rev['location']['lat'], $revisions));

        // Alt câmp, fără `location` în payload: pinul rămâne.
        $r = $this->patch($id, ['title' => 'Groapă mare'], 'admin');
        self::assertSame([3, 47.1416], [$r['revisionNumber'], $r['location']['lat']]);

        $r = $this->patch($id, ['location' => null], 'admin');
        self::assertSame(4, $r['revisionNumber']);
        self::assertNull($r['location']);
        self::assertNull($this->call('GET', "/api/civic_subjects/$id/revisions")['revisions'][3]['location']);
        $types = array_column($this->call('GET', "/api/civic_subjects/$id/votes")['types'], 'active', 'code');
        self::assertFalse($types['LOCATION'], 'fără pin, tipul LOCATION e inactiv');
        $this->call('PUT', '/api/me/vote-consent', ['noticeVersion' => $this->notice], 'ion');
        $this->call('PUT', "/api/civic_subjects/$id/votes/LOCATION", ['value' => 1], 'ion');
        self::assertGreaterThanOrEqual(400, $this->code());

        $r = $this->patch($id, ['location' => self::CLUJ], 'admin');
        self::assertSame(5, $r['revisionNumber']);
        self::assertTrue(array_column($this->call('GET', "/api/civic_subjects/$id/votes")['types'], 'active', 'code')['LOCATION']);
    }

    public function testConfirmareaPrinVotFaraAutor(): void
    {
        $id = $this->create('admin', self::CLUJ)['id'];
        $this->vote($id, 1, 'admin'); // autorul: nu se numără
        $this->vote($id, 1, 'ion');
        $this->vote($id, 1, 'maria');
        self::assertSame([false, 2], [$this->location($id)['confirmed'], $this->location($id)['confirmations']]);

        $this->vote($id, 1, 'vlad');
        $loc = $this->location($id);
        self::assertSame([true, 'USERS', 3], [$loc['confirmed'], $loc['confirmedBy'], $loc['confirmations']]);
        $this->vote($id, 0, 'ana');
        self::assertTrue($this->location($id)['confirmed'], '3 Da > 1 Nu');

        // Confirmarea nu face revizie; mutarea pinului da, iar voturile vechi nu mai confirmă noul punct.
        self::assertSame(1, $this->call('GET', "/api/civic_subjects/$id", null, null, 'application/ld+json')['revisionNumber']);
        $r = $this->patch($id, ['location' => self::DEJ], 'admin');
        self::assertSame([2, false, 0], [$r['revisionNumber'], $r['location']['confirmed'], $r['location']['confirmations']]);

        // Reconfirmare pe noua revizie; „Nu” la egalitate blochează.
        $this->vote($id, 1, 'ion');
        $this->vote($id, 1, 'maria');
        $this->vote($id, 0, 'vlad');
        $this->vote($id, 0, 'ana');
        self::assertFalse($this->location($id)['confirmed']);
    }

    public function testConfirmareaAdminuluiFaraRevizie(): void
    {
        $id = $this->create('admin', self::CLUJ)['id'];
        $fara = $this->create('admin')['id'];
        $this->patch($fara, ['locationConfirmed' => true], 'admin');
        self::assertSame(422, $this->code());

        $r = $this->patch($id, ['locationConfirmed' => true], 'admin');
        self::assertSame([1, true, 'ADMIN'], [$r['revisionNumber'], $r['location']['confirmed'], $r['location']['confirmedBy']]);
        $r = $this->patch($id, ['location' => self::DEJ], 'admin');
        self::assertFalse($r['location']['confirmed'], 'mutarea anulează confirmarea adminului');
    }

    public function testAutorulNuSePoateConfirmaSingurDirect(): void
    {
        $id = $this->create('ion', self::CLUJ)['id'];
        $this->patch($id, ['locationConfirmed' => true], 'ion');
        self::assertSame(403, $this->code());
    }

    public function testAutorSubModerareMutaPinulSubiectulReintraLaAprobare(): void
    {
        $id = $this->create('ion', self::CLUJ)['id'];
        $r = $this->patch($id, ['visibility' => 'PUBLISHED'], 'admin');
        self::assertSame('PUBLISHED', $r['visibility']);
        $r = $this->patch($id, ['location' => self::DEJ], 'ion');
        self::assertSame(['PENDING', 2], [$r['visibility'], $r['revisionNumber']]);
    }

    public function testCoordonateInvalide(): void
    {
        foreach ([
            ['lat' => 91, 'lng' => 23],
            ['lat' => 46, 'lng' => -180.5],
            ['lat' => 'abc', 'lng' => 23],
            ['lng' => 23],
            ['lat' => 46, 'lng' => 23, 'source' => 'GPS'],
        ] as $location) {
            $this->call('POST', '/api/civic_subjects', ['node' => $this->node->getId()->toBase32(), 'type' => 'ISSUE', 'title' => 'Groapă', 'description' => 'd', 'location' => $location], 'admin', 'application/ld+json');
            self::assertSame(422, $this->code(), json_encode($location));
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM civic_subject'));
    }
}
