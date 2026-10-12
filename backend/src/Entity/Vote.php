<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Votul curent, privat și ștergibil (ADR-0007 §7). Reține tipul și revizia votată.
 * Revizia aparține subiectului, tipul e configurat și activ, valoarea e în interval — verificate în VoteService,
 * după lockuri (FK-ul spre revizie e simplu, nu compus cu subject_id).
 */
#[ORM\Entity]
#[ORM\Table(name: 'vote')]
#[ORM\UniqueConstraint(name: 'uniq_vote_subject_user_type', columns: ['subject_id', 'user_id', 'vote_type_code'])]
#[ORM\Index(name: 'idx_vote_user', columns: ['user_id'])]
class Vote
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: CivicSubject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CivicSubject $subject;

    #[ORM\ManyToOne(targetEntity: VoteType::class)]
    #[ORM\JoinColumn(name: 'vote_type_code', referencedColumnName: 'code', nullable: false, onDelete: 'RESTRICT')]
    private VoteType $voteType;

    #[ORM\ManyToOne(targetEntity: SubjectRevision::class)]
    #[ORM\JoinColumn(name: 'revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private SubjectRevision $revision;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $value;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, CivicSubject $subject, VoteType $voteType, SubjectRevision $revision, int $value)
    {
        $this->id = new Ulid();
        $this->user = $user;
        $this->subject = $subject;
        $this->voteType = $voteType;
        $this->revision = $revision;
        $this->value = $value;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Ulid { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getSubject(): CivicSubject { return $this->subject; }
    public function getVoteType(): VoteType { return $this->voteType; }
    public function getRevision(): SubjectRevision { return $this->revision; }
    public function getValue(): int { return $this->value; }

    public function update(SubjectRevision $revision, int $value): void
    {
        $this->revision = $revision;
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
