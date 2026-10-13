<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\TreeNode;
use App\Repository\TreeNodeRepository;
use App\Seo\SlugRegistry;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Incarcare pe ramuri (plan Step 1.5): colectia intoarce un singur nivel -
 * radacinile (fara ?parent) sau copiii unui nod (?parent={ulid}) - cu hasChildren
 * calculat intr-o singura interogare, ca UI-ul sa stie ce noduri se pot expanda.
 *
 * @implements ProviderInterface<TreeNode>
 */
final class TreeNodeProvider implements ProviderInterface
{
    public function __construct(
        private readonly TreeNodeRepository $repository,
        private readonly SlugRegistry $slugs,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            $parentId = $context['filters']['parent'] ?? null;
            $parent = null;
            if ($parentId !== null && $parentId !== '') {
                if (!\is_string($parentId) || !Ulid::isValid($parentId)) {
                    throw new BadRequestHttpException('Parametrul parent trebuie sa fie un ULID.');
                }
                $parent = $this->repository->find(Ulid::fromString($parentId))
                    ?? throw new NotFoundHttpException('Nodul parinte nu exista.');
            }

            return $this->repository->withChildrenFlags($this->repository->findChildren($parent));
        }

        if ($operation->getName() === 'tree_node_by_path') {
            $path = trim((string) $context['request']?->query->get('path', ''), '/');
            $id = preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)*$#', $path) === 1 && \strlen($path) <= 1024
                ? $this->slugs->nodeIdForPath($path) : null;
            $node = $id === null ? null : $this->repository->find(Ulid::fromString($id));
            if ($node !== null) {
                $this->repository->withChildrenFlags([$node]);
                $this->repository->withBreadcrumbs($node);
            }

            return $node;
        }

        $id = $uriVariables['id'] ?? null;
        $node = $id === null ? null : $this->repository->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));
        if ($node !== null) {
            $this->repository->withChildrenFlags([$node]);
        }

        return $node;
    }
}
