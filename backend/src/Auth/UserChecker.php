<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void {}

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // Report confirmation status only after valid credentials.
        if ($user instanceof User && !$user->isEmailVerified()) {
            throw new CustomUserMessageAccountStatusException('Confirmă adresa de email înainte de autentificare.');
        }
    }
}
