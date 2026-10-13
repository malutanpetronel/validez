<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TreeNode;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TreeNode>
 */
class TreeNodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly Security $security)
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

    /** Stramosii + nodul, de la radacina, dintr-o singura interogare pe ltree (breadcrumbs, ADR-0008). */
    public function withBreadcrumbs(TreeNode $node): TreeNode
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT name, slug_path AS "slugPath" FROM tree_node WHERE path @> CAST(:path AS ltree) ORDER BY nlevel(path)',
            ['path' => $node->getPath()],
        );
        $node->setBreadcrumbs(array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'slugPath' => (string) $r['slugPath']], $rows));

        return $node;
    }

    /**
     * Seteaza hasChildren si totalul subiectelor vizibile pe noduri cu o singura interogare (fara N+1).
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

        $viewer = $this->security->getUser();
        $visibility = '';
        $parameters = [array_map(self::dbId(...), $nodes)];
        $types = [\Doctrine\DBAL\ArrayParameterType::STRING];
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            $visibility = ' AND (s.visibility = ?';
            $parameters[] = 'PUBLISHED';
            $types[] = \Doctrine\DBAL\ParameterType::STRING;
            if ($viewer instanceof User) {
                $visibility .= ' OR s.author_id = ?';
                $parameters[] = $viewer->getId()->toRfc4122();
                $types[] = \Doctrine\DBAL\ParameterType::STRING;
            }
            $visibility .= ')';
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT n.id AS "nodeId",
                    EXISTS (SELECT 1 FROM tree_node c WHERE c.parent_id = n.id) AS "hasChildren",
                    (SELECT COUNT(*) FROM tree_node d
                     JOIN civic_subject s ON s.node_id = d.id
                     WHERE d.path <@ n.path'.$visibility.') AS "subjectCount"
             FROM tree_node n
             WHERE n.id IN (?)',
            // Parametrii de vizibilitate preced lista ID-urilor in SQL.
            [...array_slice($parameters, 1), $parameters[0]],
            [...array_slice($types, 1), $types[0]],
        )->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['nodeId']] = $row;
        }

        foreach ($nodes as $node) {
            $row = $counts[$node->getId()->toRfc4122()];
            $node->setSubjectCount((int) $row['subjectCount']);
            $node->setHasChildren(in_array($row['hasChildren'], [true, 1, '1', 't', 'true'], true));
        }

        return $nodes;
    }
}
