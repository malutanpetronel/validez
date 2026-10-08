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

final class PasswordResetTest extends WebTestCase
{
    use AltchaPayload;

    private KernelBrowser $client;
    private Connection $db;
    private const EMAIL = 'ion@validez.test';
    private const OLD_PASSWORD = 'parola-initiala-123';
    private const NEW_PASSWORD = 'parola-resetata-456';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $c = static::getContainer();
        $this->db = $c->get(Connection::class);
        foreach (['category_suggestion', 'civic_subject', 'tree_node', 'refresh_token', 'app_user', 'altcha_used_challenge'] as $table) $this->db->executeStatement("DELETE FROM $table");
        $c->get('cache.rate_limiter')->clear();
        $em = $c->get(EntityManagerInterface::class);
        foreach ([self::EMAIL, 'neconfirmat@validez.test'] as $email) {
            $user = new User($email, 'Ion');
            if ($email !== self::EMAIL) $user->requireEmailConfirmation();
            $user->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($user, self::OLD_PASSWORD));
            $em->persist($user);
        }
        $em->flush();
    }

    private function post(string $path, array $body, bool $captcha = false): array
    {
        if ($captcha) $body['altcha'] = $this->solveAltcha($this->client);
        $this->client->request('POST', '/api'.$path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '192.0.2.11'], content: json_encode($body));
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function httpStatus(): int { return $this->client->getResponse()->getStatusCode(); }

    private function requestCode(string $email = self::EMAIL): array
    {
        return $this->post('/password-reset/request', ['email' => $email], true);
    }

    private function code(): string
    {
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertStringContainsString('resetează parola', $mail->getSubject());
        preg_match('/este: (\d{6})/', $mail->getTextBody(), $matches);
        self::assertCount(2, $matches);
        return $matches[1];
    }

    private function confirm(string $code, string $password = self::NEW_PASSWORD, string $email = self::EMAIL): array
    {
        return $this->post('/password-reset/confirm', ['email' => $email, 'code' => $code, 'password' => $password]);
    }

    public function testResetIsSingleUseAndRevokesExistingSessions(): void
    {
        $oldSession = $this->post('/auth', ['email' => self::EMAIL, 'password' => self::OLD_PASSWORD], true);
        self::assertSame(200, $this->httpStatus());
        $this->requestCode(' ION@Validez.TEST ');
        self::assertSame(200, $this->httpStatus());
        $code = $this->code();
        $hash = $this->db->fetchOne('SELECT password_reset_code_hash FROM app_user WHERE email = ?', [self::EMAIL]);
        self::assertSame(64, strlen($hash));
        self::assertNotSame($code, $hash);
        $result = $this->confirm($code);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['status' => 'password_reset'], $result);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertNull($this->db->fetchOne('SELECT password_reset_code_hash FROM app_user WHERE email = ?', [self::EMAIL]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT credential_version FROM app_user WHERE email = ?', [self::EMAIL]));
        $this->client->request('GET', '/api/me/publishing', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$oldSession['token']]);
        self::assertSame(401, $this->httpStatus());
        $this->post('/token/refresh', ['refresh_token' => $oldSession['refresh_token']]);
        self::assertSame(401, $this->httpStatus());
        $this->confirm($code, 'alta-parola-789');
        self::assertSame(400, $this->httpStatus());
        $this->post('/auth', ['email' => self::EMAIL, 'password' => self::OLD_PASSWORD], true);
        self::assertSame(401, $this->httpStatus());
        $newSession = $this->post('/auth', ['email' => self::EMAIL, 'password' => self::NEW_PASSWORD], true);
        self::assertSame(200, $this->httpStatus());
        $this->client->request('GET', '/api/me/publishing', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$newSession['token']]);
        self::assertSame(200, $this->httpStatus());
    }

    public function testRequestDoesNotRevealUnknownOrUnconfirmedAccounts(): void
    {
        $known = $this->requestCode();
        self::assertSame(200, $this->httpStatus());
        self::assertEmailCount(1);
        foreach (['absent@validez.test', 'neconfirmat@validez.test'] as $email) {
            self::assertSame($known, $this->requestCode($email));
            self::assertSame(200, $this->httpStatus());
            self::assertEmailCount(0);
            $this->confirm('123456', self::NEW_PASSWORD, $email);
            self::assertSame(400, $this->httpStatus());
        }
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM app_user'));
        self::assertFalse((bool) $this->db->fetchOne('SELECT email_verified FROM app_user WHERE email = ?', ['neconfirmat@validez.test']));
    }

    public function testCaptchaAndCooldownAreRequiredForEveryRequest(): void
    {
        $this->post('/password-reset/request', ['email' => self::EMAIL]);
        self::assertSame(400, $this->httpStatus());
        $this->requestCode();
        self::assertSame(200, $this->httpStatus());
        $code = $this->code();
        $this->requestCode();
        self::assertSame(429, $this->httpStatus());
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        // The throttled request cannot invalidate the code already delivered.
        $this->confirm($code);
        self::assertSame(200, $this->httpStatus());
    }

    public function testFiveWrongCodesInvalidateTheReset(): void
    {
        $this->requestCode(); $code = $this->code();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; ++$i) {
            $this->confirm($wrong);
            self::assertSame(400, $this->httpStatus());
        }
        $this->confirm($code);
        self::assertSame(400, $this->httpStatus());
        self::assertNull($this->db->fetchOne('SELECT password_reset_code_hash FROM app_user WHERE email = ?', [self::EMAIL]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT credential_version FROM app_user WHERE email = ?', [self::EMAIL]));
    }

    public function testExpiryResendAndPasswordValidation(): void
    {
        $this->requestCode(); $oldCode = $this->code();
        $this->db->executeStatement("UPDATE app_user SET password_reset_requested_at = NOW() - INTERVAL '16 minutes' WHERE email = ?", [self::EMAIL]);
        $this->confirm($oldCode);
        self::assertSame(400, $this->httpStatus());
        static::getContainer()->get('cache.rate_limiter')->clear();
        $this->requestCode(); $newCode = $this->code();
        self::assertNotSame($oldCode, $newCode);
        $this->confirm($oldCode);
        self::assertSame(400, $this->httpStatus());
        foreach (['scurta', str_repeat('ș', 37)] as $invalid) {
            $this->confirm($newCode, $invalid);
            self::assertSame(422, $this->httpStatus());
        }
        $this->confirm($newCode);
        self::assertSame(200, $this->httpStatus());
    }

    public function testRequestIpLimitAndConfirmationLimit(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->requestCode("absent$i@validez.test");
            self::assertSame(200, $this->httpStatus());
        }
        $this->requestCode('alta@validez.test');
        self::assertSame(429, $this->httpStatus());
        for ($i = 0; $i < 15; ++$i) {
            $this->confirm('123456', self::NEW_PASSWORD, "absent$i@validez.test");
            self::assertSame(400, $this->httpStatus());
        }
        $this->confirm('123456', self::NEW_PASSWORD, 'alta@validez.test');
        self::assertSame(429, $this->httpStatus());
    }
}
