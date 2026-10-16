<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CivicSubject;
use App\Entity\TreeNode;
use App\Media\ImageProcessor;
use App\Media\MediaStorage;
use App\Repository\CivicSubjectRepository;
use App\Repository\SubjectMediaRepository;
use App\Repository\TreeNodeRepository;
use App\Seo\Labels;
use App\Seo\SeoShell;
use App\Seo\SlugRegistry;
use App\Subject\SubjectVisibility;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;

/**
 * ADR-0008, S.3: paginile publice `/categorie/{cale}` și `/categorie/{cale}/subiect/{slug}`, servite pe
 * validez.webnou.ro (nginx-ul FE le trimite aici). Aceleași reguli de adresă ca FE (frontend/src/seo/paths.js):
 * cale/slug vechi sau cale de categorie greșită → 301 spre adresa canonică; inexistent sau nepublicat → 404
 * cu noindex (Google vine anonim: doar subiecte PUBLISHED). Pagina e aplicația React cu meta și rezumat.
 */
final class SeoShellController
{
    private const SUBJECTS_ON_CATEGORY = 50;

    public function __construct(
        private readonly SlugRegistry $slugs,
        private readonly TreeNodeRepository $nodes,
        private readonly CivicSubjectRepository $subjects,
        private readonly SubjectMediaRepository $media,
        private readonly MediaStorage $storage,
        private readonly SeoShell $shell,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/categorie/{path}', name: 'seo_shell', requirements: ['path' => '.*'], defaults: ['path' => ''], methods: ['GET', 'HEAD'])]
    public function __invoke(string $path): Response
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        $i = array_search('subiect', $segments, true);
        if ($segments === [] || $i === 0) {
            return $this->redirect('/');
        }
        foreach ($segments as $segment) {
            if (preg_match('/^[a-z0-9-]{1,100}$/', $segment) !== 1) {
                return $this->notFound();
            }
        }
        if ($i === false) {
            return $this->category(implode('/', $segments));
        }
        $categoryPath = implode('/', \array_slice($segments, 0, $i));
        if (\count($segments) !== $i + 2) {
            return $this->redirect('/categorie/'.$categoryPath);
        }

        return $this->subject($categoryPath, $segments[$i + 1]);
    }

    private function category(string $path): Response
    {
        $id = $this->slugs->nodeIdForPath($path);
        $node = $id === null ? null : $this->nodes->find(Ulid::fromString($id));
        if ($node === null) {
            return $this->notFound();
        }
        if ($node->getSlugPath() !== $path) {
            return $this->redirect('/categorie/'.$node->getSlugPath());
        }
        $crumbs = $this->nodes->withBreadcrumbs($node)->getBreadcrumbs() ?? [];
        [$subjects] = $this->subjects->findVisiblePage($node, null, null, null, false, 1, self::SUBJECTS_ON_CATEGORY, SubjectVisibility::PUBLISHED, true);
        $names = array_column($crumbs, 'name');

        return $this->shell->render([
            'title' => implode(' · ', array_reverse(\array_slice($names, -2))),
            'description' => 'Subiecte din categoria '.implode(' › ', $names).' pe VALIDEZ.',
            'path' => '/categorie/'.$path,
            'jsonLd' => [$this->breadcrumbList($crumbs)],
        ], $this->twig->render('seo/category.html.twig', [
            'node' => $node,
            'crumbs' => $crumbs,
            'children' => $this->nodes->findChildren($node),
            'subjects' => $subjects,
            'types' => Labels::TYPES,
            'stages' => Labels::STAGES,
        ]));
    }

    private function subject(string $categoryPath, string $slug): Response
    {
        $id = $this->slugs->subjectIdForSlug($slug);
        $subject = $id === null ? null : $this->subjects->find(Ulid::fromString($id));
        if (!$subject instanceof CivicSubject || $subject->getVisibility() !== SubjectVisibility::PUBLISHED) {
            return $this->notFound();
        }
        $canonical = $subject->getCanonicalPath();
        if ($canonical !== '/categorie/'.$categoryPath.'/subiect/'.$slug) {
            return $this->redirect($canonical);
        }
        $crumbs = $this->nodes->withBreadcrumbs($subject->getNode())->getBreadcrumbs() ?? [];
        $description = Labels::excerpt($subject->getDescription()) ?: $subject->getTitle();
        // Prima fotografie publicată (ordinea aleasă de autor/admini); una fără varianta og (încărcată înainte de
        // 16.10.2026 și negenerată încă de app:media:og-backfill) lasă imaginea site-ului din șablon.
        $cover = $this->media->findFirstPublishedPhoto($subject);
        $image = $cover !== null && $this->storage->exists($cover->storageKey('og')) ? [
            'path' => '/media/og/'.$cover->getId()->toBase32().'.jpg',
            'width' => ImageProcessor::OG_WIDTH,
            'height' => ImageProcessor::OG_HEIGHT,
            'alt' => 'Fotografie: '.$subject->getTitle(),
        ] : null;

        return $this->shell->render([
            'title' => $subject->getTitle(),
            'description' => $description,
            'path' => $canonical,
            'ogType' => 'article',
            'image' => $image,
            'jsonLd' => [
                $this->breadcrumbList([...$crumbs, ['name' => $subject->getTitle(), 'url' => $canonical]]),
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'Article',
                    'headline' => mb_substr($subject->getTitle(), 0, 110),
                    'description' => $description,
                    ...($image === null ? [] : ['image' => $this->shell->url($image['path'])]),
                    'datePublished' => $subject->getCreatedAt()->format(\DATE_ATOM),
                    'dateModified' => $subject->getUpdatedAt()->format(\DATE_ATOM),
                    'mainEntityOfPage' => $this->shell->url($canonical),
                    'publisher' => ['@type' => 'Organization', 'name' => 'VALIDEZ', 'url' => $this->shell->url('/')],
                ],
            ],
        ], $this->twig->render('seo/subject.html.twig', [
            'subject' => $subject,
            'crumbs' => $crumbs,
            'types' => Labels::TYPES,
            'stages' => Labels::STAGES,
        ]));
    }

    /** @param list<array{name: string, slugPath?: string, url?: string}> $items */
    private function breadcrumbList(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $c, int $i): array => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $c['name'],
                'item' => $this->shell->url($c['url'] ?? '/categorie/'.$c['slugPath']),
            ], $items, array_keys($items)),
        ];
    }

    private function redirect(string $path): RedirectResponse
    {
        $response = new RedirectResponse($this->shell->url($path), Response::HTTP_MOVED_PERMANENTLY);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    private function notFound(): Response
    {
        return $this->shell->render(
            ['title' => 'Pagină negăsită', 'description' => 'Pagina căutată nu există sau nu este publică.', 'noindex' => true],
            $this->twig->render('seo/not_found.html.twig'),
            Response::HTTP_NOT_FOUND,
        );
    }
}
