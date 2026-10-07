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

/**
 * Plan Step 1.4: navigare publica; modificarea structurii doar pentru administratori;
 * autorul vine din autentificare, nu din payload. Plus login si rotatia refresh token-ului.
 */
final class AuthAndPermissionsTest extends WebTestCase
{
    use AltchaPayload;

    private const PAROLA = 'parola-de-test-123';

    private KernelBrowser $client;
    private Connection $db;
    private User $admin;
    private User $ion;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $c = static::getContainer();
        $c->get('limiter.captcha_challenge')->create('127.0.0.1')->reset();
        $this->db = $c->get(Connection::class);
        foreach (['civic_subject', 'tree_node', 'refresh_token', 'app_user'] as $t) {
            $this->db->executeStatement("DELETE FROM $t");
        }

        $em = $c->get(EntityManagerInterface::class);
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $this->admin = new User('admin@validez.test', 'Admina', [User::ROLE_ADMIN]);
        $this->ion = new User('ion@validez.test', 'Ion');
        foreach ([$this->admin, $this->ion] as $u) {
            $u->setPassword($hasher->hashPassword($u, self::PAROLA));
            $em->persist($u);
        }
        $em->flush();
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $uri, ?array $body = null, ?string $token = null, string $type = 'application/ld+json'): array
    {
        if ($method === 'POST' && $uri === '/api/auth') {
            $body['altcha'] = $this->solveAltcha($this->client);
        }
        $server = ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => 'application/ld+json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $uri, server: $server, content: $body === null ? null : json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array{token: string, refresh_token: string} */
    private function login(string $email, string $password = self::PAROLA): array
    {
        $r = $this->json('POST', '/api/auth', ['email' => $email, 'password' => $password], type: 'application/json');
        self::assertSame(200, $this->httpStatus(), 'login esuat: '.json_encode($r));

        return $r;
    }

    private function createRoot(string $token, string $name = 'Drumuri'): string
    {
        $r = $this->json('POST', '/api/tree_nodes', ['name' => $name], $token);
        self::assertSame(201, $this->httpStatus());

        return $r['id'];
    }

    public function testVizitatorulPoateNavigaArborele(): void
    {
        $id = $this->createRoot($this->login('admin@validez.test')['token']);

        $this->json('GET', '/api/tree_nodes');
        self::assertSame(200, $this->httpStatus());
        $this->json('GET', '/api/tree_nodes?parent='.$id);
        self::assertSame(200, $this->httpStatus());
        $this->json('GET', '/api/tree_nodes/'.$id);
        self::assertSame(200, $this->httpStatus());
    }

    public function testVizitatorulNuPoateModificaStructura(): void
    {
        $id = $this->createRoot($this->login('admin@validez.test')['token']);

        $this->json('POST', '/api/tree_nodes', ['name' => 'X']);
        self::assertSame(401, $this->httpStatus());
        $this->json('PATCH', '/api/tree_nodes/'.$id, ['name' => 'Y'], type: 'application/merge-patch+json');
        self::assertSame(401, $this->httpStatus());
        $this->json('POST', '/api/tree_nodes/'.$id.'/move', ['parent' => null]);
        self::assertSame(401, $this->httpStatus());
    }

    public function testUtilizatorulFaraRolAdminPrimeste403(): void
    {
        $adminToken = $this->login('admin@validez.test')['token'];
        $id = $this->createRoot($adminToken);
        $token = $this->login('ion@validez.test')['token'];

        $this->json('POST', '/api/tree_nodes', ['name' => 'X'], $token);
        self::assertSame(403, $this->httpStatus());
        $this->json('PATCH', '/api/tree_nodes/'.$id, ['name' => 'Y'], $token, 'application/merge-patch+json');
        self::assertSame(403, $this->httpStatus());
        $this->json('POST', '/api/tree_nodes/'.$id.'/move', ['parent' => null], $token);
        self::assertSame(403, $this->httpStatus());
    }

    public function testAutorulVineDinAutentificareNuDinPayload(): void
    {
        $token = $this->login('admin@validez.test')['token'];
        $r = $this->json('POST', '/api/tree_nodes', [
            'name' => 'Drumuri',
            'createdBy' => $this->ion->getId()->toBase32(),
            'created_by_id' => $this->ion->getId()->toRfc4122(),
        ], $token);
        self::assertSame(201, $this->httpStatus());

        $autor = $this->db->fetchOne('SELECT u.email FROM tree_node t JOIN app_user u ON u.id = t.created_by_id WHERE t.name = :n', ['n' => 'Drumuri']);
        self::assertSame('admin@validez.test', $autor);
        self::assertArrayNotHasKey('createdBy', $r);
    }

    public function testAdministratorulModificaStructura(): void
    {
        $token = $this->login('admin@validez.test')['token'];
        $a = $this->createRoot($token, 'A');
        $b = $this->createRoot($token, 'B');

        $this->json('PATCH', '/api/tree_nodes/'.$a, ['name' => 'A2'], $token, 'application/merge-patch+json');
        self::assertSame(200, $this->httpStatus());
        $r = $this->json('POST', '/api/tree_nodes/'.$b.'/move', ['parent' => $a], $token);
        self::assertSame(200, $this->httpStatus());
        self::assertSame($a, $r['parentId']);
    }

    public function testLoginGresitSiEmailIndiferentDeMajuscule(): void
    {
        $this->json('POST', '/api/auth', ['email' => 'admin@validez.test', 'password' => 'gresita'], type: 'application/json');
        self::assertSame(401, $this->httpStatus());
        $this->json('POST', '/api/auth', ['email' => 'nimeni@validez.test', 'password' => self::PAROLA], type: 'application/json');
        self::assertSame(401, $this->httpStatus());

        $r = $this->login('  Admin@Validez.TEST ');
        self::assertNotEmpty($r['token']);
        self::assertNotEmpty($r['refresh_token']);
    }

    public function testTokenulContineIdulNumeleSiRolurile(): void
    {
        $token = $this->login('admin@validez.test')['token'];
        $payload = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

        self::assertSame('admin@validez.test', $payload['username']);
        self::assertSame('Admina', $payload['displayName']);
        self::assertSame($this->admin->getId()->toBase32(), $payload['id']);
        self::assertContains('ROLE_ADMIN', $payload['roles']);
    }

    public function testRefreshRotesteTokenulSiInvalideazaVechiul(): void
    {
        $vechi = $this->login('admin@validez.test')['refresh_token'];

        $r = $this->json('POST', '/api/token/refresh', ['refresh_token' => $vechi], type: 'application/json');
        self::assertSame(200, $this->httpStatus());
        self::assertNotEmpty($r['token']);
        self::assertNotSame($vechi, $r['refresh_token']);

        // single_use: refresh token-ul vechi nu mai poate fi folosit
        $this->json('POST', '/api/token/refresh', ['refresh_token' => $vechi], type: 'application/json');
        self::assertSame(401, $this->httpStatus());

        // tokenul nou de acces functioneaza
        $this->createRoot($r['token']);
    }
}
