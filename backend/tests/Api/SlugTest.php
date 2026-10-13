<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Seo\SlugRegistry;
use App\Tests\Support\AltchaPayload;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Step S.1 (ADR-0008): slug-uri pentru categorii și subiecte, istoric la redenumire/mutare/titlu nou,
 * rezolvare după cale/slug cu regulile de vizibilitate.
 */
final class SlugTest extends WebTestCase
{
    use AltchaPayload;

    private const PAROLA = 'parola-de-test-123';

    private KernelBrowser $client;
    private Connection $db;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $c = static::getContainer();
        $c->get('limiter.captcha_challenge')->create('127.0.0.1')->reset();
        $this->db = $c->get(Connection::class);
        \App\Tests\Support\Database::reset($this->db);
        $em = $c->get(EntityManagerInterface::class);
        $hasher = $c->get(UserPasswordHasherInterface::class);
        foreach ([new User('admin@validez.test', 'Admina', [User::ROLE_ADMIN]), new User('ion@validez.test', 'Ion')] as $u) {
            $u->setPassword($hasher->hashPassword($u, self::PAROLA));
            $em->persist($u);
        }
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

    private function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array<string, mixed> nodul creat */
    private function node(string $name, ?array $parent = null): array
    {
        $r = $this->call('POST', '/api/tree_nodes', ['name' => $name, 'parent' => $parent['id'] ?? null], 'admin');
        self::assertSame(201, $this->code(), json_encode($r));

        return $r;
    }

    private function rename(array $node, string $name): array
    {
        $r = $this->call('PATCH', '/api/tree_nodes/'.$node['id'], ['name' => $name], 'admin', 'application/merge-patch+json');
        self::assertSame(200, $this->code(), json_encode($r));

        return $r;
    }

    private function byPath(string $path, ?string $who = null): array
    {
        return $this->call('GET', '/api/tree_nodes/by-path?path='.rawurlencode($path), null, $who);
    }

    private function subject(string $who, array $node, string $title): array
    {
        $r = $this->call('POST', '/api/civic_subjects', ['node' => $node['id'], 'type' => 'ISSUE', 'title' => $title, 'description' => 'Descriere'], $who);
        self::assertSame(201, $this->code(), json_encode($r));

        return $r;
    }

    private function bySlug(string $slug, ?string $who = null): array
    {
        return $this->call('GET', '/api/civic_subjects/by-slug/'.$slug, null, $who);
    }

    public function testCategoriiUniceIntreFratiSiSlugRezervat(): void
    {
        $drumuri = $this->node('Drumuri');
        $drumuri2 = $this->node('drumuri!');
        $scoli = $this->node('Școli și Țară');
        $cluj = $this->node('Cluj', $drumuri);
        $cluj2 = $this->node('Cluj', $scoli);
        $rezervat = $this->node('Subiect', $drumuri);

        self::assertSame(['drumuri', 'drumuri'], [$drumuri['slug'], $drumuri['slugPath']]);
        self::assertSame('drumuri-2', $drumuri2['slugPath']);
        self::assertSame('scoli-si-tara', $scoli['slugPath']);
        self::assertSame(['cluj', 'drumuri/cluj'], [$cluj['slug'], $cluj['slugPath']]);
        self::assertSame('scoli-si-tara/cluj', $cluj2['slugPath'], 'același slug sub alt părinte');
        self::assertSame('drumuri/subiect-2', $rezervat['slugPath']);

        self::assertSame($cluj['id'], $this->byPath('drumuri/cluj')['id']);
        self::assertSame('/api/tree_nodes/'.$cluj['id'], $this->byPath('drumuri/cluj')['@id'], '@id rămâne IRI-ul canonic al nodului');
        self::assertSame(
            [['id' => $drumuri['id'], 'name' => 'Drumuri', 'slugPath' => 'drumuri'], ['id' => $cluj['id'], 'name' => 'Cluj', 'slugPath' => 'drumuri/cluj']],
            $this->byPath('drumuri/cluj')['breadcrumbs'],
        );
        self::assertArrayNotHasKey('breadcrumbs', $cluj, 'breadcrumbs doar pe by-path');
        self::assertSame($cluj['id'], $this->byPath('/drumuri/cluj/')['id'], 'slash-urile de la capete nu contează');
        foreach (['drumuri/necunoscut', 'Drumuri/Cluj', 'drumuri//cluj', ''] as $gresit) {
            $this->byPath($gresit);
            self::assertSame(404, $this->code(), $gresit);
        }
    }

    public function testRedenumireaRescrieSubarboreleSiPastreazaCaileVechi(): void
    {
        $drumuri = $this->node('Drumuri');
        $cluj = $this->node('Cluj', $drumuri);
        $calitate = $this->node('Calitate', $cluj);

        self::assertSame('drumuri/cluj-napoca', $this->rename($cluj, 'Cluj-Napoca')['slugPath']);
        $r = $this->byPath('drumuri/cluj/calitate');
        self::assertSame(200, $this->code());
        self::assertSame([$calitate['id'], 'drumuri/cluj-napoca/calitate'], [$r['id'], $r['slugPath']], 'calea veche a descendentului duce la calea nouă');

        // Redenumirea doar ca formă (majuscule) nu schimbă slug-ul și nu adaugă istoric.
        $inainte = (int) $this->db->fetchOne('SELECT count(*) FROM slug_redirect');
        $this->rename($cluj, 'CLUJ-NAPOCA');
        self::assertSame($inainte, (int) $this->db->fetchOne('SELECT count(*) FROM slug_redirect'));

        // Înapoi la numele vechi: calea vie are prioritate față de istoric, iar calea intermediară rămâne validă.
        self::assertSame('drumuri/cluj', $this->rename($cluj, 'Cluj')['slugPath']);
        self::assertSame($calitate['id'], $this->byPath('drumuri/cluj/calitate')['id']);
        self::assertSame('drumuri/cluj/calitate', $this->byPath('drumuri/cluj-napoca/calitate')['slugPath']);
    }

