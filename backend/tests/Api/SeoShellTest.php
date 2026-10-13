<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\CivicSubject;
use App\Entity\TreeNode;
use App\Entity\User;
use App\Subject\SubjectType;
use App\Subject\SubjectVisibility;
use App\Tests\Support\Database;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Step S.3 (ADR-0008): shell-ul HTML al paginilor publice — meta, canonical, JSON-LD, rezumat, 301/404.
 * Șablonul vine din tests/Fixtures/seo/index.html (SEO_SHELL_TEMPLATE în .env.test), SITE_URL=https://validez.test.
 */
final class SeoShellTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;
    private TreeNode $calitate;
    private CivicSubject $gropi;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $c = static::getContainer();
        $this->db = $c->get(Connection::class);
        Database::reset($this->db);
        $this->em = $c->get(EntityManagerInterface::class);

        $this->admin = $admin = new User('admin@validez.test', 'Admina', [User::ROLE_ADMIN]);
        $admin->setPassword('x');
        $this->em->persist($admin);
        $drumuri = new TreeNode('Drumuri', $admin);
        $cluj = new TreeNode('Cluj', $admin, $drumuri);
        $this->calitate = new TreeNode('Calitate', $admin, $cluj);
        $dn1 = new TreeNode('DN1', $admin, $this->calitate);
        foreach ([$drumuri, $cluj, $this->calitate, $dn1] as $n) {
            $this->em->persist($n);
        }
        $this->gropi = $this->subject('Gropi pe DN1', "Multe gropi\nîntre km 10 și 12.", SubjectVisibility::PUBLISHED);
        $this->em->flush();
    }

    private function subject(string $title, string $description, SubjectVisibility $visibility): CivicSubject
    {
        $s = new CivicSubject($this->calitate, $this->admin, SubjectType::ISSUE, $title, $description);
        $s->moderate(null, $visibility);
        $this->em->persist($s);

        return $s;
    }

    private function get(string $path): string
    {
        $this->client->request('GET', $path);

        return (string) $this->client->getResponse()->getContent();
    }

    private function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** @return list<array<string, mixed>> */
    private function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(static fn (string $j): array => json_decode($j, true, flags: \JSON_THROW_ON_ERROR), $m[1]);
    }

    public function testPaginaCategoriei(): void
    {
        $html = $this->get('/categorie/drumuri/cluj/calitate');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('max-age=300', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        self::assertSame(1, substr_count($html, '<title>'), 'titlul din șablon e înlocuit, nu dublat');
        self::assertStringContainsString('<title>Calitate · Cluj — VALIDEZ</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Subiecte din categoria Drumuri › Cluj › Calitate pe VALIDEZ."/>', $html);
        self::assertSame(1, substr_count($html, 'name="description"'));
        self::assertStringContainsString('<link rel="canonical" href="https://validez.test/categorie/drumuri/cluj/calitate"/>', $html);
        self::assertStringContainsString('<meta property="og:url" content="https://validez.test/categorie/drumuri/cluj/calitate"/>', $html);
        self::assertSame(1, substr_count($html, 'og:url'), 'og:url din șablon (pagina principală) e înlocuit');
        self::assertStringContainsString('<meta property="og:site_name" content="VALIDEZ"/>', $html, 'restul șablonului rămâne');
        self::assertStringContainsString('src="/static/js/main.test.js"', $html, 'aplicația se încarcă în continuare');
        self::assertStringNotContainsString('noindex', $html);

        $root = substr($html, strpos($html, '<div id="root">'));
        self::assertStringContainsString('<h1>Calitate</h1>', $root);
        self::assertStringContainsString('<a href="/categorie/drumuri">Drumuri</a>', $root);
        self::assertStringContainsString('<a href="/categorie/drumuri/cluj">Cluj</a>', $root);
        self::assertStringContainsString('<a href="/categorie/drumuri/cluj/calitate/dn1">DN1</a>', $root, 'subcategoriile ca linkuri');
        self::assertStringContainsString('<a href="/categorie/drumuri/cluj/calitate/subiect/gropi-pe-dn1">Gropi pe DN1</a> — Problemă, Deschis', $root);

        [$crumbs] = $this->jsonLd($html);
        self::assertSame('BreadcrumbList', $crumbs['@type']);
        self::assertSame(['Drumuri', 'Cluj', 'Calitate'], array_column($crumbs['itemListElement'], 'name'));
        self::assertSame('https://validez.test/categorie/drumuri/cluj', $crumbs['itemListElement'][1]['item']);
        self::assertSame([1, 2, 3], array_column($crumbs['itemListElement'], 'position'));
    }

    public function testPaginaSubiectului(): void
    {
        $html = $this->get('/categorie/drumuri/cluj/calitate/subiect/gropi-pe-dn1');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('<title>Gropi pe DN1 — VALIDEZ</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Multe gropi între km 10 și 12."/>', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://validez.test/categorie/drumuri/cluj/calitate/subiect/gropi-pe-dn1"/>', $html);
        self::assertStringContainsString('<meta property="og:type" content="article"/>', $html);
        self::assertStringContainsString('<h1>Gropi pe DN1</h1>', $html);
        self::assertStringContainsString("Multe gropi<br />\nîntre km 10 și 12.", $html);
        self::assertStringContainsString('<a href="/categorie/drumuri/cluj/calitate">Calitate</a>', $html);

        [$crumbs, $article] = $this->jsonLd($html);
        self::assertSame(['Drumuri', 'Cluj', 'Calitate', 'Gropi pe DN1'], array_column($crumbs['itemListElement'], 'name'));
        self::assertSame('https://validez.test/categorie/drumuri/cluj/calitate/subiect/gropi-pe-dn1', $crumbs['itemListElement'][3]['item']);
        self::assertSame('Article', $article['@type']);
        self::assertSame('Gropi pe DN1', $article['headline']);
        self::assertSame($this->gropi->getCreatedAt()->format(\DATE_ATOM), $article['datePublished']);
        self::assertArrayNotHasKey('author', $article);
    }

    public function testAdreseVechiSiIncompleteDau301SpreCanonic(): void
    {
        $id = $this->gropi->getId()->toRfc4122();
        $this->db->executeStatement("INSERT INTO slug_redirect VALUES ('SUBJECT', 'gropi-vechi', :id, now())", ['id' => $id]);
        $this->db->executeStatement("INSERT INTO slug_redirect VALUES ('NODE', 'drumuri/cluj-vechi', :id, now())", ['id' => $this->calitate->getParent()->getId()->toRfc4122()]);

        $cases = [
            '/categorie/drumuri/cluj/calitate/subiect/gropi-vechi' => '/categorie/drumuri/cluj/calitate/subiect/gropi-pe-dn1',
            '/categorie/drumuri/subiect/gropi-pe-dn1' => '/categorie/drumuri/cluj/calitate/subiect/gropi-pe-dn1',
            '/categorie/drumuri/cluj-vechi' => '/categorie/drumuri/cluj',
            '/categorie/drumuri/cluj/calitate/subiect' => '/categorie/drumuri/cluj/calitate',
            '/categorie/drumuri/subiect/a/b' => '/categorie/drumuri',
            '/categorie' => '/',
            '/categorie/' => '/',
            '/categorie/subiect/gropi-pe-dn1' => '/',
        ];
        foreach ($cases as $from => $to) {
            $this->get($from);
            self::assertSame(301, $this->code(), $from);
            self::assertSame('https://validez.test'.$to, $this->client->getResponse()->headers->get('Location'), $from);
        }
    }

    public function testInexistentSauNepublicat404CuNoindex(): void
    {
        $this->subject('Ascuns', 'x', SubjectVisibility::HIDDEN);
        $this->subject('In asteptare', 'x', SubjectVisibility::PENDING);
        $this->em->flush();

        foreach ([
            '/categorie/drumuri/necunoscut',
            '/categorie/Drumuri',
            '/categorie/drumuri/cluj/calitate/subiect/nu-exista',
            '/categorie/drumuri/cluj/calitate/subiect/ascuns',
            '/categorie/drumuri/cluj/calitate/subiect/in-asteptare',
        ] as $path) {
            $html = $this->get($path);
            self::assertSame(404, $this->code(), $path);
            self::assertStringContainsString('<meta name="robots" content="noindex"/>', $html, $path);
            self::assertStringNotContainsString('rel="canonical"', $html, $path);
            self::assertStringContainsString('<title>Pagină negăsită — VALIDEZ</title>', $html, $path);
            self::assertStringContainsString('src="/static/js/main.test.js"', $html, 'React afișează mesajul obișnuit');
        }
    }

    public function testTextulUtilizatorilorEsteEscapat(): void
    {
        $this->subject('Atac </script><script>alert(1)</script> $1 \\1', 'Descriere <b>bold</b> & "ghilimele"', SubjectVisibility::PUBLISHED);
        $this->em->flush();
        $slug = $this->db->fetchOne("SELECT slug FROM civic_subject WHERE title LIKE 'Atac%'");

        $html = $this->get('/categorie/drumuri/cluj/calitate/subiect/'.$slug);
        self::assertSame(200, $this->code());
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<b>bold</b>', $html);
        self::assertStringContainsString('&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt; $1 \\1', $html, 'text escapat, „$1” păstrat literal');
        self::assertSame(3, substr_count($html, '</script>'), 'doar scriptul aplicației și cele două JSON-LD');
        $ld = $this->jsonLd($html);
        self::assertSame('Atac </script><script>alert(1)</script> $1 \\1', $ld[1]['headline'], 'JSON-LD decodat corect');
    }

    public function testFaraSablonServestePaginaMinima(): void
    {
        $_SERVER['SEO_SHELL_TEMPLATE'] = $_ENV['SEO_SHELL_TEMPLATE'] = 'tests/Fixtures/seo/nu-exista.html';
        try {
            self::ensureKernelShutdown();
            $this->client = static::createClient();
            $html = $this->get('/categorie/drumuri/cluj/calitate');
            self::assertSame(200, $this->code());
            self::assertStringContainsString('<title>Calitate · Cluj — VALIDEZ</title>', $html);
            self::assertStringContainsString('<h1>Calitate</h1>', $html);
            self::assertStringNotContainsString('main.test.js', $html);
        } finally {
            $_SERVER['SEO_SHELL_TEMPLATE'] = $_ENV['SEO_SHELL_TEMPLATE'] = 'tests/Fixtures/seo/index.html';
        }
    }
}
