<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\TreeNodeMoveInput;
use App\Entity\TreeNode;
use App\Repository\TreeNodeRepository;
use App\Tree\TreeMoveException;
use App\Tree\TreeMover;
use App\Tree\TreeNodeNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * @implements ProcessorInterface<TreeNodeMoveInput, TreeNode>
 */
final class TreeNodeMoveProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TreeMover $mover,
        private readonly TreeNodeRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TreeNode
    {
        \assert($data instanceof TreeNodeMoveInput);

        $raw = $uriVariables['id'] ?? null;
        if ($raw === null || (!$raw instanceof Ulid && !Ulid::isValid((string) $raw))) {
            throw new NotFoundHttpException('Nodul nu există.');
        }
        $id = $raw instanceof Ulid ? $raw : Ulid::fromString((string) $raw);

        try {
            $this->mover->move($id, $data->parent === null ? null : Ulid::fromString($data->parent), $data->position);
        } catch (TreeNodeNotFoundException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        } catch (TreeMoveException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        // Mutarea s-a facut in SQL: entitatile din identity map ar fi vechi.
        $this->em->clear();
        $node = $this->repository->find($id) ?? throw new NotFoundHttpException('Nodul nu există.');
        $this->repository->withChildrenFlags([$node]);

        return $node;
    }
}
