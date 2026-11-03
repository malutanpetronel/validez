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

/** Step P (ADR-0013): sondaje — creare, validare, blocare la primul vot, închidere, rezultate ascunse până la vot. */
final class PollTest extends WebTestCase
{
    use AltchaPayload;

    private const PAROLA = 'parola-de-test-123';
    private const OPTIONS = ['Dimineața', 'La prânz', 'Seara'];

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
        $users = ['admin' => new User('admin@validez.test', 'Admina', [User::ROLE_ADMIN]), 'ion' => new User('ion@validez.test', 'Ion'), 'maria' => new User('maria@validez.test', 'Maria')];
        foreach ($users as $u) {
            $u->setPassword($hasher->hashPassword($u, self::PAROLA));
            $em->persist($u);
        }
        $this->node = new TreeNode('Sondaje', $users['admin'], null, 0);
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

    private function code(): int { return $this->client->getResponse()->getStatusCode(); }

    private function future(string $modify = '+7 days'): string
    {
        return (new \DateTimeImmutable($modify))->format(\DATE_ATOM);
    }

    /** @param array<string, mixed> $extra */
    private function create(array $extra = [], string $who = 'admin'): array
    {
        return $this->call('POST', '/api/civic_subjects', $extra + [
            'node' => $this->node->getId()->toBase32(),
            'type' => 'POLL',
            'title' => 'Când e bine să se ridice gunoiul?',
            'description' => 'Sondaj pentru cartier',
            'pollOptions' => self::OPTIONS,
            'pollClosesAt' => $this->future(),
        ], $who, 'application/ld+json');
    }

    private function poll(): string
    {
        $r = $this->create();
        self::assertSame(201, $this->code(), json_encode($r));

        return $r['id'];
    }

    private function patch(string $id, array $body, string $who = 'admin'): array
    {
        return $this->call('PATCH', "/api/civic_subjects/$id", $body, $who, 'application/merge-patch+json');
    }

    private function consent(string $who): void
    {
        $this->call('PUT', '/api/me/vote-consent', ['noticeVersion' => $this->notice], $who);
        self::assertSame(200, $this->code());
    }

    private function vote(string $id, mixed $value, string $who): array
    {
        return $this->call('PUT', "/api/civic_subjects/$id/votes/CHOICE", ['value' => $value], $who);
    }

    /** @return array<string, mixed> tipul CHOICE din sumar */
    private function choice(string $id, ?string $who): array
    {
        $r = $this->call('GET', "/api/civic_subjects/$id/votes", null, $who);
        self::assertSame(200, $this->code());
        self::assertSame(['CHOICE'], array_column($r['types'], 'code'));

        return $r['types'][0];
    }

    private function uuid(string $id): string { return Ulid::fromString($id)->toRfc4122(); }

    public function testAdminCreatesPollWithOptionsAndClosingDate(): void
    {
        $r = $this->create(['pollOptions' => ['  Dimineața ', 'La   prânz', 'Seara']]);
        self::assertSame(201, $this->code());
        self::assertSame('POLL', $r['type']);
        self::assertSame(self::OPTIONS, $r['pollOptions'], 'spațiile sunt normalizate');
        self::assertNotNull($r['pollClosesAt']);
        self::assertFalse($r['pollClosed']);

        $choice = $this->choice($r['id'], null);
        self::assertSame([0, 2, self::OPTIONS], [$choice['minValue'], $choice['maxValue'], $choice['labels']]);
        $revision = $this->call('GET', "/api/civic_subjects/{$r['id']}/revisions")['revisions'][0];
        self::assertSame(self::OPTIONS, $revision['pollOptions']);

        // Celelalte tipuri nu au câmpurile de sondaj.
        $issue = $this->call('POST', '/api/civic_subjects', ['node' => $this->node->getId()->toBase32(), 'type' => 'ISSUE', 'title' => 'Groapă', 'description' => 'DN1'], 'admin', 'application/ld+json');
        self::assertNull($issue['pollOptions']);
        self::assertNull($issue['pollClosesAt']);
    }

    public function testOnlyAdminsCreatePolls(): void
    {
        $this->create([], 'ion');
        self::assertSame(403, $this->code());
    }

