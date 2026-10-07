<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\Support\AltchaPayload;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationTest extends WebTestCase
{
    use AltchaPayload;

    private KernelBrowser $client;
    private Connection $db;
    private const EMAIL = 'nou@validez.test';
    private const PASSWORD = 'parola-noua-123';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->db = $container->get(Connection::class);
        foreach (['civic_subject', 'tree_node', 'refresh_token', 'app_user', 'altcha_used_challenge'] as $table) $this->db->executeStatement("DELETE FROM $table");
        $container->get('cache.rate_limiter')->clear();
        $user = new User('existent@validez.test', 'Existent', [User::ROLE_ADMIN]);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user); $em->flush();
    }

    private function request(string $path, array $data = [], bool $verify = false): array
    {
        if ($verify) $data['altcha'] = $this->solveAltcha($this->client);
        $this->client->request('POST', '/api'.$path, server: ['REMOTE_ADDR' => '192.0.2.10', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($data));
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function httpStatus(): int { return $this->client->getResponse()->getStatusCode(); }

    private function register(array $extra = []): array
    {
        return $this->request('/register', array_replace(['email' => self::EMAIL, 'displayName' => 'Utilizator Nou', 'password' => self::PASSWORD], $extra), true);
    }

    private function emailCode(): string
    {
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertMatchesRegularExpression('/este: (\d{6})/', $email->getTextBody());
        preg_match('/este: (\d{6})/', $email->getTextBody(), $matches);
        return $matches[1];
    }

    public function testRegisterConfirmAndRefresh(): void
    {
        $r = $this->register(['email' => ' Nou@Validez.TEST ', 'roles' => ['ROLE_ADMIN'], 'emailVerified' => true]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('code_sent', $r['status']);
        self::assertArrayNotHasKey('token', $r);
        $code = $this->emailCode();
        $stored = $this->db->fetchAssociative('SELECT * FROM app_user WHERE email = ?', [self::EMAIL]);
        self::assertFalse($stored['email_verified']);
        self::assertNotSame($code, $stored['confirmation_code_hash']);
        self::assertSame(64, strlen($stored['confirmation_code_hash']));
        self::assertNotSame(self::PASSWORD, $stored['password']);
        self::assertSame('[]', $stored['roles']);
        $r = $this->request('/register/confirm', ['email' => self::EMAIL, 'code' => $code]);
        self::assertSame(200, $this->httpStatus());
        self::assertNotEmpty($r['token']); self::assertNotEmpty($r['refresh_token']);
        $payload = json_decode(base64_decode(strtr(explode('.', $r['token'])[1], '-_', '+/')), true);
        self::assertSame(['ROLE_USER'], $payload['roles']);
        self::assertSame('Utilizator Nou', $payload['displayName']);
        self::assertNull($this->db->fetchOne('SELECT confirmation_code_hash FROM app_user WHERE email = ?', [self::EMAIL]));
        $refresh = $this->request('/token/refresh', ['refresh_token' => $r['refresh_token']]);
        self::assertSame(200, $this->httpStatus());
        self::assertNotSame($r['refresh_token'], $refresh['refresh_token']);
        $this->request('/register/confirm', ['email' => self::EMAIL, 'code' => $code]);
        self::assertSame(400, $this->httpStatus());
    }

    public function testPendingAccountCannotLoginAndExistingAccountsRemainActive(): void
    {
        $this->register(); $code = $this->emailCode();
        $r = $this->request('/auth', ['email' => self::EMAIL, 'password' => 'gresita'], true);
        self::assertSame(401, $this->httpStatus());
        self::assertStringNotContainsString('Confirmă', $r['message']);
        $r = $this->request('/auth', ['email' => self::EMAIL, 'password' => self::PASSWORD], true);
        self::assertSame(401, $this->httpStatus());
        self::assertStringContainsString('Confirmă adresa de email', $r['message']);
        $r = $this->request('/auth', ['email' => 'existent@validez.test', 'password' => self::PASSWORD], true);
        self::assertSame(200, $this->httpStatus()); self::assertNotEmpty($r['token']);
        $this->request('/register/confirm', ['email' => self::EMAIL, 'code' => $code]);
        self::assertSame(200, $this->httpStatus());
        $this->request('/auth', ['email' => self::EMAIL, 'password' => self::PASSWORD], true);
        self::assertSame(200, $this->httpStatus());
    }

    public function testCaptchaMissingForgedAndReplayedAreRejected(): void
    {
        foreach (['/auth', '/register', '/register/resend'] as $path) {
            $this->request($path, ['email' => self::EMAIL, 'password' => self::PASSWORD]);
            self::assertContains($this->httpStatus(), [400, 401]);
        }
        $this->request('/auth', ['email' => 'existent@validez.test', 'password' => self::PASSWORD, 'altcha' => base64_encode('{}')]);
        self::assertSame(401, $this->httpStatus());
        $payload = $this->solveAltcha($this->client);
        $body = ['email' => 'existent@validez.test', 'password' => self::PASSWORD, 'altcha' => $payload];
        $this->request('/auth', $body);
        self::assertSame(200, $this->httpStatus());
        $this->request('/auth', $body);
        self::assertSame(401, $this->httpStatus());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM altcha_used_challenge'));
    }

    public function testCodeAttemptsAndExpiry(): void
    {
        $this->register(); $code = $this->emailCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; ++$i) {
            $this->request('/register/confirm', ['email' => self::EMAIL, 'code' => $wrong]);
            self::assertSame(400, $this->httpStatus());
        }
        $this->request('/register/confirm', ['email' => self::EMAIL, 'code' => $code]);
        self::assertSame(400, $this->httpStatus());
        self::assertNull($this->db->fetchOne('SELECT confirmation_code_hash FROM app_user WHERE email = ?', [self::EMAIL]));
        $this->db->executeStatement("UPDATE app_user SET code_requested_at = NOW() - INTERVAL '61 seconds' WHERE email = ?", [self::EMAIL]);
        $this->request('/register/resend', ['email' => self::EMAIL], true); $newCode = $this->emailCode();
        $this->db->executeStatement("UPDATE app_user SET code_requested_at = NOW() - INTERVAL '16 minutes' WHERE email = ?", [self::EMAIL]);
        $this->request('/register/confirm', ['email' => self::EMAIL, 'code' => $newCode]);
        self::assertSame(400, $this->httpStatus());
    }

    public function testResendCooldownRotatesCodeAndDoesNotExposeActiveAccounts(): void
    {
        $this->register(); $oldCode = $this->emailCode();
        $this->request('/register/resend', ['email' => self::EMAIL], true);
        self::assertSame(429, $this->httpStatus());
        self::assertEmailCount(0);
        $this->db->executeStatement("UPDATE app_user SET code_requested_at = NOW() - INTERVAL '61 seconds' WHERE email = ?", [self::EMAIL]);
        $this->request('/register/resend', ['email' => self::EMAIL], true);
        self::assertSame(200, $this->httpStatus()); $newCode = $this->emailCode();
        if ($oldCode !== $newCode) {
            $this->request('/register/confirm', ['email' => self::EMAIL, 'code' => $oldCode]);
            self::assertSame(400, $this->httpStatus());
        }
        $this->request('/register/confirm', ['email' => self::EMAIL, 'code' => $newCode]);
        self::assertSame(200, $this->httpStatus());
        $this->request('/register/resend', ['email' => 'absent@validez.test'], true);
        self::assertSame(200, $this->httpStatus()); self::assertEmailCount(0);
    }

    public function testCannotOverwriteActiveAccountAndRegistrationIsLimited(): void
    {
        $hash = $this->db->fetchOne('SELECT password FROM app_user WHERE email = ?', ['existent@validez.test']);
        $this->register(['email' => 'existent@validez.test', 'password' => 'parola-alta-123']);
        self::assertSame(200, $this->httpStatus()); self::assertEmailCount(0);
        self::assertSame($hash, $this->db->fetchOne('SELECT password FROM app_user WHERE email = ?', ['existent@validez.test']));
        for ($i = 0; $i < 4; ++$i) {
            $this->register(['email' => "email$i@validez.test"]);
            self::assertSame(200, $this->httpStatus());
        }
        $this->register(['email' => 'prea-multe@validez.test']);
        self::assertSame(429, $this->httpStatus());
        self::assertSame(5, (int) $this->db->fetchOne('SELECT count(*) FROM app_user'));
    }

    public function testPendingRegistrationCanBeCorrectedAfterCooldown(): void
    {
        $this->register(); $oldCode = $this->emailCode();
        $id = $this->db->fetchOne('SELECT id FROM app_user WHERE email = ?', [self::EMAIL]);
        $this->register(['displayName' => 'Nume corectat', 'password' => 'parola-corectata-123']);
        self::assertSame(429, $this->httpStatus());
        $this->db->executeStatement("UPDATE app_user SET code_requested_at = NOW() - INTERVAL '61 seconds' WHERE email = ?", [self::EMAIL]);
        $this->register(['displayName' => 'Nume corectat', 'password' => 'parola-corectata-123']);
        self::assertSame(200, $this->httpStatus()); $newCode = $this->emailCode();
        self::assertSame($id, $this->db->fetchOne('SELECT id FROM app_user WHERE email = ?', [self::EMAIL]));
        self::assertSame('Nume corectat', $this->db->fetchOne('SELECT display_name FROM app_user WHERE email = ?', [self::EMAIL]));
        $this->request('/register/confirm', ['email' => self::EMAIL, 'code' => $oldCode]);
        self::assertSame(400, $this->httpStatus());
        $this->request('/register/confirm', ['email' => self::EMAIL, 'code' => $newCode]);
        self::assertSame(200, $this->httpStatus());
        $this->request('/auth', ['email' => self::EMAIL, 'password' => 'parola-corectata-123'], true);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM app_user'));
    }

    public function testValidationDoesNotCreateAccounts(): void
    {
        foreach ([['email' => 'invalid'], ['displayName' => 'ab'], ['password' => 'scurta'], ['email' => []]] as $invalid) {
            $this->register($invalid);
            self::assertSame(422, $this->httpStatus());
        }
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM app_user'));
    }
}
