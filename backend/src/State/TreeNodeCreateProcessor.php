<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\TreeNodeCreateInput;
use App\Entity\TreeNode;
use App\Repository\TreeNodeRepository;
use App\Tree\TreeLock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Creare nod: pozitia = la capatul fratilor, calculata sub TreeLock, in aceeasi tranzactie
 * cu insert-ul (doua creari concurente sub acelasi parinte nu primesc aceeasi pozitie).
 *
 * @implements ProcessorInterface<TreeNodeCreateInput, TreeNode>
 */
final class TreeNodeCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TreeNodeRepository $repository,
        private readonly TreeLock $lock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TreeNode
    {
        \assert($data instanceof TreeNodeCreateInput);

        $node = $this->em->wrapInTransaction(function () use ($data): TreeNode {
            $this->lock->acquire();

            $parent = null;
            if ($data->parent !== null) {
                $parent = $this->repository->find(Ulid::fromString($data->parent))
                    ?? throw new UnprocessableEntityHttpException('Nodul parinte nu exista.');
            }

            $node = new TreeNode($data->name, $parent, $this->repository->nextPosition($parent));
            $this->em->persist($node);
            $this->em->flush();

            return $node;
        });

        $node->setHasChildren(false);

        return $node;
    }
}