    public function testPollValidation(): void
    {
        $cases = [
            'fără variante' => ['pollOptions' => null],
            'o singură variantă' => ['pollOptions' => ['Da']],
            '21 de variante' => ['pollOptions' => array_map(static fn (int $i) => "V$i", range(1, 21))],
            'duplicat' => ['pollOptions' => ['Da', 'da ', 'Nu']],
            'variantă goală' => ['pollOptions' => ['Da', '   ']],
            'variantă prea lungă' => ['pollOptions' => ['Da', str_repeat('x', 121)]],
            'fără dată' => ['pollClosesAt' => null],
            'dată în trecut' => ['pollClosesAt' => $this->future('-1 hour')],
            'dată fără fus orar' => ['pollClosesAt' => '2030-01-01T10:00:00'],
        ];
        foreach ($cases as $label => $extra) {
            $this->create($extra);
            self::assertSame(422, $this->code(), $label);
        }
        $this->create(['pollClosesAt' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i:s.v\Z')]);
        self::assertSame(201, $this->code(), 'formatul toISOString din browser');

        $this->call('POST', '/api/civic_subjects', ['node' => $this->node->getId()->toBase32(), 'type' => 'ISSUE', 'title' => 'Groapă', 'description' => 'DN1', 'pollOptions' => ['A', 'B']], 'admin', 'application/ld+json');
        self::assertSame(422, $this->code(), 'variantele există doar la sondaje');
    }

    public function testPollTypeCannotChangeEitherWay(): void
    {
        $id = $this->poll();
        $this->patch($id, ['type' => 'ISSUE']);
        self::assertSame(422, $this->code());

        $issue = $this->call('POST', '/api/civic_subjects', ['node' => $this->node->getId()->toBase32(), 'type' => 'ISSUE', 'title' => 'Groapă', 'description' => 'DN1'], 'admin', 'application/ld+json');
        $this->patch($issue['id'], ['type' => 'POLL', 'pollOptions' => ['A', 'B'], 'pollClosesAt' => $this->future()]);
        self::assertSame(422, $this->code());
    }

    public function testOptionsEditableBeforeFirstVoteAndLockedAfter(): void
    {
        $id = $this->poll();
        $r = $this->patch($id, ['pollOptions' => ['Dimineața', 'Seara', 'Noaptea', 'Nu contează']]);
        self::assertSame(200, $this->code());
        self::assertSame(2, $r['revisionNumber'], 'variantele fac parte din revizie');

        $this->consent('ion');
        $this->vote($id, 3, 'ion');
        self::assertSame(200, $this->code());

        foreach ([['pollOptions' => ['A', 'B']], ['title' => 'Altă întrebare'], ['description' => 'Altă descriere']] as $body) {
            $this->patch($id, $body);
            self::assertSame(409, $this->code(), json_encode($body));
        }
        self::assertSame(['Dimineața', 'Seara', 'Noaptea', 'Nu contează'], $this->call('GET', "/api/civic_subjects/$id")['pollOptions']);

        // Data de închidere și stadiul rămân editabile; nu creează revizie.
        $r = $this->patch($id, ['pollClosesAt' => $this->future('+30 days')]);
        self::assertSame(200, $this->code());
        self::assertSame(2, $r['revisionNumber']);
        $this->patch($id, ['pollClosesAt' => $this->future('-1 day')]);
        self::assertSame(422, $this->code(), 'data nouă tot în viitor');
    }

    public function testVoteValueMustBeOneOfTheOptions(): void
    {
        $id = $this->poll();
        $this->consent('ion');
        foreach ([3, 19, -1] as $value) {
            $r = $this->vote($id, $value, 'ion');
            self::assertSame([422, 'value_out_of_range'], [$this->code(), $r['reason']], (string) $value);
        }
        self::assertSame('VOTE', $this->vote($id, 2, 'ion')['action']);
        self::assertSame('CHANGE', $this->vote($id, 0, 'ion')['action'], 'un singur răspuns, schimbabil cât e deschis');
    }

    public function testResultsHiddenUntilOwnVoteThenPublicAfterClosing(): void
    {
        $id = $this->poll();
        $this->consent('ion');
        $this->consent('admin');
        $this->vote($id, 1, 'ion');

        foreach (['vizitator' => null, 'utilizator fără vot' => 'maria', 'admin fără vot' => 'admin'] as $label => $who) {
            $choice = $this->choice($id, $who);
            self::assertTrue($choice['result']['hidden'], $label);
            self::assertSame([], (array) $choice['result']['counts'], $label);
            self::assertSame(1, $choice['result']['total'], "$label: totalul participanților e public");
        }

        $choice = $this->choice($id, 'ion');
        self::assertFalse($choice['result']['hidden']);
        self::assertSame(['1' => 1], (array) $choice['result']['counts']);

        $r = $this->vote($id, 2, 'admin');
        self::assertSame(['1' => 1, '2' => 1], (array) $r['types'][0]['result']['counts'], 'răspunsul la vot arată rezultatele');

        // Retragerea votului ascunde din nou rezultatele.
        $this->call('DELETE', "/api/civic_subjects/$id/votes/CHOICE", null, 'admin');
        self::assertTrue($this->choice($id, 'admin')['result']['hidden']);

        // Închis prin dată: rezultate publice, vot refuzat, retragerea rămâne posibilă.
        $this->db->executeStatement("UPDATE civic_subject SET poll_closes_at = NOW() - INTERVAL '1 minute' WHERE id = ?", [$this->uuid($id)]);
        $choice = $this->choice($id, null);
        self::assertFalse($choice['result']['hidden']);
        self::assertSame(['1' => 1], (array) $choice['result']['counts']);
        self::assertTrue($this->call('GET', "/api/civic_subjects/$id")['pollClosed']);
        $r = $this->vote($id, 0, 'ion');
        self::assertSame([409, 'subject_frozen'], [$this->code(), $r['reason']]);
        $this->patch($id, ['pollClosesAt' => $this->future()]);
        self::assertSame(409, $this->code(), 'un sondaj închis nu se redeschide prin dată');
        self::assertSame(['withdrawn' => true], $this->call('DELETE', "/api/civic_subjects/$id/votes/CHOICE", null, 'ion'));
    }

    public function testClosedStageClosesPollBeforeDate(): void
    {
        $id = $this->poll();
        $this->consent('ion');
        $this->vote($id, 0, 'ion');
        $this->patch($id, ['stage' => 'CLOSED']);
        self::assertSame(200, $this->code());
        self::assertFalse($this->choice($id, 'maria')['result']['hidden']);
        $this->vote($id, 1, 'ion');
        self::assertSame(409, $this->code());
    }

    public function testDatabaseRejectsInconsistentPollRows(): void
    {
        $id = $this->poll();
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->db->executeStatement('UPDATE civic_subject SET poll_closes_at = NULL WHERE id = ?', [$this->uuid($id)]);
    }
}
