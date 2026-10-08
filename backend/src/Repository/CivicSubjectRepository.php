<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CivicSubject;
use App\Entity\TreeNode;
use App\Entity\User;
use App\Subject\SubjectStage;
use App\Subject\SubjectType;
use App\Subject\SubjectVisibility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CivicSubject>
 */
class CivicSubjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CivicSubject::class);
    }

    /**
     * Subiectele vizibile pentru `viewer`, opțional sub un nod (nodul + tot subarborele, ltree <@),
     * cele mai noi primele. Vizibilitate: adminul vede tot; ceilalți PUBLISHED + propriile PENDING/HIDDEN.
     *
     * @return array{0: list<CivicSubject>, 1: int} [pagina, total]
     */
    public function findVisiblePage(?TreeNode $node, ?SubjectType $type, ?SubjectStage $stage, ?User $viewer, bool $isAdmin, int $page, int $perPage, ?SubjectVisibility $visibility = null, bool $direct = false): array
    {
        $base = function () use ($node, $type, $stage, $viewer, $isAdmin, $visibility, $direct): QueryBuilder {
            $qb = $this->createQueryBuilder('s');
            if ($node !== null && $direct) {
                $qb->andWhere('s.node = :node')->setParameter('node', $node->getId()->toRfc4122());
            } elseif ($node !== null) {
                // Operatorii ltree nu exista in DQL: id-urile subarborelui vin din SQL, apoi IN (parametri uuid RFC 4122).
                $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
                    'SELECT id::text FROM tree_node WHERE path <@ (SELECT path FROM tree_node WHERE id = :id)',
                    ['id' => $node->getId()->toRfc4122()],
                );
                $qb->andWhere('s.node IN (:nodes)')->setParameter('nodes', $ids);
            }
            if ($type !== null) {
                $qb->andWhere('s.type = :type')->setParameter('type', $type->value);
            }
            if ($stage !== null) {
                $qb->andWhere('s.stage = :stage')->setParameter('stage', $stage->value);
            }
            if ($visibility !== null) $qb->andWhere('s.visibility = :visibility')->setParameter('visibility', $visibility->value);
            if (!$isAdmin) {
                if ($viewer === null) {
                    $qb->andWhere('s.visibility = :pub');
                } else {
                    $qb->andWhere('s.visibility = :pub OR s.author = :viewer')->setParameter('viewer', $viewer->getId()->toRfc4122());
                }
                $qb->setParameter('pub', SubjectVisibility::PUBLISHED->value);
            }

            return $qb;
        };

        $total = (int) $base()->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        $items = $base()
            ->addSelect('n', 'a')->join('s.node', 'n')->join('s.author', 'a')
            ->orderBy('s.createdAt', \SortDirection::Descending)->addOrderBy('s.id', \SortDirection::Descending)
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();

        return [$items, $total];
    }
}
