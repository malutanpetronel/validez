<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Dreptul de a modifica structura arborelui (creare, redenumire; mutare/ordonare la 1.3).
 *
 * Plan Step 1.4: navigare publica, modificarea structurii rezervata administratorilor.
 * TEMPORAR, pana la autentificare (1.4): in APP_ENV=dev scrierea e permisa oricui, ca ecranul
 * arborelui sa poata fi folosit local; in orice alt mediu cere ROLE_ADMIN, deci pe prod e refuzata
 * pana exista utilizatori. La 1.4 ramura "dev" se elimina.
 */
final class TreeEditVoter extends Voter
{
    public const EDIT = 'TREE_EDIT';

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::EDIT;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if ($this->environment === 'dev') {
            return true;
        }

        return \in_array('ROLE_ADMIN', $token->getRoleNames(), true);
    }
}
