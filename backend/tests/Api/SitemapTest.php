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

/** Step S.4 (ADR-0008): sitemap index, categorii, subiecte publicate paginate (SITEMAP_PAGE_SIZE=2 în .env.test). */
final class SitemapTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $c = static::getContainer();
        Database::reset($c->get(Connection::class));
        $em = $c->get(EntityManagerInterface::class);

        $admin = new User('admin@validez.test', 'Admina', [User::ROLE_ADMIN]);
        $admin->setPassword('x');
        $em->persist($admin);
        $drumuri = new TreeNode('Drumuri', $admin);
        $cluj = new TreeNode('Cluj', $admin, $drumuri);
        $scoli = new TreeNode('Școli', $admin, null, 1);
        foreach ([$drumuri, $cluj, $scoli] as $n) {
            $em->persist($n);
        }
        foreach ([
            ['Gropi pe DN1', SubjectVisibility::PUBLISHED, $cluj],
            ['Trotuar spart', SubjectVisibility::PUBLISHED, $drumuri],
            ['Ascuns', SubjectVisibility::HIDDEN, $cluj],
            ['In asteptare', SubjectVisibility::PENDING, $cluj],
            ['Curte scoala', SubjectVisibility::PUBLISHED, $scoli],
        ] as $i => [$title, $visibility, $node]) {
            $s = new CivicSubject($node, $admin, SubjectType::ISSUE, $title, 'x');
            $s->moderate(null, $visibility);
            $em->persist($s);
            $em->flush(); // ordinea de creare, folosită la paginare
        }
    }

    /** @return list<string> loc-urile, după ce XML-ul a fost validat cu DOM */
    private function locs(string $path, string $root = 'urlset'): array
    {
        $this->client->request('GET', $path);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), $path);
        self::assertStringStartsWith('application/xml', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML((string) $response->getContent()), $path);
        self::assertSame($root, $dom->documentElement->localName);
        self::assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $dom->documentElement->namespaceURI);
        foreach ($dom->getElementsByTagName('lastmod') as $lastmod) {
            self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $lastmod->textContent), $lastmod->textContent);
        }

        return array_map(static fn (\DOMElement $e): string => $e->textContent, iterator_to_array($dom->getElementsByTagName('loc')));
    }

    public function testIndexulListeazaCategoriileSiPaginileDeSubiecte(): void
    {
        self::assertSame([
            'https://validez.test/sitemap/categorii.xml',
            'https://validez.test/sitemap/subiecte-1.xml',
            'https://validez.test/sitemap/subiecte-2.xml',
        ], $this->locs('/sitemap.xml', 'sitemapindex'), '3 subiecte publicate, câte 2 pe fișier');
    }

    public function testCategoriile(): void
    {
        self::assertSame([
            'https://validez.test/',
            'https://validez.test/categorie/drumuri',
            'https://validez.test/categorie/drumuri/cluj',
            'https://validez.test/categorie/scoli',
        ], $this->locs('/sitemap/categorii.xml'));
    }

    public function testDoarSubiectePublicatePaginate(): void
    {
        self::assertSame([
            'https://validez.test/categorie/drumuri/cluj/subiect/gropi-pe-dn1',
            'https://validez.test/categorie/drumuri/subiect/trotuar-spart',
        ], $this->locs('/sitemap/subiecte-1.xml'));
        self::assertSame(['https://validez.test/categorie/scoli/subiect/curte-scoala'], $this->locs('/sitemap/subiecte-2.xml'));

        foreach (['/sitemap/subiecte-3.xml', '/sitemap/subiecte-0.xml', '/sitemap/subiecte-x.xml'] as $path) {
            $this->client->request('GET', $path);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $path);
        }
    }

    public function testFaraSubiectePublicateExistaUnFisierGol(): void
    {
        static::getContainer()->get(Connection::class)->executeStatement("UPDATE civic_subject SET visibility = 'HIDDEN'");
        self::assertSame(['https://validez.test/sitemap/categorii.xml', 'https://validez.test/sitemap/subiecte-1.xml'], $this->locs('/sitemap.xml', 'sitemapindex'));
        self::assertSame([], $this->locs('/sitemap/subiecte-1.xml'));
    }

    public function testRobotsPeApiBlocheazaTot(): void
    {
        $robots = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/public/robots.txt');
        self::assertMatchesRegularExpression('/^User-agent: \*\s*\nDisallow: \/\s*$/m', $robots);
    }
}
