<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\CivicSubject;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * SUBJECT_EDIT: adminul pe orice subiect, autorul pe ale lui.
 * Restricțiile pe câmpuri (stage/visibility, tipuri doar-admin) sunt în CivicSubjectUpdateProcessor.
 */
final class CivicSubjectVoter extends Voter
{
    public const EDIT = 'SUBJECT_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::EDIT && $subject instanceof CivicSubject;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $user->isAdmin() || $subject->isAuthoredBy($user);
    }
}
