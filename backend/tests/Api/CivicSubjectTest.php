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
        self::assertSame(['Pe Drumuri'], $this->titles('?node='.$this->n['Drumuri']->getId()->toBase32().'&scope=direct'));
        self::assertSame([], $this->titles('?node='.$this->n['Cluj']->getId()->toBase32().'&scope=direct'));

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

    public function testEstimateExactPartialUpdatesAndDatabaseConstraint(): void
    {
        $id = $this->create('ion', 'Cluj', 'PROPOSAL')['id'];
        $estimate = ['costEstimate' => '940.10', 'costCurrency' => 'EUR', 'costEstimateScope' => "Pentru un loc de parcare\nInclude montajul"];
        $r = $this->patch($id, $estimate, 'ion');
        self::assertSame(200, $this->code());
        self::assertSame('940.10', $r['costEstimate']);
        $r = $this->patch($id, ['costEstimate' => '0'], 'ion');
        self::assertSame(200, $this->code());
        self::assertSame('0.00', $r['costEstimate']);
        self::assertSame('EUR', $r['costCurrency']);
        foreach ([['costEstimate' => null], ['costEstimate' => '-1'], ['costEstimate' => '1.001'], ['costEstimate' => '1e3'], ['costCurrency' => 'XXX'], ['costEstimateScope' => '   '], ['costEstimateScope' => str_repeat('x', 201)]] as $invalid) {
            $this->patch($id, $invalid, 'ion');
            self::assertSame(422, $this->code(), json_encode($invalid));
        }
        $r = $this->patch($id, array_fill_keys(array_keys($estimate), null), 'ion');
        self::assertSame(200, $this->code());
        self::assertNull($r['costEstimate']);
        self::assertNull($r['costCurrency']);
        self::assertNull($r['costEstimateScope']);
        $this->patch($id, ['costCurrency' => 'RON'], 'ion');
        self::assertSame(422, $this->code());
        $this->db->beginTransaction();
        try {
            $this->db->executeStatement("UPDATE civic_subject SET cost_currency = 'RON' WHERE id = ?", [\Symfony\Component\Uid\Ulid::fromString($id)->toRfc4122()]);
            self::fail('Database accepted a currency without an amount');
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            self::assertStringContainsString('chk_subject_cost_complete', $e->getMessage());
        } finally {
            $this->db->rollBack();
        }
    }

    public function testEstimateCreationAndOnlyProposal(): void
    {
        $payload = ['node' => $this->n['Cluj']->getId()->toBase32(), 'type' => 'PROPOSAL', 'title' => 'Parcare inteligenta', 'description' => 'Descriere',
            'costEstimate' => '9999999999.99', 'costCurrency' => 'RON', 'costEstimateScope' => 'Pentru un loc'];
        $r = $this->call('POST', '/api/civic_subjects', $payload, 'ion');
        self::assertSame(201, $this->code());
        self::assertSame('9999999999.99', $r['costEstimate']);
        foreach (['ISSUE', 'PROJECT'] as $type) {
            $this->call('POST', '/api/civic_subjects', array_replace($payload, ['type' => $type]), 'admin');
            self::assertSame(422, $this->code());
        }
        $this->call('POST', '/api/civic_subjects', array_replace($payload, ['costEstimate' => '10000000000']), 'ion');
        self::assertSame(422, $this->code());
        $issue = $this->create('ion', 'Cluj')['id'];
        $this->patch($issue, ['costEstimate' => '10', 'costCurrency' => 'RON', 'costEstimateScope' => 'Total'], 'ion');
        self::assertSame(422, $this->code());
    }

    public function testPrivateNoteAccessIsolationAndDeletion(): void
    {
        $id = $this->create('ion', 'Cluj', 'PROPOSAL')['id'];
        $url = '/api/civic_subjects/'.$id.'/note';
        $r = $this->call('GET', $url, null, 'ion');
        self::assertSame(200, $this->code());
        self::assertSame(['technicalNotes' => ''], $r);
        $r = $this->call('PUT', $url, ['technicalNotes' => 'Senzori si alimentare', 'author' => 'admin'], 'ion', 'application/json');
        self::assertSame(200, $this->code());
        self::assertSame('Senzori si alimentare', $r['technicalNotes']);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        foreach ([null, 'maria', 'admin'] as $who) {
            foreach (['GET', 'PUT', 'DELETE'] as $method) {
                $this->call($method, $url, $method === 'PUT' ? ['technicalNotes' => 'Alterat'] : null, $who, 'application/json');
                self::assertSame($who === null ? 401 : 404, $this->code(), "$method $who");
            }
        }
        foreach ([null, 'ion', 'admin'] as $who) {
            foreach (['/api/civic_subjects', '/api/civic_subjects/'.$id] as $publicUrl) {
                $r = $this->call('GET', $publicUrl, null, $who);
                self::assertSame(200, $this->code());
                self::assertStringNotContainsString('technicalNotes', json_encode($r));
                self::assertStringNotContainsString('Senzori si alimentare', json_encode($r));
            }
        }
        $this->call('PUT', $url, ['technicalNotes' => str_repeat('x', 10001)], 'ion', 'application/json');
        self::assertSame(422, $this->code());
        $r = $this->call('DELETE', $url, null, 'ion');
        self::assertSame(200, $this->code());
        self::assertSame(['technicalNotes' => ''], $r);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM subject_private_note'));
    }

    public function testTypeChangePreservesButHidesNoteAndEstimate(): void
    {
        $id = $this->create('ion', 'Cluj', 'PROPOSAL')['id'];
        $estimate = ['costEstimate' => '940.00', 'costCurrency' => 'EUR', 'costEstimateScope' => 'Pentru un loc'];
        $this->patch($id, $estimate, 'ion');
        $url = '/api/civic_subjects/'.$id.'/note';
        $this->call('PUT', $url, ['technicalNotes' => 'Memo privat'], 'ion', 'application/json');
        $r = $this->patch($id, ['type' => 'ISSUE'], 'ion');
        self::assertSame(200, $this->code());
        self::assertNull($r['costEstimate']);
        $this->call('GET', $url, null, 'ion');
        self::assertSame(404, $this->code());
        $this->call('PUT', $url, ['technicalNotes' => 'Alterare'], 'ion', 'application/json');
        self::assertSame(404, $this->code());
        $list = $this->call('GET', '/api/civic_subjects');
        self::assertNull($list['member'][0]['costCurrency']);
        $r = $this->patch($id, ['type' => 'PROPOSAL'], 'ion');
        self::assertSame(200, $this->code());
        foreach ($estimate as $key => $value) self::assertSame($value, $r[$key]);
        $r = $this->call('GET', $url, null, 'ion');
        self::assertSame('Memo privat', $r['technicalNotes']);
    }

    public function testPersonalNoteExportAndDeletionIncludePreservedNotes(): void
    {
        $id = $this->create('ion', 'Cluj', 'PROPOSAL')['id'];
        $this->call('PUT', '/api/civic_subjects/'.$id.'/note', ['technicalNotes' => 'Nota lui Ion'], 'ion', 'application/json');
        $other = $this->create('maria', 'Cluj', 'PROPOSAL')['id'];
        $this->call('PUT', '/api/civic_subjects/'.$other.'/note', ['technicalNotes' => 'Nota Mariei'], 'maria', 'application/json');
        $this->patch($id, ['type' => 'ISSUE'], 'ion');
        $r = $this->call('GET', '/api/me/subject-notes', null, 'ion');
        self::assertSame(200, $this->code());
        self::assertCount(1, $r['notes']);
        self::assertSame($id, $r['notes'][0]['subjectId']);
        self::assertSame('Nota lui Ion', $r['notes'][0]['technicalNotes']);
        $r = $this->call('GET', '/api/me/subject-notes', null, 'admin');
        self::assertSame([], $r['notes']);
        $this->call('GET', '/api/me/subject-notes');
        self::assertSame(401, $this->code());
        $this->call('DELETE', '/api/me/subject-notes', null, 'ion');
        self::assertSame(200, $this->code());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM subject_private_note'));
        $r = $this->call('GET', '/api/me/subject-notes', null, 'ion');
        self::assertSame([], $r['notes']);
        $r = $this->call('GET', '/api/civic_subjects/'.$other.'/note', null, 'maria');
        self::assertSame('Nota Mariei', $r['technicalNotes']);
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
