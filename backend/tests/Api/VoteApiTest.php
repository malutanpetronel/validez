<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\TreeNode;
use App\Entity\User;
use App\Tests\Support\AltchaPayload;
use App\Tests\Support\Database;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Ulid;

/** Step 4.b4 (ADR-0007): API-ul de vot, consimțământul, exportul, limitarea și app:votes:verify. */
final class VoteApiTest extends WebTestCase
{
    use AltchaPayload;

    private const PAROLA = 'parola-de-test-123';

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
        $this->node = new TreeNode('Drumuri', $users['admin'], null, 0);
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

    private function assertNoStore(): void
    {
        self::assertStringContainsString('no-store', (string) $this->header('Cache-Control'));
        self::assertStringContainsString('private', (string) $this->header('Cache-Control'));
    }

    private function raw(): string { return (string) $this->client->getResponse()->getContent(); }
    private function code(): int { return $this->client->getResponse()->getStatusCode(); }
    private function header(string $name): ?string { return $this->client->getResponse()->headers->get($name); }

    private function subject(string $type = 'ISSUE'): string
    {
        $r = $this->call('POST', '/api/civic_subjects', ['node' => $this->node->getId()->toBase32(), 'type' => $type, 'title' => 'Groapă', 'description' => 'Pe DN1'], 'admin', 'application/ld+json');
        self::assertSame(201, $this->code());

        return $r['id'];
    }

    private function consent(string $who): void
    {
        $this->call('PUT', '/api/me/vote-consent', ['noticeVersion' => $this->notice], $who);
        self::assertSame(200, $this->code());
    }

    private function vote(string $id, string $type, mixed $value, ?string $who): array
    {
        return $this->call('PUT', "/api/civic_subjects/$id/votes/$type", ['value' => $value], $who);
    }

    private function verify(): array
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:votes:verify'));
        $code = $tester->execute([]);

