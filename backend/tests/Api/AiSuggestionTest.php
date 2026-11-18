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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Step AI (ADR-0014): setările AI și sugestia de subiect din fotografie — doar admini, cote, cache, erori. */
final class AiSuggestionTest extends WebTestCase
{
    use AltchaPayload;

    private const PAROLA = 'parola-de-test-123';

    private KernelBrowser $client;
    private Connection $db;
    private TreeNode $root;
    private TreeNode $child;
    private string $tmpDir;
    /** @var list<array{url: string, body: array<string, mixed>}> */
    private array $sent = [];
    /** @var list<array<string, mixed>> */
    private array $replies = [];
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $c = static::getContainer();
        $c->get('limiter.captcha_challenge')->create('127.0.0.1')->reset();
        $this->db = $c->get(Connection::class);
        Database::reset($this->db, 'ai_request');
        $this->db->executeStatement("UPDATE ai_settings SET enabled = true, provider = 'anthropic', prompt = NULL, daily_limit_per_admin = 30,
            daily_requests_cap = 30, daily_usd_cap = 1, monthly_usd_cap = 10,
            model_prices = '{\"claude-haiku-5-5\": {\"input\": 0.1, \"output\": 0.5}, \"gpt-6-luna\": {\"input\": 0.1, \"output\": 0.5}}'");
        $c->set('http_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sent[] = ['url' => $url, 'body' => json_decode((string) $options['body'], true)];

            return new MockResponse(json_encode(array_shift($this->replies) ?? self::reply()), ['http_code' => 200]);
        }));

        $this->tmpDir = $c->getParameter('kernel.project_dir').'/var/test-uploads-ai';
        (new Filesystem())->mkdir($this->tmpDir);
        $em = $c->get(EntityManagerInterface::class);
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $admin = new User('admin@validez.test', 'Admina', [User::ROLE_ADMIN]);
        $ion = new User('ion@validez.test', 'Ion');
        foreach ([$admin, $ion] as $u) {
            $u->setPassword($hasher->hashPassword($u, self::PAROLA));
            $em->persist($u);
        }
        $this->root = new TreeNode('Drumuri', $admin, null, 0);
        $this->child = new TreeNode('Gropi', $admin, $this->root, 0);
        $em->persist($this->root);
        $em->persist($this->child);
        $em->flush();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmpDir);
        parent::tearDown();
    }

    /** @param array<string, mixed> $over */
    private static function reply(array $over = []): array
    {
        return [
            'model' => 'claude-haiku-5-5',
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 200],
            'content' => [['type' => 'tool_use', 'name' => 'raspuns', 'input' => $over + [
                'is_public_problem' => true, 'problem_kind' => 'POTHOLE', 'title' => 'Groapă adâncă în carosabil lângă trecerea de pietoni',
                'description' => 'Pe carosabil se vede o groapă largă. Mașinile o ocolesc pe contrasens.', 'severity' => 'HIGH',
                'immediate_danger' => true, 'location_clues' => ['Str. Avram Iancu 12'], 'suggested_category' => 'c2',
                'confidence' => 0.87, 'people_or_plates_visible' => false,
            ]]],
        ];
    }

    private function token(string $who): string
    {
        if (!isset($this->tokens[$who])) {
            $body = ['email' => "$who@validez.test", 'password' => self::PAROLA, 'altcha' => $this->solveAltcha($this->client)];
            $this->client->request('POST', '/api/auth', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));
            $this->tokens[$who] = json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
        }

        return $this->tokens[$who];
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $uri, ?string $who, ?array $body = null): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($who !== null) $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token($who);
        $this->client->request($method, $uri, server: $server, content: $body === null ? null : json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, mixed> */
    private function suggest(?string $who = 'admin', ?string $node = null): array
    {
        $copy = $this->tmpDir.'/'.uniqid('poza', true).'.jpg';
        copy(__DIR__.'/../Fixtures/media/photo-gps.jpg', $copy);
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($who !== null) $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token($who);
        $this->client->request('POST', '/api/ai/subject-suggestion', ['node' => $node ?? $this->root->getId()->toBase32(), 'takenAt' => '2026-11-17T09:30:00+00:00'],
            ['file' => new UploadedFile($copy, 'poza.jpg', null, null, true)], $server);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    public function testDoarAdminii(): void
    {
        $this->suggest(null);
        self::assertSame(401, $this->code());
        $this->suggest('ion');
        self::assertSame(403, $this->code());
        $this->json('GET', '/api/admin/ai-settings', 'ion');
        self::assertSame(403, $this->code());
        $this->json('PATCH', '/api/admin/ai-settings', 'ion', ['enabled' => false]);
        self::assertSame(403, $this->code());
        self::assertSame([], $this->sent);
    }

    public function testSugestiaCuCategoriaDinSubarboreSiJurnal(): void
    {
        $r = $this->suggest();
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertFalse($r['cached']);
        self::assertSame('ISSUE', $r['suggestion']['type']);
        self::assertSame('POTHOLE', $r['suggestion']['problemKind']);
        self::assertSame($this->child->getId()->toBase32(), $r['suggestion']['categoryId']);
        self::assertSame('Drumuri > Gropi', $r['suggestion']['categoryPath']);
        self::assertSame(['Str. Avram Iancu 12'], $r['suggestion']['locationClues']);
        self::assertSame('0.000220', $r['costUsd']);

        self::assertCount(1, $this->sent);
        $body = $this->sent[0]['body'];
        self::assertSame('claude-haiku-5-5', $body['model']);
        self::assertSame('image/webp', $body['messages'][0]['content'][0]['source']['media_type']);
        $prompt = $body['messages'][0]['content'][1]['text'];
        self::assertStringContainsString("c1: Drumuri\nc2: Drumuri > Gropi", $prompt);
        self::assertStringContainsString('Data fotografiei: 17.11.2026 11:30', $prompt);
        // ADR-0014 p.6: la furnizor nu pleacă metadatele (fixture-ul are GPS în EXIF).
        $sentImage = base64_decode($body['messages'][0]['content'][0]['source']['data']);
        self::assertStringNotContainsString('GPS', $sentImage);

        $row = $this->db->fetchAssociative('SELECT status, input_tokens, output_tokens, cost_usd, requester_id FROM ai_request');
        self::assertSame(['SUCCESS', 1200, 200, '0.000220'], [$row['status'], $row['input_tokens'], $row['output_tokens'], $row['cost_usd']]);
        self::assertNotNull($row['requester_id']);
    }

    public function testAceeasiCerereVineDinCacheFaraApelNou(): void
    {
        $this->suggest();
        $r = $this->suggest();
        self::assertSame(200, $this->code());
        self::assertTrue($r['cached']);
        self::assertCount(1, $this->sent);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM ai_request'));
    }

    public function testOpritSauLimitaAtinsa(): void
    {
        $this->json('PATCH', '/api/admin/ai-settings', 'admin', ['enabled' => false]);
        self::assertSame(200, $this->code());
        $r = $this->suggest();
        self::assertSame(503, $this->code());
        self::assertSame('Sugestiile AI sunt oprite din setări.', $r['message']);

        $this->json('PATCH', '/api/admin/ai-settings', 'admin', ['enabled' => true, 'dailyLimitPerAdmin' => 0]);
        $r = $this->suggest();
        self::assertSame(429, $this->code());
        self::assertStringContainsString('limita zilnică', $r['message']);
        self::assertSame([], $this->sent);

        $this->json('PATCH', '/api/admin/ai-settings', 'admin', ['provider' => 'openai', 'dailyLimitPerAdmin' => 5]);
        $r = $this->suggest();
        self::assertSame(503, $this->code(), 'fără OPENAI_API_KEY în env-ul de test');
        self::assertStringContainsString('openai', $r['message']);
    }

    public function testRaspunsInvalidEsteEroareNuSugestie(): void
    {
        $reply = self::reply();
        unset($reply['content'][0]['input']['title']);
        $this->replies[] = $reply;
        $r = $this->suggest();
        self::assertSame(502, $this->code());
        self::assertArrayNotHasKey('suggestion', $r);
        self::assertSame('ERROR', $this->db->fetchOne('SELECT status FROM ai_request'));
    }

    public function testCategoriaNecunoscutaSeIgnora(): void
    {
        $this->replies[] = self::reply(['suggested_category' => 'c99', 'problem_kind' => 'ALTCEVA', 'severity' => 'MAXIM']);
        $r = $this->suggest('admin', $this->child->getId()->toBase32());
        self::assertSame(200, $this->code());
        self::assertNull($r['suggestion']['categoryId']);
        self::assertSame('OTHER', $r['suggestion']['problemKind']);
        self::assertSame('MEDIUM', $r['suggestion']['severity']);
        self::assertStringContainsString('Categoria curentă: Drumuri > Gropi.', $this->sent[0]['body']['messages'][0]['content'][1]['text']);
    }

    public function testSetarile(): void
    {
        $r = $this->json('GET', '/api/admin/ai-settings', 'admin');
        self::assertSame(200, $this->code());
        self::assertSame(['anthropic' => true, 'openai' => false], $r['keys']);
        self::assertSame(['anthropic' => 'claude-haiku-5-5', 'openai' => 'gpt-6-luna'], $r['models']);
        self::assertTrue($r['isDefaultPrompt']);
        self::assertSame($r['defaultPrompt'], $r['prompt']);

        $r = $this->json('PATCH', '/api/admin/ai-settings', 'admin', ['modelAnthropic' => 'claude-sonnet-5-5']);
        self::assertSame(422, $this->code());
        self::assertArrayHasKey('modelAnthropic', $r['errors']);

        $r = $this->json('PATCH', '/api/admin/ai-settings', 'admin', ['prompt' => 'Descrie problema în {limba}.', 'monthlyUsdCap' => '12.5', 'dailyRequestsCap' => -1]);
        self::assertSame(422, $this->code());
        self::assertSame(['dailyRequestsCap'], array_keys($r['errors']));
        self::assertNull($this->db->fetchOne('SELECT prompt FROM ai_settings'), 'nimic salvat la eroare');

        $r = $this->json('PATCH', '/api/admin/ai-settings', 'admin', ['prompt' => 'Descrie problema în {limba}.', 'monthlyUsdCap' => '12.5']);
        self::assertSame(200, $this->code());
        self::assertFalse($r['isDefaultPrompt']);
        self::assertSame('12.50', $r['monthlyUsdCap']);
        $r = $this->json('PATCH', '/api/admin/ai-settings', 'admin', ['prompt' => null]);
        self::assertTrue($r['isDefaultPrompt']);
    }
}
