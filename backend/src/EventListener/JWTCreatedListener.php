<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Payload JWT: pe langa username (email) si roles (lexik), id-ul (ULID base32) si numele afisat,
 * ca frontend-ul sa afiseze utilizatorul fara o cerere suplimentara.
 * Doar pentru afisare: autorizarea se decide mereu pe server.
 */
#[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_created')]
final class JWTCreatedListener
{
    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $event->setData([
            ...$event->getData(),
            'id' => $user->getId()->toBase32(),
            'credentialVersion' => $user->getCredentialVersion(),
            'displayName' => $user->getDisplayName(),
        ]);
    }
}
