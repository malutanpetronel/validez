<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Consimțământul explicit pentru voturi (ADR-0007 §6): versiunea informării și momentele acordării/retragerii.
 * Un singur consimțământ activ per utilizator (index unic parțial). Retenția istoricului acordărilor e punct deschis.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vote_consent')]
#[ORM\Index(name: 'idx_vote_consent_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_vote_consent_active', columns: ['user_id'], options: ['where' => '(withdrawn_at IS NULL)'])]
class VoteConsent
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(length: 32)]
    private string $noticeVersion;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $grantedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $withdrawnAt = null;

    public function __construct(User $user, string $noticeVersion)
    {
        $this->id = new Ulid();
        $this->user = $user;
        $this->noticeVersion = $noticeVersion;
        $this->grantedAt = new \DateTimeImmutable();
    }

    public function getNoticeVersion(): string { return $this->noticeVersion; }
    public function isActive(): bool { return $this->withdrawnAt === null; }

    public function withdraw(): void
    {
        $this->withdrawnAt ??= new \DateTimeImmutable();
    }
}
