<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Dreptul de a modifica structura arborelui: creare, redenumire, mutare, ordonare.
 * Plan Step 1.4: navigare publica; modificarea structurii rezervata administratorilor.
 */
final class TreeEditVoter extends Voter
{
    public const EDIT = 'TREE_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::EDIT;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        return \in_array(User::ROLE_ADMIN, $token->getRoleNames(), true);
    }
}
