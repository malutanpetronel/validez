<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TreeNode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TreeNode>
 */
class TreeNodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TreeNode::class);
    }

    /**
     * Id-ul in formatul coloanei (uuid). DQL nu aplica tipul ulid parametrilor de relatie
     * (n.parent = :parent, IN (:nodes)) si ar trimite base32 -> "invalid input syntax for type uuid".
     */
    private static function dbId(TreeNode $node): string
    {
        return $node->getId()->toRfc4122();
    }

    /**
     * Un singur nivel: radacinile (parent null) sau copiii directi, in ordinea fratilor.
     *
     * @return list<TreeNode>
     */
    public function findChildren(?TreeNode $parent): array
    {
        $qb = $this->createQueryBuilder('n')->orderBy('n.position', \SortDirection::Ascending);
        if ($parent === null) {
            $qb->where('n.parent IS NULL');
        } else {
            $qb->where('n.parent = :parent')->setParameter('parent', self::dbId($parent));
        }

        return $qb->getQuery()->getResult();
    }

    /** Pozitia de la capatul fratilor (0 pentru primul). De apelat sub TreeLock. */
    public function nextPosition(?TreeNode $parent): int
    {
        $qb = $this->createQueryBuilder('n')->select('MAX(n.position)');
        if ($parent === null) {
            $qb->where('n.parent IS NULL');
        } else {
            $qb->where('n.parent = :parent')->setParameter('parent', self::dbId($parent));
        }
        $max = $qb->getQuery()->getSingleScalarResult();

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /**
     * Seteaza hasChildren pe noduri cu o singura interogare (fara N+1).
     *
     * @param list<TreeNode> $nodes
     *
     * @return list<TreeNode>
     */
    public function withChildrenFlags(array $nodes): array
    {
        if ($nodes === []) {
            return $nodes;
        }

        $rows = $this->createQueryBuilder('c')
            ->select('DISTINCT IDENTITY(c.parent) AS parentId')
            ->where('c.parent IN (:nodes)')
            ->setParameter('nodes', array_map(self::dbId(...), $nodes))
            ->getQuery()
            ->getScalarResult();

        $withChildren = [];
        foreach ($rows as $row) {
            $withChildren[(string) $row['parentId']] = true;
        }

        foreach ($nodes as $node) {
            $node->setHasChildren(isset($withChildren[$node->getId()->toRfc4122()]));
        }

        return $nodes;
    }
}
