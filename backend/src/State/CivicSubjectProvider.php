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
use App\Repository\TreeNodeRepository;
use App\Subject\SubjectStage;
use App\Subject\SubjectType;
use App\Subject\SubjectVisibility;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Listă (paginată, opțional pe subarborele unui nod) și detaliu, cu regula de vizibilitate:
 * HIDDEN doar pentru admin și autor - pentru ceilalți detaliul e 404 (nu dezvăluim existența).
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
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $viewer = $this->security->getUser();
        $viewer = $viewer instanceof User ? $viewer : null;
        $isAdmin = $viewer?->isAdmin() ?? false;

        if (!$operation instanceof CollectionOperationInterface) {
            $id = $uriVariables['id'] ?? null;
            $subject = $id === null ? null : $this->subjects->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));
            if ($subject === null) {
                return null;
            }
            if ($subject->getVisibility() === SubjectVisibility::HIDDEN && !$isAdmin && !$subject->isAuthoredBy($viewer)) {
                return null;
            }

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
        $stage = $this->enumFilter($f, 'stage', SubjectStage::class);
        $page = max(1, (int) ($f['page'] ?? 1));

        [$items, $total] = $this->subjects->findVisiblePage($node, $type, $stage, $viewer, $isAdmin, $page, self::PER_PAGE);

        return new TraversablePaginator(new \ArrayIterator($items), $page, self::PER_PAGE, $total);
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
