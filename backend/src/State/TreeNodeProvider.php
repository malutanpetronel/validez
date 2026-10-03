<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\TreeNode;
use App\Repository\TreeNodeRepository;
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
    public function __construct(private readonly TreeNodeRepository $repository)
    {
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

        $id = $uriVariables['id'] ?? null;
        $node = $id === null ? null : $this->repository->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));
        if ($node !== null) {
            $this->repository->withChildrenFlags([$node]);
        }

        return $node;
    }
}
