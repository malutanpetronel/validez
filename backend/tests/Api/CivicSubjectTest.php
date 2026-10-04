<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\TreeNode;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Step 2: CivicSubject — listă pe subarbore, detaliu, creare/editare, reguli de acces și vizibilitate. */
final class CivicSubjectTest extends WebTestCase
{
    private const PAROLA = 'parola-de-test-123';

    private KernelBrowser $client;
    private Connection $db;
    /** @var array<string, TreeNode> */
    private array $n = [];
    /** @var array<string, string> email => token */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $c = static::getContainer();
        $this->db = $c->get(Connection::class);
        foreach (['civic_subject', 'tree_node', 'refresh_token', 'app_user'] as $t) {
            $this->db->executeStatement("DELETE FROM $t");
        }
        $em = $c->get(EntityManagerInterface::class);
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $users = ['admin' => new User('admin@validez.test', 'Admina', [User::ROLE_ADMIN]), 'ion' => new User('ion@validez.test', 'Ion'), 'maria' => new User('maria@validez.test', 'Maria')];
        foreach ($users as $u) {
            $u->setPassword($hasher->hashPassword($u, self::PAROLA));
            $em->persist($u);
        }
        // Drumuri > Cluj > Calitate ; Sanatate
        $add = function (string $name, ?string $parent, int $pos) use ($em, $users): void {
            $this->n[$name] = new TreeNode($name, $users['admin'], $parent === null ? null : $this->n[$parent], $pos);
            $em->persist($this->n[$name]);
        };
        $add('Drumuri', null, 0);
        $add('Cluj', 'Drumuri', 0);
        $add('Calitate', 'Cluj', 0);
        $add('Sanatate', null, 1);
        $em->flush();
    }

    private function token(string $who): string
    {
        if (!isset($this->tokens[$who])) {
            $r = $this->call('POST', '/api/auth', ['email' => "$who@validez.test", 'password' => self::PAROLA], null, 'application/json');
            $this->tokens[$who] = $r['token'];
        }

        return $this->tokens[$who];
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $uri, ?array $body = null, ?string $who = null, string $type = 'application/ld+json'): array
    {
        $server = ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => 'application/ld+json'];
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

    private function create(string $who, string $node, string $type = 'ISSUE', string $title = 'Gropi pe DN1'): array
    {
        return $this->call('POST', '/api/civic_subjects', ['node' => $this->n[$node]->getId()->toBase32(), 'type' => $type, 'title' => $title, 'description' => 'Descriere'], $who);
    }

    private function patch(string $id, array $body, ?string $who): array
    {
        return $this->call('PATCH', '/api/civic_subjects/'.$id, $body, $who, 'application/merge-patch+json');
    }

    /** @return list<string> */
    private function titles(string $query = '', ?string $who = null): array
    {
        $r = $this->call('GET', '/api/civic_subjects'.$query, null, $who);
        self::assertSame(200, $this->code());

        return array_map(static fn ($s) => $s['title'], $r['member']);
    }

    public function testUtilizatorulCreeazaTipuriPermiseAutorulDinToken(): void
    {
        $r = $this->create('ion', 'Calitate', 'ISSUE');
        self::assertSame(201, $this->code());
        self::assertSame('Ion', $r['authorName']);
        self::assertSame('Calitate', $r['nodeName']);
        self::assertSame(['PUBLISHED', 'OPEN'], [$r['visibility'], $r['stage']]);

        foreach (['PROPOSAL', 'PETITION'] as $t) {
            $this->create('ion', 'Calitate', $t);
            self::assertSame(201, $this->code(), $t);
        }
    }

    public function testTipurileDoarAdminSuntRefuzateUtilizatorilor(): void
    {
        foreach (['PROMISE', 'ELECTORAL_EVALUATION', 'PROJECT', 'TOPIC_EVALUATION'] as $t) {
            $this->create('ion', 'Cluj', $t);
            self::assertSame(403, $this->code(), $t);
            $this->create('admin', 'Cluj', $t);
            self::assertSame(201, $this->code(), $t);
        }
    }

    public function testVizitatorulCitesteDarNuCreeaza(): void
    {
        $id = $this->create('ion', 'Cluj')['id'];
        $this->call('POST', '/api/civic_subjects', ['node' => $this->n['Cluj']->getId()->toBase32(), 'type' => 'ISSUE', 'title' => 'X X X', 'description' => 'd']);
        self::assertSame(401, $this->code());
        self::assertSame(['Gropi pe DN1'], $this->titles());
        $this->call('GET', '/api/civic_subjects/'.$id);
        self::assertSame(200, $this->code());
    }

    public function testListaPeNodIncludeSubarboreleSiFiltreaza(): void
    {
        $this->create('ion', 'Drumuri', 'ISSUE', 'Pe Drumuri');
        $this->create('ion', 'Calitate', 'PROPOSAL', 'Pe Calitate');
        $this->create('ion', 'Sanatate', 'ISSUE', 'Pe Sanatate');

        $q = '?node='.$this->n['Cluj']->getId()->toBase32();
        self::assertSame(['Pe Calitate'], $this->titles($q));
        self::assertEqualsCanonicalizing(['Pe Drumuri', 'Pe Calitate'], $this->titles('?node='.$this->n['Drumuri']->getId()->toBase32()));
        self::assertSame(['Pe Drumuri'], $this->titles('?node='.$this->n['Drumuri']->getId()->toBase32().'&type=ISSUE'));

        $this->call('GET', '/api/civic_subjects?type=NIMIC');
        self::assertSame(400, $this->code());
        $this->call('GET', '/api/civic_subjects?node=01JZZZZZZZZZZZZZZZZZZZZZZZ');
        self::assertSame(404, $this->code());
    }

    public function testPaginare(): void
    {
        for ($i = 1; $i <= 23; $i++) {
            $this->create('ion', 'Cluj', 'ISSUE', "Subiect $i");
        }
        $r = $this->call('GET', '/api/civic_subjects?page=2');
        self::assertSame(23, $r['totalItems']);
        self::assertCount(3, $r['member']);
    }

    public function testAutorulEditeazaAltUtilizatorNu(): void
    {
        $id = $this->create('ion', 'Cluj')['id'];

        $r = $this->patch($id, ['title' => 'Gropi mari pe DN1', 'type' => 'PETITION'], 'ion');
        self::assertSame(200, $this->code());
        self::assertSame(['Gropi mari pe DN1', 'PETITION'], [$r['title'], $r['type']]);

        $this->patch($id, ['title' => 'Altceva'], 'maria');
        self::assertSame(403, $this->code());
        $this->patch($id, ['title' => 'Altceva'], null);
        self::assertSame(401, $this->code());
    }

    public function testAutorulNuSchimbaStadiulVizibilitateaSauTipDoarAdmin(): void
    {
        $id = $this->create('ion', 'Cluj')['id'];
        foreach ([['stage' => 'RESOLVED'], ['visibility' => 'HIDDEN'], ['type' => 'PROMISE']] as $body) {
            $this->patch($id, $body, 'ion');
            self::assertSame(403, $this->code(), json_encode($body));
        }
        $r = $this->patch($id, ['stage' => 'IN_PROGRESS', 'title' => 'Editat de admin'], 'admin');
        self::assertSame(200, $this->code());
        self::assertSame(['IN_PROGRESS', 'Editat de admin'], [$r['stage'], $r['title']]);
    }

    public function testSubiectulAscunsIlVadDoarAdminulSiAutorul(): void
    {
        $id = $this->create('ion', 'Cluj', 'ISSUE', 'Ascuns')['id'];
        $this->patch($id, ['visibility' => 'HIDDEN'], 'admin');
        self::assertSame(200, $this->code());

        foreach ([null, 'maria'] as $who) {
            $this->call('GET', '/api/civic_subjects/'.$id, null, $who);
            self::assertSame(404, $this->code(), (string) $who);
            self::assertSame([], $this->titles('', $who));
            $this->patch($id, ['title' => 'Incercare'], $who);
            self::assertContains($this->code(), [401, 404], (string) $who);
        }
        foreach (['ion', 'admin'] as $who) {
            $this->call('GET', '/api/civic_subjects/'.$id, null, $who);
            self::assertSame(200, $this->code(), $who);
            self::assertSame(['Ascuns'], $this->titles('', $who));
        }
    }

    public function testValidare(): void
    {
        $this->call('POST', '/api/civic_subjects', ['node' => $this->n['Cluj']->getId()->toBase32(), 'type' => 'ISSUE', 'title' => '  ', 'description' => 'd'], 'ion');
        self::assertSame(422, $this->code());
        $this->call('POST', '/api/civic_subjects', ['node' => $this->n['Cluj']->getId()->toBase32(), 'type' => 'NIMIC', 'title' => 'Titlu', 'description' => 'd'], 'ion');
        self::assertSame(422, $this->code());
        $this->call('POST', '/api/civic_subjects', ['node' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'type' => 'ISSUE', 'title' => 'Titlu', 'description' => 'd'], 'ion');
        self::assertSame(422, $this->code());
        $this->call('POST', '/api/civic_subjects', ['node' => $this->n['Cluj']->getId()->toBase32(), 'type' => 'ISSUE', 'title' => 'Titlu', 'description' => 'd', 'author' => 'altcineva'], 'ion');
        self::assertSame(201, $this->code());
    }
}
