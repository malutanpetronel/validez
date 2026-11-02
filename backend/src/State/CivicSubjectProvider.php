<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Entity\CivicSubject;
use App\Entity\User;
use App\Repository\CivicSubjectRepository;
use App\Repository\SubjectMediaRepository;
use App\Repository\TreeNodeRepository;
use App\Seo\SlugRegistry;
use App\Subject\SubjectStage;
use App\Subject\SubjectType;
use App\Subject\SubjectVisibility;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Listă (paginată, opțional pe subarborele unui nod) și detaliu, cu regula de vizibilitate:
 * PENDING/HIDDEN doar pentru admin și autor - pentru ceilalți detaliul e 404 (nu dezvăluim existența).
 *
 * @implements ProviderInterface<CivicSubject>
 */
final class CivicSubjectProvider implements ProviderInterface
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly CivicSubjectRepository $subjects,
        private readonly TreeNodeRepository $nodes,
        private readonly Security $security,
        private readonly SlugRegistry $slugs,
        private readonly SubjectMediaRepository $media,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $viewer = $this->security->getUser();
        $viewer = $viewer instanceof User ? $viewer : null;
        $isAdmin = $viewer?->isAdmin() ?? false;

        if (!$operation instanceof CollectionOperationInterface) {
            if ($operation->getName() === 'civic_subject_by_slug') {
                $slug = (string) ($uriVariables['slug'] ?? '');
                $id = preg_match('/^[a-z0-9-]{1,100}$/', $slug) === 1 ? $this->slugs->subjectIdForSlug($slug) : null;
            } else {
                $id = $uriVariables['id'] ?? null;
            }
            $subject = $id === null ? null : $this->subjects->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));
            if ($subject === null) {
                return null;
            }
            if ($subject->getVisibility() !== SubjectVisibility::PUBLISHED && !$isAdmin && !$subject->isAuthoredBy($viewer)) {
                return null;
            }
            $this->attachCovers([$subject]);

            return $subject;
        }

        $f = $context['filters'] ?? [];
        $node = null;
        if (($f['node'] ?? '') !== '') {
            if (!\is_string($f['node']) || !Ulid::isValid($f['node'])) {
                throw new BadRequestHttpException('Parametrul node trebuie să fie un ULID.');
            }
            $node = $this->nodes->find(Ulid::fromString($f['node'])) ?? throw new NotFoundHttpException('Nodul nu există.');
        }
        $type = $this->enumFilter($f, 'type', SubjectType::class);
        $visibility = $this->enumFilter($f, 'visibility', SubjectVisibility::class);
        $stage = $this->enumFilter($f, 'stage', SubjectStage::class);
        $page = max(1, (int) ($f['page'] ?? 1));
        // Arborele cere pagini mici (3 subiecte recente lângă arbore); implicit și maxim 20.
        $perPage = isset($f['perPage']) && is_numeric($f['perPage']) ? min(self::PER_PAGE, max(1, (int) $f['perPage'])) : self::PER_PAGE;

        [$items, $total] = $this->subjects->findVisiblePage($node, $type, $stage, $viewer, $isAdmin, $page, $perPage, $visibility, ($f['scope'] ?? '') === 'direct');
        $this->attachCovers($items);

        return new TraversablePaginator(new \ArrayIterator($items), $page, $perPage, $total);
    }

    /**
     * Coperta = prima fotografie publicată (aceeași regulă ca og:image), deci o poză în așteptare nu apare în liste.
     *
     * @param list<CivicSubject> $subjects
     */
    private function attachCovers(array $subjects): void
    {
        $covers = $this->media->findFirstPublishedPhotos($subjects);
        foreach ($subjects as $subject) {
            $cover = $covers[$subject->getId()->toBase32()] ?? null;
            $subject->attachCoverThumbUrl($cover === null ? null : '/api/media/'.$cover->getId()->toBase32().'/thumb');
        }
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    private function enumFilter(array $filters, string $name, string $enum): ?\BackedEnum
    {
        $value = $filters[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return \is_string($value) ? $enum::tryFrom($value) ?? throw new BadRequestHttpException("Valoare necunoscută pentru $name.")
            : throw new BadRequestHttpException("Valoare necunoscută pentru $name.");
    }
}