    public function testMutareaEvitaColiziuneaCuFratiiDeLaDestinatie(): void
    {
        $drumuri = $this->node('Drumuri');
        $this->node('Cluj', $drumuri);
        $scoli = $this->node('Scoli');
        $cluj = $this->node('Cluj', $scoli);
        $liceu = $this->node('Liceu', $cluj);

        $this->call('POST', '/api/tree_nodes/'.$cluj['id'].'/move', ['parent' => $drumuri['id']], 'admin');
        self::assertSame(200, $this->code());

        $r = $this->byPath('scoli/cluj/liceu');
        self::assertSame([$liceu['id'], 'drumuri/cluj-2/liceu'], [$r['id'], $r['slugPath']]);
        self::assertSame($cluj['id'], $this->byPath('drumuri/cluj-2')['id']);
        self::assertSame(['cluj', 'cluj-2'], $this->db->fetchFirstColumn(
            'SELECT slug FROM tree_node WHERE parent_id = :p ORDER BY slug',
            ['p' => \Symfony\Component\Uid\Ulid::fromString($drumuri['id'])->toRfc4122()],
        ));
    }

    public function testSubiectPublicTitluNouSlugVechiRamaneValid(): void
    {
        $calitate = $this->node('Calitate', $this->node('Cluj', $this->node('Drumuri')));
        $a = $this->subject('admin', $calitate, 'Gropi pe DN1');
        $b = $this->subject('admin', $calitate, 'Gropi pe DN1');
        self::assertSame(['gropi-pe-dn1', 'gropi-pe-dn1-2'], [$a['slug'], $b['slug']]);
        self::assertSame('/categorie/drumuri/cluj/calitate/subiect/gropi-pe-dn1', $a['canonicalPath']);

        $p = $this->call('PATCH', '/api/civic_subjects/'.$a['id'], ['title' => 'Gropi reparate pe DN1'], 'admin', 'application/merge-patch+json');
        self::assertSame('gropi-reparate-pe-dn1', $p['slug']);

        $r = $this->bySlug('gropi-pe-dn1');
        self::assertSame(200, $this->code());
        self::assertSame([$a['id'], '/categorie/drumuri/cluj/calitate/subiect/gropi-reparate-pe-dn1'], [$r['id'], $r['canonicalPath']]);
        self::assertSame($b['id'], $this->bySlug('gropi-pe-dn1-2')['id']);

        // Editare fără titlu: slug-ul rămâne.
        self::assertSame('gropi-reparate-pe-dn1', $this->call('PATCH', '/api/civic_subjects/'.$a['id'], ['description' => 'Alta'], 'admin', 'application/merge-patch+json')['slug']);

        // Subiectul urmează categoria: după redenumirea ei, canonicalPath se schimbă, slug-ul nu.
        $this->rename(['id' => $calitate['id']], 'Starea drumurilor');
        self::assertSame('/categorie/drumuri/cluj/starea-drumurilor/subiect/gropi-reparate-pe-dn1', $this->bySlug('gropi-reparate-pe-dn1')['canonicalPath']);
    }

    public function testSubiectNepublicat404SiFaraIstoric(): void
    {
        $drumuri = $this->node('Drumuri');
        $s = $this->subject('ion', $drumuri, 'Trotuar spart');
        self::assertSame('PENDING', $s['visibility']);

        $this->bySlug('trotuar-spart');
        self::assertSame(404, $this->code(), 'vizitatorul nu vede un subiect PENDING');
        $this->bySlug('trotuar-spart', 'ion');
        self::assertSame(200, $this->code(), 'autorul îl vede');

        $this->call('PATCH', '/api/civic_subjects/'.$s['id'], ['title' => 'Trotuar reparat'], 'ion', 'application/merge-patch+json');
        $this->bySlug('trotuar-spart', 'ion');
        self::assertSame(404, $this->code(), 'un subiect nepublicat nu lasă istoric');
        self::assertSame(0, (int) $this->db->fetchOne("SELECT count(*) FROM slug_redirect WHERE entity_type = 'SUBJECT'"));

        $this->bySlug('Trotuar-Reparat');
        self::assertSame(404, $this->code());
    }

    public function testSlugVotesNuEsteUmbritDeRutaVoturilor(): void
    {
        $s = $this->subject('admin', $this->node('Drumuri'), 'Votes');
        self::assertSame('votes', $s['slug']);
        self::assertSame($s['id'], $this->bySlug('votes')['id']);
        self::assertSame('/api/civic_subjects/'.$s['id'], $this->bySlug('votes')['@id']);
    }

    public function testAlocareaSlugurilorDeSubiectEsteSerializata(): void
    {
        // A doua conexiune ține lock-ul de alocare; prima, cu lock_timeout, nu îl poate lua -> alocările așteaptă.
        $other = DriverManager::getConnection($this->db->getParams());
        $other->beginTransaction();
        $other->executeQuery('SELECT pg_advisory_xact_lock(7302002)');
        $registry = static::getContainer()->get(SlugRegistry::class);
        $this->db->beginTransaction();
        try {
            $this->db->executeStatement("SET LOCAL lock_timeout = '300ms'");
            $registry->lockSubjects();
            self::fail('lock-ul de alocare trebuia să aștepte');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('lock timeout', $e->getMessage());
        } finally {
            $this->db->rollBack();
            $other->rollBack();
            $other->close();
        }
    }
}
