<?php

declare(strict_types=1);

namespace App\Subject;

use App\Entity\CivicSubject;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class PublishingPolicy
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function lock(User $user): void
    {
        $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
    }

    public function approvedCount(User $user): int
    {
        return $this->em->getRepository(CivicSubject::class)->count(['author' => $user, 'approvedContribution' => true]);
    }

    public function canPublish(User $user): bool
    {
        return $user->isAdmin() || (!$user->isDirectPublishingRevoked() && $this->approvedCount($user) >= 3);
    }
}
