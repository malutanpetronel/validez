<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\TreeNodeRenameInput;
use App\Entity\TreeNode;
use App\Repository\TreeNodeRepository;
use App\Seo\SlugRegistry;
use App\Tree\TreeLock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Redenumire: schimba doar name; path (din ULID-uri) ramane neatins (ADR-0001).
 * Slug-ul si calea publica (ADR-0008) se recalculeaza, cu istoric pentru tot subarborele, sub TreeLock.
 *
 * @implements ProcessorInterface<TreeNodeRenameInput, TreeNode>
 */
final class TreeNodeRenameProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TreeNodeRepository $repository,
        private readonly TreeLock $lock,
        private readonly SlugRegistry $slugs,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TreeNode
    {
        \assert($data instanceof TreeNodeRenameInput);

        $id = $uriVariables['id'] ?? null;
        $node = $id === null ? null : $this->repository->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));
        if ($node === null) {
            throw new NotFoundHttpException('Nodul nu exista.');
        }

        $this->em->wrapInTransaction(function () use ($node, $data): void {
            $this->lock->acquire();
            $this->em->refresh($node);
            $node->rename($data->name);
            $this->slugs->afterRename($node);
            $this->em->flush();
        });
        $this->repository->withChildrenFlags([$node]);

        return $node;
    }
}
