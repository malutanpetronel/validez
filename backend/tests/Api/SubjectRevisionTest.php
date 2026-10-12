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
use Symfony\Component\Uid\Ulid;

/** Step 4.b2 (ADR-0007 §3): revizii la creare/editare, tipuri implicite, blocarea tipului, agregate la revizie nouă. */
final class SubjectRevisionTest extends WebTestCase
{
    use AltchaPayload;

    private const PAROLA = 'parola-de-test-123';

    private KernelBrowser $client;
    private Connection $db;
    private TreeNode $node;
    /** @var array<string, User> */
    private array $users = [];
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $c = static::getContainer();
        $c->get('limiter.captcha_challenge')->create('127.0.0.1')->reset();
        $this->db = $c->get(Connection::class);
        Database::reset($this->db);
        $em = $c->get(EntityManagerInterface::class);
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $this->users = ['admin' => new User('admin@validez.test', 'Admina', [User::ROLE_ADMIN]), 'ion' => new User('ion@validez.test', 'Ion')];
        foreach ($this->users as $u) {
            $u->setPassword($hasher->hashPassword($u, self::PAROLA));
            $em->persist($u);
        }
        $this->node = new TreeNode('Drumuri', $this->users['admin'], null, 0);
        $em->persist($this->node);
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
        if ($method === 'POST' && $uri === '/api/auth') {
            $body['altcha'] = $this->solveAltcha($this->client);
        }
        $server = ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => 'application/ld+json'];
        if ($who !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token($who);
        }
        $this->client->request($method, $uri, server: $server, content: $body === null ? null : json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function code(): int { return $this->client->getResponse()->getStatusCode(); }

    private function create(string $who, string $type, array $extra = []): string
    {
        $r = $this->call('POST', '/api/civic_subjects', ['node' => $this->node->getId()->toBase32(), 'type' => $type, 'title' => 'Titlu', 'description' => 'Descriere'] + $extra, $who);
        self::assertSame(201, $this->code(), json_encode($r));

        return $r['id'];
    }

    private function patch(string $id, array $body, string $who): array
    {
        return $this->call('PATCH', '/api/civic_subjects/'.$id, $body, $who, 'application/merge-patch+json');
    }

    private function uuid(string $id): string { return Ulid::fromString($id)->toRfc4122(); }

    /** @return list<array<string, mixed>> */
    private function revisions(string $id): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT r.number, r.type, r.title, r.description, r.cost_estimate, r.cost_currency, r.cost_estimate_scope, u.email AS created_by
             FROM subject_revision r JOIN app_user u ON u.id = r.created_by WHERE r.subject_id = ? ORDER BY r.number',
            [$this->uuid($id)],
        );
    }

    private function revisionNumber(string $id): int
    {
        return (int) $this->db->fetchOne('SELECT revision_number FROM civic_subject WHERE id = ?', [$this->uuid($id)]);
    }

    /** @return list<string> */
    private function voteTypes(string $id): array
    {
        return $this->db->fetchFirstColumn('SELECT vote_type_code FROM subject_vote_type WHERE subject_id = ? ORDER BY vote_type_code', [$this->uuid($id)]);
    }

    public function testCreationStoresRevisionOneAndDefaultVoteTypes(): void
    {
        $expected = ['ISSUE' => ['IMPORTANCE', 'VALIDITY'], 'PROPOSAL' => ['IMPORTANCE', 'YES_NO'], 'PETITION' => ['YES_NO'], 'PROMISE' => []];
        foreach ($expected as $type => $codes) {
            $id = $this->create('admin', $type);
            self::assertSame($codes, $this->voteTypes($id), $type);
            $revisions = $this->revisions($id);
            self::assertCount(1, $revisions);
            self::assertSame([1, $type, 'Titlu', 'Descriere', 'admin@validez.test'], [$revisions[0]['number'], $revisions[0]['type'], $revisions[0]['title'], $revisions[0]['description'], $revisions[0]['created_by']]);
            self::assertSame(1, $this->revisionNumber($id));
        }

        $proposal = $this->create('admin', 'PROPOSAL', ['costEstimate' => '970', 'costCurrency' => 'EUR', 'costEstimateScope' => 'Kiss & Ride']);
        self::assertSame(['970.00', 'EUR', 'Kiss & Ride'], [$this->revisions($proposal)[0]['cost_estimate'], $this->revisions($proposal)[0]['cost_currency'], $this->revisions($proposal)[0]['cost_estimate_scope']]);
    }

    public function testEffectiveChangesCreateRevisionsAndIdenticalSavesDoNot(): void
    {
        $id = $this->create('admin', 'PROPOSAL');

        $this->patch($id, ['title' => 'Titlu', 'description' => 'Descriere'], 'admin');
        self::assertSame(200, $this->code());
        $this->patch($id, ['stage' => 'IN_PROGRESS'], 'admin');
        self::assertSame(200, $this->code());
        self::assertCount(1, $this->revisions($id), 'salvare identică / doar stadiu: fără revizie');

        $this->patch($id, ['title' => 'Titlu nou'], 'admin');
        self::assertSame(2, $this->revisionNumber($id));

        // Aceeași sumă scrisă altfel se normalizează la aceeași valoare: o singură revizie pentru estimare.
        $this->patch($id, ['costEstimate' => '970', 'costCurrency' => 'EUR', 'costEstimateScope' => 'Kiss & Ride'], 'admin');
        $this->patch($id, ['costEstimate' => '970.00'], 'admin');
        self::assertSame(3, $this->revisionNumber($id));

        // Tipul face parte din instantaneu: schimbarea lui înaintea primului vot creează revizie.
        $this->patch($id, ['type' => 'ISSUE'], 'admin');
        self::assertSame(200, $this->code());
        $revisions = $this->revisions($id);
        self::assertSame([1, 2, 3, 4], array_map('intval', array_column($revisions, 'number')));
        self::assertSame(['PROPOSAL', 'PROPOSAL', 'PROPOSAL', 'ISSUE'], array_column($revisions, 'type'));
        self::assertSame('Titlu nou', $revisions[3]['title']);
        self::assertSame('970.00', $revisions[3]['cost_estimate'], 'estimarea brută rămâne în instantaneu');
        self::assertSame(4, $this->revisionNumber($id));
    }

    public function testRevisionAuthorIsTheEditor(): void
    {
        $id = $this->create('ion', 'ISSUE');
        $this->patch($id, ['visibility' => 'PUBLISHED'], 'admin');
        $this->patch($id, ['description' => 'Descriere corectată de admin'], 'admin');
        self::assertSame(['ion@validez.test', 'admin@validez.test'], array_column($this->revisions($id), 'created_by'));
    }

    public function testModeratedAuthorEditGoesBackToPendingWithNewRevision(): void
    {
        $id = $this->create('ion', 'ISSUE');
        $this->patch($id, ['visibility' => 'PUBLISHED'], 'admin');
        self::assertCount(1, $this->revisions($id), 'aprobarea nu schimbă instantaneul');

        $r = $this->patch($id, ['title' => 'Titlu schimbat de autor'], 'ion');
        self::assertSame(200, $this->code());
        self::assertSame('PENDING', $r['visibility']);
        self::assertSame(2, $this->revisionNumber($id));
    }

    public function testTypeIsLockedAfterFirstVoteForEveryone(): void
    {
        $id = $this->create('admin', 'ISSUE');
        $this->db->executeStatement('UPDATE civic_subject SET first_voted_at = NOW() WHERE id = ?', [$this->uuid($id)]);

        foreach (['admin', 'ion'] as $who) {
            if ($who === 'ion') {
                $this->db->executeStatement('UPDATE civic_subject SET author_id = ? WHERE id = ?', [$this->users['ion']->getId()->toRfc4122(), $this->uuid($id)]);
            }
            $r = $this->patch($id, ['type' => 'PROPOSAL'], $who);
            self::assertSame(422, $this->code(), $who);
            self::assertStringContainsString('după primul vot', json_encode($r, JSON_UNESCAPED_UNICODE));
        }
        self::assertCount(1, $this->revisions($id));

        // Același tip în payload nu e o schimbare; titlul rămâne editabil.
        $this->patch($id, ['type' => 'ISSUE', 'title' => 'Titlu după vot'], 'admin');
        self::assertSame(200, $this->code());
        self::assertSame(2, $this->revisionNumber($id));
    }

    public function testNewRevisionTurnsCurrentVotesIntoUnconfirmedOnes(): void
    {
        $id = $this->create('admin', 'ISSUE');
        $revision1 = $this->db->fetchOne('SELECT id FROM subject_revision WHERE subject_id = ?', [$this->uuid($id)]);
        foreach ([['admin', 'VALIDITY', 1], ['ion', 'VALIDITY', 0], ['ion', 'IMPORTANCE', 4]] as [$who, $type, $value]) {
            $this->db->executeStatement(
                'INSERT INTO vote (id, user_id, subject_id, vote_type_code, revision_id, value, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [(new Ulid())->toRfc4122(), $this->users[$who]->getId()->toRfc4122(), $this->uuid($id), $type, $revision1, $value],
            );
        }

        $this->patch($id, ['description' => 'Descriere modificată după vot'], 'admin');
        self::assertSame(200, $this->code());

        $results = $this->db->fetchAllAssociativeIndexed('SELECT vote_type_code, current_counts::text AS counts, current_total, stale_total FROM vote_result WHERE subject_id = ?', [$this->uuid($id)]);
        $types = array_keys($results);
        sort($types);
        self::assertSame(['IMPORTANCE', 'VALIDITY'], $types);
        self::assertSame(['{}', 0, 2], [$results['VALIDITY']['counts'], (int) $results['VALIDITY']['current_total'], (int) $results['VALIDITY']['stale_total']]);
        self::assertSame(['{}', 0, 1], [$results['IMPORTANCE']['counts'], (int) $results['IMPORTANCE']['current_total'], (int) $results['IMPORTANCE']['stale_total']]);
    }
}
