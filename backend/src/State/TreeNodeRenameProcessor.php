<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\TreeNodeRenameInput;
use App\Entity\TreeNode;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Redenumire: schimba doar name; path (din ULID-uri) ramane neatins (ADR-0001).
 *
 * @implements ProcessorInterface<TreeNodeRenameInput, TreeNode>
 */
final class TreeNodeRenameProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TreeNodeRepository $repository,
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

        $node->rename($data->name);
        $this->em->flush();
        $this->repository->withChildrenFlags([$node]);

        return $node;
    }
}
