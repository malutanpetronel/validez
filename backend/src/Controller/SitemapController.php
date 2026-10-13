<?php

declare(strict_types=1);

namespace App\Controller;

use App\Seo\SeoShell;
use App\Subject\SubjectVisibility;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ADR-0008, S.4: sitemap pentru validez.webnou.ro (nginx-ul FE trimite /sitemap.xml și /sitemap/* aici).
 * Index → categorii (pagina principală + toate categoriile) și subiectele PUBLISHED, paginate.
 * Doar adrese canonice; fără changefreq/priority (Google le ignoră). Date direct din SQL, fără entități.
 */
final class SitemapController
{
    private const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    public function __construct(
        private readonly Connection $db,
        private readonly SeoShell $shell,
        #[Autowire('%env(default:sitemap_page_size_default:int:SITEMAP_PAGE_SIZE)%')] private readonly int $pageSize,
    ) {
    }

    #[Route('/sitemap.xml', name: 'sitemap_index', methods: ['GET', 'HEAD'])]
    public function index(): Response
    {
        $xml = $this->writer('sitemapindex');
        $files = ['/sitemap/categorii.xml'];
        for ($page = 1; $page <= $this->subjectPages(); ++$page) {
            $files[] = '/sitemap/subiecte-'.$page.'.xml';
        }
        foreach ($files as $file) {
            $xml->startElement('sitemap');
            $xml->writeElement('loc', $this->shell->url($file));
            $xml->endElement();
        }

        return $this->response($xml);
    }

    #[Route('/sitemap/categorii.xml', name: 'sitemap_categories', methods: ['GET', 'HEAD'])]
    public function categories(): Response
    {
        $xml = $this->writer('urlset');
        $this->url($xml, '/', null);
        foreach ($this->db->iterateAssociative('SELECT slug_path, updated_at FROM tree_node ORDER BY slug_path') as $row) {
            $this->url($xml, '/categorie/'.$row['slug_path'], $row['updated_at']);
        }

        return $this->response($xml);
    }

    #[Route('/sitemap/subiecte-{page}.xml', name: 'sitemap_subjects', requirements: ['page' => '[1-9][0-9]{0,5}'], methods: ['GET', 'HEAD'])]
    public function subjects(int $page): Response
    {
        if ($page > $this->subjectPages()) {
            throw new NotFoundHttpException('Fișier sitemap inexistent.');
        }
        $xml = $this->writer('urlset');
        $rows = $this->db->iterateAssociative(
            'SELECT n.slug_path, s.slug, s.updated_at FROM civic_subject s JOIN tree_node n ON n.id = s.node_id
             WHERE s.visibility = :pub ORDER BY s.created_at, s.id LIMIT :limit OFFSET :offset',
            ['pub' => SubjectVisibility::PUBLISHED->value, 'limit' => $this->pageSize, 'offset' => ($page - 1) * $this->pageSize],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
        );
        foreach ($rows as $row) {
            $this->url($xml, '/categorie/'.$row['slug_path'].'/subiect/'.$row['slug'], $row['updated_at']);
        }

        return $this->response($xml);
    }

    /** Cel puțin un fișier de subiecte (gol, dacă nu există încă subiecte publicate). */
    private function subjectPages(): int
    {
        $count = (int) $this->db->fetchOne('SELECT count(*) FROM civic_subject WHERE visibility = :pub', ['pub' => SubjectVisibility::PUBLISHED->value]);

        return max(1, (int) ceil($count / max(1, $this->pageSize)));
    }

    private function writer(string $root): \XMLWriter
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement($root);
        $xml->writeAttribute('xmlns', self::NS);

        return $xml;
    }

    private function url(\XMLWriter $xml, string $path, ?string $updatedAt): void
    {
        $xml->startElement('url');
        $xml->writeElement('loc', $this->shell->url($path));
        if ($updatedAt !== null) {
            $xml->writeElement('lastmod', (new \DateTimeImmutable($updatedAt))->format(\DATE_ATOM));
        }
        $xml->endElement();
    }

    private function response(\XMLWriter $xml): Response
    {
        $xml->endElement();
        $xml->endDocument();
        $response = new Response($xml->outputMemory(), Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
