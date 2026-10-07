<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Mapping only; consumption uses an atomic DBAL insert, never serializer/API access. */
#[ORM\Entity]
#[ORM\Table(name: 'altcha_used_challenge')]
#[ORM\Index(name: 'idx_altcha_expiry', columns: ['expires_at'])]
class AltchaUsedChallenge
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;
}
