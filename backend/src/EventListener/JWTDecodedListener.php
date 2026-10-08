<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_decoded')]
final class JWTDecodedListener
{
    public function __construct(private readonly Connection $db) {}

    public function __invoke(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();
        $email = $payload['username'] ?? null;
        $version = is_string($email) ? $this->db->fetchOne('SELECT credential_version FROM app_user WHERE email = ?', [User::normalizeEmail($email)]) : false;
        // Tokens issued before this migration remain valid only until the first password reset.
        if ($version === false || ($payload['credentialVersion'] ?? 0) !== (int) $version) $event->markAsInvalid();
    }
}
