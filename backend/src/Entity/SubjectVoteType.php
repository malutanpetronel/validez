<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Tipurile de vot configurate pe un subiect (ADR-0003). Un tip cu voturi se dezactivează, nu se șterge (ADR-0007 §1). */
#[ORM\Entity]
#[ORM\Table(name: 'subject_vote_type')]
class SubjectVoteType
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: CivicSubject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', onDelete: 'RESTRICT')]
    private CivicSubject $subject;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: VoteType::class)]
    #[ORM\JoinColumn(name: 'vote_type_code', referencedColumnName: 'code', onDelete: 'RESTRICT')]
    private VoteType $voteType;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(CivicSubject $subject, VoteType $voteType)
    {
        $this->subject = $subject;
        $this->voteType = $voteType;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getSubject(): CivicSubject { return $this->subject; }
    public function getVoteType(): VoteType { return $this->voteType; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }
}
