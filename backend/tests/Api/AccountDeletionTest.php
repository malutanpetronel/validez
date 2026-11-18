<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\CivicSubject;
use App\Entity\SubjectPrivateNote;
use App\Entity\TreeNode;
use App\Entity\User;
use App\Subject\SubjectRevisions;
use App\Subject\SubjectType;
use App\Tests\Support\AltchaPayload;
use App\Tests\Support\Database;
use App\Vote\ErasureRegistry;
use App\Vote\VoteService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** L.5b/L.5c: „Șterge-mi contul” — link pe email valabil o oră, confirmare, „Cont șters”, reaplicare după restore. */
final class AccountDeletionTest extends WebTestCase
{
    use AltchaPayload;

    private const PAROLA = 'parola-de-test-123';

    private KernelBrowser $client;
    private Connection $db;
    private ErasureRegistry $registry;
    private string $ionId;
    private string $subjectId;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $c = static::getContainer();
        $c->get('limiter.captcha_challenge')->create('127.0.0.1')->reset();
        $c->get('cache.rate_limiter')->clear();
        $this->db = $c->get(Connection::class);
        Database::reset($this->db, 'category_suggestion');
        $this->registry = $c->get(ErasureRegistry::class);
        @unlink($this->registry->path());

        $em = $c->get(EntityManagerInterface::class);
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $admin = new User('admin@validez.test', 'Admina', [User::ROLE_ADMIN]);
        $ion = new User('ion@validez.test', 'Ion Popescu');
        foreach ([$admin, $ion] as $u) {
            $u->setPassword($hasher->hashPassword($u, self::PAROLA));
            $em->persist($u);
        }
        $node = new TreeNode('Drumuri', $admin, null, 0);
        $em->persist($node);
        $subject = new CivicSubject($node, $ion, SubjectType::PROPOSAL, 'Parcare nouă', 'Pe strada Mare');
        $subject->assignSlug('parcare-noua');
        $em->persist($subject);
        $c->get(SubjectRevisions::class)->initialize($subject, $ion);
        $em->persist(new SubjectPrivateNote($subject, 'notă privată'));
        $em->flush();
        $this->db->insert('category_suggestion', ['id' => '01890000-0000-7000-8000-000000000001', 'author_id' => $ion->getId()->toRfc4122(),
            'parent_id' => null, 'name' => 'Parcări', 'reason' => 'lipsește', 'status' => 'PENDING', 'created_at' => (new \DateTimeImmutable())->format('c')]);
        $votes = $c->get(VoteService::class);
        $votes->grantConsent($ion, $votes->getCurrentNoticeVersion());
        $votes->cast($ion, $subject, 'IMPORTANCE', 4);
        $this->ionId = $ion->getId()->toRfc4122();
        $this->subjectId = $subject->getId()->toBase32();
    }

    private function token(string $who): string
    {
        if (!isset($this->tokens[$who])) {
            $body = ['email' => "$who@validez.test", 'password' => self::PAROLA, 'altcha' => $this->solveAltcha($this->client)];
            $this->client->request('POST', '/api/auth', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));
            $this->tokens[$who] = json_decode((string) $this->client->getResponse()->getContent(), true)['token'] ?? '';
        }

        return $this->tokens[$who];
    }

    /** @return array<string, mixed> */
    private function post(string $uri, ?string $who, array $body = []): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($who !== null) $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token($who);
        $this->client->request('POST', $uri, server: $server, content: json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** Cere linkul și întoarce tokenul din email. */
    private function requestLink(): string
    {
        $this->post('/api/account/deletion-request', 'ion');
        self::assertSame(200, $this->code());
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        \assert($email instanceof Email);
        self::assertSame('ion@validez.test', $email->getTo()[0]->getAddress());
        self::assertMatchesRegularExpression('#https://validez\.test/sterge-cont/confirmare\?token=[A-Za-z0-9_-]{43}\b#', (string) $email->getTextBody());
        preg_match('#token=([A-Za-z0-9_-]+)#', (string) $email->getTextBody(), $m);

        return $m[1];
    }

    private function user(): array
    {
        return $this->db->fetchAssociative('SELECT email, display_name, roles, deleted_at, account_deletion_token_hash FROM app_user WHERE id = ?', [$this->ionId]);
    }

    public function testAdminulSiVizitatorulNuPotCere(): void
    {
        $this->post('/api/account/deletion-request', null);
        self::assertSame(401, $this->code());
        $r = $this->post('/api/account/deletion-request', 'admin');
        self::assertSame(403, $this->code());
        self::assertStringContainsString('administrator', $r['detail'] ?? $r['message'] ?? '');
        self::assertEmailCount(0);
    }

    public function testStergereaCompleta(): void
    {
        $jwt = $this->token('ion');
        $token = $this->requestLink();
        self::assertNull($this->user()['deleted_at'], 'cererea nu șterge nimic');

        $this->post('/api/account/deletion-confirm', null, ['token' => $token]);
        self::assertSame(200, $this->code());
        self::assertEmailCount(1);
        $final = self::getMailerMessage();
        self::assertSame('ion@validez.test', $final->getTo()[0]->getAddress());
        self::assertStringContainsString('a fost șters', (string) $final->getSubject());

        $u = $this->user();
        self::assertSame(User::DELETED_NAME, $u['display_name']);
        self::assertStringEndsWith('@cont-sters.invalid', $u['email']);
        self::assertSame('[]', $u['roles']);
        self::assertNotNull($u['deleted_at']);
        self::assertNull($u['account_deletion_token_hash']);
        foreach (['vote', 'user_vote_history', 'subject_private_note', 'refresh_token'] as $table) {
            $column = $table === 'refresh_token' ? 'username' : ($table === 'subject_private_note' ? 'author_id' : 'user_id');
            $value = $table === 'refresh_token' ? 'ion@validez.test' : $this->ionId;
            self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM $table WHERE $column = ?", [$value]), $table);
        }
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM category_suggestion WHERE status = 'PENDING'"));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM civic_subject WHERE author_id = ?', [$this->ionId]), 'subiectul rămâne');

        // Tokenul JWT vechi nu mai e valabil, iar parola veche nu mai deschide contul.
        $this->client->request('POST', '/api/account/deletion-request', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$jwt, 'CONTENT_TYPE' => 'application/json']);
        self::assertSame(401, $this->code());
        unset($this->tokens['ion']);
        self::assertSame('', $this->token('ion'));
        // Adresa se eliberează: se poate folosi pentru un cont nou.
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM app_user WHERE email = 'ion@validez.test'"));

        $actions = array_column($this->registry->entries(), 'action');
        self::assertContains(ErasureRegistry::CONSENT_WITHDRAWN, $actions);
        self::assertContains(ErasureRegistry::ACCOUNT_ERASED, $actions);

        // Linkul nu se poate folosi a doua oară.
        $this->post('/api/account/deletion-confirm', null, ['token' => $token]);
        self::assertSame(400, $this->code());
    }

    public function testLinkExpiratGresitSauInlocuit(): void
    {
        $first = $this->requestLink();
        $second = $this->requestLink();
        $this->post('/api/account/deletion-confirm', null, ['token' => $first]);
        self::assertSame(400, $this->code(), 'cererea nouă anulează linkul vechi');
        $this->post('/api/account/deletion-confirm', null, ['token' => 'nu-exista']);
        self::assertSame(400, $this->code());

        $this->db->executeStatement("UPDATE app_user SET account_deletion_requested_at = NOW() - INTERVAL '61 minutes' WHERE id = ?", [$this->ionId]);
        $r = $this->post('/api/account/deletion-confirm', null, ['token' => $second]);
        self::assertSame(400, $this->code());
        self::assertStringContainsString('nu mai este valabil', $r['detail'] ?? '');
        self::assertNull($this->user()['deleted_at']);
    }

    public function testRestaurareaUneiCopiiGolesteDinNouContul(): void
    {
        $this->post('/api/account/deletion-confirm', null, ['token' => $this->requestLink()]);
        self::assertSame(200, $this->code());
        // „Copia restaurată”: rândul contului revine cum era înainte de ștergere.
        $this->db->executeStatement("UPDATE app_user SET email = 'ion@validez.test', display_name = 'Ion Popescu', deleted_at = NULL WHERE id = ?", [$this->ionId]);

        $tester = new CommandTester((new Application(static::$kernel))->find('app:votes:reapply-erasures'));
        self::assertSame(0, $tester->execute([]));
        self::assertMatchesRegularExpression('/Conturi golite \(„Cont șters”\)\s+1/u', $tester->getDisplay());
        $u = $this->user();
        self::assertSame(User::DELETED_NAME, $u['display_name']);
        self::assertNotNull($u['deleted_at']);

        $tester->execute([]);
        self::assertMatchesRegularExpression('/Conturi golite \(„Cont șters”\)\s+0/u', $tester->getDisplay(), 'idempotentă');
    }
}