        return [$code, $tester->getDisplay()];
    }

    public function testVisitorSeesAggregatesOnlyAndCannotVote(): void
    {
        $id = $this->subject();
        $this->consent('ion');
        $this->vote($id, 'VALIDITY', 1, 'ion');
        $this->vote($id, 'VALIDITY', 0, 'maria'); // fără consimțământ → refuzat

        $r = $this->call('GET', "/api/civic_subjects/$id/votes");
        self::assertSame(200, $this->code());
        self::assertStringNotContainsString('no-store', (string) $this->header('Cache-Control'), 'răspunsul pentru vizitator nu conține date personale');
        self::assertNull($r['consent']);
        self::assertSame(['IMPORTANCE', 'VALIDITY'], array_column($r['types'], 'code'));
        self::assertSame([null, null], array_column($r['types'], 'mine'));
        self::assertStringContainsString('"counts":{"1":1}', $this->raw(), 'contoarele sunt obiect JSON, nu listă');
        self::assertStringContainsString('"counts":{}', $this->raw(), 'contorul gol e {} , nu []');
        self::assertTrue($r['votingOpen']);

        $this->vote($id, 'VALIDITY', 1, null);
        self::assertSame(401, $this->code());
    }

    public function testConsentFlowAndVoteResponses(): void
    {
        $id = $this->subject();
        $r = $this->vote($id, 'VALIDITY', 1, 'ion');
        self::assertSame([403, 'consent_required'], [$this->code(), $r['reason']]);

        $r = $this->call('PUT', '/api/me/vote-consent', ['noticeVersion' => 'alta'], 'ion');
        self::assertSame([422, 'notice_version_mismatch'], [$this->code(), $r['reason']]);
        $this->consent('ion');
        self::assertSame(['active' => true, 'current' => true, 'noticeVersion' => $this->notice, 'currentNoticeVersion' => $this->notice], $this->call('GET', '/api/me/vote-consent', null, 'ion'));
        $this->assertNoStore();

        $r = $this->vote($id, 'VALIDITY', 1, 'ion');
        self::assertSame([200, 'VOTE'], [$this->code(), $r['action']]);
        $this->assertNoStore();
        $validity = $r['types'][1];
        self::assertSame(['value' => 1, 'revisionNumber' => 1, 'current' => true], $validity['mine']);
        self::assertSame(1, $validity['result']['total']);
        self::assertNull($this->vote($id, 'VALIDITY', 1, 'ion')['action']);
        self::assertSame('CHANGE', $this->vote($id, 'VALIDITY', 0, 'ion')['action']);

        foreach ([['VALIDITY', 'da', 422, 'invalid_body'], ['VALIDITY', 2, 422, 'value_out_of_range'], ['YES_NO', 1, 409, 'type_not_active'], ['NECUNOSCUT', 1, 409, 'type_not_active']] as [$type, $value, $status, $reason]) {
            $r = $this->vote($id, $type, $value, 'ion');
            self::assertSame([$status, $reason], [$this->code(), $r['reason']], "$type=$value");
        }

        $this->call('PATCH', "/api/civic_subjects/$id", ['stage' => 'CLOSED'], 'admin', 'application/merge-patch+json');
        $r = $this->vote($id, 'VALIDITY', 1, 'ion');
        self::assertSame([409, 'subject_frozen'], [$this->code(), $r['reason']]);
        self::assertSame(['withdrawn' => true], $this->call('DELETE', "/api/civic_subjects/$id/votes/VALIDITY", null, 'ion'));
        self::assertSame(['withdrawn' => false], $this->call('DELETE', "/api/civic_subjects/$id/votes/VALIDITY", null, 'ion'));
    }

    public function testRevisionMakesOwnVoteUnconfirmedAndSubjectExposesLock(): void
    {
        $id = $this->subject();
        $this->consent('ion');
        $this->vote($id, 'VALIDITY', 1, 'ion');
        $subject = $this->call('PATCH', "/api/civic_subjects/$id", ['description' => 'Pe DN1, km 12'], 'admin', 'application/merge-patch+json');
        self::assertSame([2, true], [$subject['revisionNumber'], $subject['typeLocked']]);

        $r = $this->call('GET', "/api/civic_subjects/$id/votes", null, 'ion');
        self::assertSame(['value' => 1, 'revisionNumber' => 1, 'current' => false], $r['types'][1]['mine']);
        self::assertSame([0, 1], [$r['types'][1]['result']['total'], $r['types'][1]['result']['staleTotal']]);
        self::assertSame('RECONFIRM', $this->vote($id, 'VALIDITY', 1, 'ion')['action']);

        $revisions = $this->call('GET', "/api/civic_subjects/$id/revisions");
        self::assertSame([1, 2], array_column($revisions['revisions'], 'number'));
        self::assertSame(['Pe DN1', 'Pe DN1, km 12'], array_column($revisions['revisions'], 'description'));
        self::assertSame([true, true], array_column($revisions['revisions'], 'byAuthor'), 'adminul e și autorul aici');
    }

    public function testRevisionsHideEstimateOnIneligibleTypes(): void
    {
        $id = $this->subject('PROPOSAL');
        $this->call('PATCH', "/api/civic_subjects/$id", ['costEstimate' => '970', 'costCurrency' => 'EUR', 'costEstimateScope' => 'Kiss & Ride'], 'admin', 'application/merge-patch+json');
        $this->call('PATCH', "/api/civic_subjects/$id", ['type' => 'ISSUE'], 'admin', 'application/merge-patch+json');
        $revisions = $this->call('GET', "/api/civic_subjects/$id/revisions")['revisions'];
        self::assertSame([null, ['costEstimate' => '970.00', 'costCurrency' => 'EUR', 'costEstimateScope' => 'Kiss & Ride'], null], array_column($revisions, 'estimate'));
    }

    public function testHiddenSubjectAllowsOnlyBareWithdrawal(): void
    {
        $id = $this->subject();
        $this->consent('ion');
        $this->vote($id, 'VALIDITY', 1, 'ion');
        $this->vote($id, 'IMPORTANCE', 4, 'ion');
        $this->call('PATCH', "/api/civic_subjects/$id", ['visibility' => 'HIDDEN'], 'admin', 'application/merge-patch+json');

        $this->call('GET', "/api/civic_subjects/$id/votes", null, 'ion');
        self::assertSame(404, $this->code());
        $this->call('GET', "/api/civic_subjects/$id/revisions", null, 'ion');
        self::assertSame(404, $this->code());
        $this->vote($id, 'VALIDITY', 0, 'ion');
        self::assertSame(404, $this->code());

        self::assertSame(['withdrawn' => true], $this->call('DELETE', "/api/civic_subjects/$id/votes/VALIDITY", null, 'ion'), 'doar confirmarea, fără rezultate');
        $this->call('DELETE', "/api/civic_subjects/$id/votes/VALIDITY", null, 'ion');
        self::assertSame(404, $this->code(), 'fără vot pe un subiect invizibil: 404');
        $this->call('DELETE', "/api/civic_subjects/$id/votes/IMPORTANCE", null, 'maria');
        self::assertSame(404, $this->code());
        self::assertSame(['withdrawn' => true], $this->call('DELETE', "/api/civic_subjects/$id/votes/IMPORTANCE", null, 'ion'));
    }

    public function testExportLetsTheUserVerifyOwnCommitments(): void
    {
        $id = $this->subject();
        $this->consent('ion');
        $this->consent('maria');
        $this->vote($id, 'VALIDITY', 1, 'ion');
        $this->vote($id, 'VALIDITY', 0, 'maria');
        $this->vote($id, 'VALIDITY', 0, 'ion');
        $this->call('DELETE', "/api/civic_subjects/$id/votes/VALIDITY", null, 'ion');

        $export = $this->call('GET', '/api/me/votes', null, 'ion');
        $this->assertNoStore();
        self::assertSame(['VOTE', 'CHANGE', 'WITHDRAW'], array_column($export['history'], 'action'), 'doar evenimentele proprii');
        self::assertSame([], $export['votes']);

        // Verificare independentă, doar din export: codarea descrisă în `format`, fără codul aplicației.
        $field = static fn (?string $v): string => $v === null ? "\x00" : "\x01".pack('N', \strlen($v)).$v;
        foreach ($export['history'] as $entry) {
            $c = $entry['commitment'];
            $bytes = $field($c['domain']);
            foreach ($c['fields'] as $f) {
                $bytes .= $field($f === null ? null : (string) $f);
            }
            $bytes .= $field(hex2bin($c['nonce']));
            self::assertSame($c['commitment'], hash('sha256', $bytes), $entry['action']);
            $stored = $this->db->fetchOne("SELECT encode(commitment, 'hex') FROM integrity_event_log WHERE encode(event_hash, 'hex') = ?", [$entry['event']['eventHash']]);
            self::assertSame($c['commitment'], $stored, 'commitmentul din export e cel din jurnal');
        }
        self::assertNull($export['history'][2]['commitment']['fields'][5], 'retragerea are starea rezultată nulă');
    }

    public function testOutdatedNoticeBlocksVotingButVerifyAcceptsExistingVotes(): void
    {
        $id = $this->subject();
        $this->consent('ion');
        $this->vote($id, 'VALIDITY', 1, 'ion');
        $this->db->executeStatement("UPDATE vote_consent SET notice_version = 'veche'");

        $r = $this->vote($id, 'VALIDITY', 0, 'ion');
        self::assertSame([403, 'consent_required'], [$this->code(), $r['reason']]);
        self::assertFalse($this->call('GET', '/api/me/vote-consent', null, 'ion')['current']);
        self::assertSame(['withdrawn' => true], $this->call('DELETE', "/api/civic_subjects/$id/votes/VALIDITY", null, 'ion'), 'retragerea nu cere versiunea curentă');
        $this->vote($id, 'VALIDITY', 1, 'ion');
        self::assertSame(403, $this->code());
        $this->consent('ion');
        self::assertSame('VOTE', $this->vote($id, 'VALIDITY', 1, 'ion')['action']);
        $this->db->executeStatement("UPDATE vote_consent SET notice_version = 'veche' WHERE withdrawn_at IS NULL");
        self::assertSame(0, $this->verify()[0], 'acordul pe versiune veche nu invalidează voturile existente');
    }

    public function testRateLimitOnWritesButConsentWithdrawalStaysAvailable(): void
    {
        $id = $this->subject();
        $this->consent('ion'); // 1 operație de scriere
        $statuses = [];
        for ($i = 0; $i < 31; ++$i) {
            $this->vote($id, 'VALIDITY', $i % 2, 'ion');
            $statuses[] = $this->code();
        }
        self::assertSame(29, \count(array_filter($statuses, static fn (int $s): bool => $s === 200)));
        self::assertSame(429, end($statuses));
        self::assertSame('rate_limited', json_decode($this->raw(), true)['reason']);
        self::assertGreaterThanOrEqual(1, (int) $this->header('Retry-After'));
        $this->call('DELETE', "/api/civic_subjects/$id/votes/VALIDITY", null, 'ion');
        self::assertSame(429, $this->code(), 'retragerea votului e tot o scriere limitată');

        $r = $this->call('DELETE', '/api/me/vote-consent', null, 'ion');
        self::assertSame(200, $this->code());
        self::assertSame([1, false], [$r['withdrawnVotes'], $r['active']]);
        self::assertSame(0, $this->verify()[0]);
    }

    public function testVerifyReportsInconsistencies(): void
    {
        $id = $this->subject();
        $this->consent('ion');
        $this->vote($id, 'VALIDITY', 1, 'ion');
        self::assertSame(0, $this->verify()[0]);

        $this->db->executeStatement("UPDATE vote_result SET current_counts = '{\"1\": 5}', current_total = 5");
        $this->db->executeStatement('UPDATE vote_consent SET withdrawn_at = NOW()');
        [$code, $output] = $this->verify();
        self::assertSame(1, $code);
        self::assertStringContainsString('agregat diferit de recalculare', $output);
        self::assertStringContainsString('voturi fără consimțământ activ', $output);
        self::assertStringContainsString(Ulid::fromString($id)->toBase32(), $output);
    }
}
