<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Entity\CivicSubject;
use App\Entity\SubjectMedia;
use App\Entity\User;
use App\Repository\CivicSubjectRepository;
use App\Repository\SubjectMediaRepository;
use App\Repository\TreeNodeRepository;
use App\Seo\SlugRegistry;
use App\Subject\SubjectListFilter;
use App\Subject\SubjectStage;
use App\Subject\SubjectType;
use App\Subject\SubjectVisibility;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
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
        private readonly Connection $db,
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
            $this->attachStats([$subject]);

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
        // ?area= (RO / cod județ / cod UAT) și ?q= (titlu sau descriere) - aceleași reguli ca bulinele din arbore.
        $filter = SubjectListFilter::fromFilters($f, $this->db);
        $page = max(1, (int) ($f['page'] ?? 1));
        // Arborele cere pagini mici (3 subiecte recente lângă arbore); implicit și maxim 20.
        $perPage = isset($f['perPage']) && is_numeric($f['perPage']) ? min(self::PER_PAGE, max(1, (int) $f['perPage'])) : self::PER_PAGE;

        [$items, $total] = $this->subjects->findVisiblePage($node, $type, $stage, $viewer, $isAdmin, $page, $perPage, $visibility, ($f['scope'] ?? '') === 'direct', $filter);
        $this->attachCovers($items);
        $this->attachStats($items);
        $this->preloadAreas($items);

        return new TraversablePaginator(new \ArrayIterator($items), $page, $perPage, $total);
    }

    /**
     * G.3: aria (unitățile declarate + UAT-ul pinului și județele lor) apare în fiecare card din listă; o singură
     * interogare pentru toată pagina, în loc de câte una per subiect. Entitățile ajung în identity map.
     *
     * @param list<CivicSubject> $subjects
     */
    private function preloadAreas(array $subjects): void
    {
        $withArea = array_values(array_filter($subjects, static fn (CivicSubject $s) => $s->getScope() !== null));
        if ($withArea === []) {
            return;
        }
        $this->subjects->createQueryBuilder('s')
            ->addSelect('u', 'up', 'pu', 'pp')
            ->leftJoin('s.areaUnits', 'u')->leftJoin('u.parent', 'up')
            ->leftJoin('s.pinAdminUnit', 'pu')->leftJoin('pu.parent', 'pp')
            // ULID-urile ca UUID (formatul coloanei); entitățile ar fi trimise în base32.
            ->where('s.id IN (:ids)')->setParameter('ids', array_map(static fn (CivicSubject $s) => $s->getId()->toRfc4122(), $withArea), ArrayParameterType::STRING)
            ->getQuery()->getResult();
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
            $base = $cover === null ? null : '/api/media/'.$cover->getId()->toBase32();
            $subject->attachCoverThumbUrl($base === null ? null : $base.'/thumb');
            $subject->attachCoverDisplayUrl($base === null ? null : $base.'/display');
            $subject->attachCoverCardUrl($base === null ? null : $base.'/card');
        }
    }

    /**
     * Rândul de indicatori din liste: persoane care au votat revizia curentă, linkuri și fotografii publicate.
     *
     * @param list<CivicSubject> $subjects
     */
    private function attachStats(array $subjects): void
    {
        $voters = $this->subjects->countVotersOnCurrentRevision($subjects);
        $media = $this->media->countPublishedByKind($subjects);
        foreach ($subjects as $subject) {
            $id = $subject->getId()->toBase32();
            $subject->attachStats($voters[$id] ?? 0, $media[$id][SubjectMedia::KIND_LINK] ?? 0, $media[$id][SubjectMedia::KIND_PHOTO] ?? 0);
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
