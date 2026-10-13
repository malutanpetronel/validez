<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Istoricul URL-urilor publice (ADR-0008): o cale veche de categorie (`NODE`) sau un slug vechi de
 * subiect (`SUBJECT`) → entitatea curentă. Scris doar prin App\Seo\SlugRegistry (SQL); maparea există
 * ca schema să rămână sub controlul Doctrine (`migrations:diff` gol).
 * target_id fără FK: indică fie tree_node, fie civic_subject; ambele se șterg doar RESTRICT.
 */
#[ORM\Entity]
#[ORM\Table(name: 'slug_redirect')]
class SlugRedirect
{
    #[ORM\Id]
    #[ORM\Column(length: 16)]
    private string $entityType;

    #[ORM\Id]
    #[ORM\Column(length: 1024)]
    private string $oldValue;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $targetId;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $entityType, string $oldValue, Ulid $targetId)
    {
        $this->entityType = $entityType;
        $this->oldValue = $oldValue;
        $this->targetId = $targetId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getTargetId(): Ulid
    {
        return $this->targetId;
    }
}
