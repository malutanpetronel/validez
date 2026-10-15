<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CivicSubject;
use App\Entity\SubjectMedia;
use App\Subject\MediaStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;

/** @extends ServiceEntityRepository<SubjectMedia> */
final class SubjectMediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubjectMedia::class);
    }

    /** Fotografiile încă atașate (publicate și în așteptare) - limita de 10 se aplică pe ele. */
    public function countActive(CivicSubject $subject): int
    {
        return (int) $this->createQueryBuilder('m')->select('COUNT(m.id)')
            ->where('IDENTITY(m.subject) = :s')->andWhere('m.removedAt IS NULL')
            ->setParameter('s', $subject->getId(), UlidType::NAME)->getQuery()->getSingleScalarResult();
    }

    public function nextPosition(CivicSubject $subject): int
    {
        $max = $this->createQueryBuilder('m')->select('MAX(m.position)')
            ->where('IDENTITY(m.subject) = :s')->setParameter('s', $subject->getId(), UlidType::NAME)->getQuery()->getSingleScalarResult();

        return $max === null ? 0 : (int) $max + 1;
    }

    /** @return list<SubjectMedia> */
    public function findActive(CivicSubject $subject, bool $includePending): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('IDENTITY(m.subject) = :s')->andWhere('m.removedAt IS NULL')
            ->setParameter('s', $subject->getId(), UlidType::NAME)->orderBy('m.position', \SortDirection::Ascending);
        if (!$includePending) {
            $qb->andWhere('m.status = :published')->setParameter('published', MediaStatus::PUBLISHED->value);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<SubjectMedia> pentru #/moderare, cele mai vechi întâi */
    public function findPending(): array
    {
        return $this->createQueryBuilder('m')->addSelect('s')->join('m.subject', 's')
            ->where('m.status = :pending')->andWhere('m.removedAt IS NULL')
            ->setParameter('pending', MediaStatus::PENDING->value)
            ->orderBy('m.createdAt', \SortDirection::Ascending)->getQuery()->getResult();
    }
}
