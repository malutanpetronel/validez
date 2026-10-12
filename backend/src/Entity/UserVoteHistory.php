<?php

declare(strict_types=1);

namespace App\Entity;

use App\Vote\Bytes;
use App\Vote\VoteHistoryAction;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Istoricul privat al voturilor unei persoane (ADR-0007 §7), ștergibil complet la retragerea consimțământului.
 * `nonce` e valoarea aleatorie privată a commitmentului din IntegrityEventLog; nu se publică.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_vote_history')]
#[ORM\Index(name: 'idx_user_vote_history_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_user_vote_history_event', columns: ['integrity_event_id'])]
class UserVoteHistory
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

    #[ORM\Column(length: 16, enumType: VoteHistoryAction::class)]
    private VoteHistoryAction $action;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $valueBefore;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $valueAfter;

    #[ORM\ManyToOne(targetEntity: SubjectRevision::class)]
    #[ORM\JoinColumn(name: 'revision_before_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?SubjectRevision $revisionBefore;

    #[ORM\ManyToOne(targetEntity: SubjectRevision::class)]
    #[ORM\JoinColumn(name: 'revision_after_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?SubjectRevision $revisionAfter;

    /** @var string|resource */
    #[ORM\Column(type: Types::BINARY, length: 32)]
    private mixed $nonce;

    #[ORM\ManyToOne(targetEntity: IntegrityEventLog::class)]
    #[ORM\JoinColumn(name: 'integrity_event_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private IntegrityEventLog $integrityEvent;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        User $user,
        CivicSubject $subject,
        VoteType $voteType,
        VoteHistoryAction $action,
        ?int $valueBefore,
        ?int $valueAfter,
        ?SubjectRevision $revisionBefore,
        ?SubjectRevision $revisionAfter,
        string $nonce,
        IntegrityEventLog $integrityEvent,
    ) {
        $this->id = new Ulid();
        $this->user = $user;
        $this->subject = $subject;
        $this->voteType = $voteType;
        $this->action = $action;
        $this->valueBefore = $valueBefore;
        $this->valueAfter = $valueAfter;
        $this->revisionBefore = $revisionBefore;
        $this->revisionAfter = $revisionAfter;
        $this->nonce = Bytes::require32($nonce, 'nonce');
        $this->integrityEvent = $integrityEvent;
        $this->createdAt = $integrityEvent->getCreatedAt();
    }

    public function getAction(): VoteHistoryAction { return $this->action; }
    public function getSubject(): CivicSubject { return $this->subject; }
    public function getVoteType(): VoteType { return $this->voteType; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getValueBefore(): ?int { return $this->valueBefore; }
    public function getValueAfter(): ?int { return $this->valueAfter; }
    public function getRevisionBefore(): ?SubjectRevision { return $this->revisionBefore; }
    public function getRevisionAfter(): ?SubjectRevision { return $this->revisionAfter; }
    public function getNonce(): string { return (string) Bytes::read($this->nonce); }
    public function getIntegrityEvent(): IntegrityEventLog { return $this->integrityEvent; }
}
