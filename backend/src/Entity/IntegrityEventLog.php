<?php

declare(strict_types=1);

namespace App\Entity;

use App\Vote\Bytes;
use App\Vote\IntegrityEventKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Jurnal de integritate append-only (ADR-0007 §7): fără user_id, vote_id, pseudonim, IP sau opțiune în clar.
 * UPDATE/DELETE sunt respinse de un trigger. TRUNCATE rămâne disponibil testelor; în producție rolul aplicației
 * nu trebuie să poată executa TRUNCATE sau elimina triggerul (vezi live-reminders.md).
 * Commitmentul, secvența și hashurile se calculează în VoteService (Step 4.b3), sub lockul de rând al subiectului.
 */
#[ORM\Entity]
#[ORM\Table(name: 'integrity_event_log')]
#[ORM\UniqueConstraint(name: 'uniq_integrity_subject_sequence', columns: ['subject_id', 'sequence_number'])]
#[ORM\UniqueConstraint(name: 'uniq_integrity_event_hash', columns: ['event_hash'])]
class IntegrityEventLog
{
    public const FORMAT_VERSION = 1;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: CivicSubject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CivicSubject $subject;

    #[ORM\Column(type: Types::BIGINT)]
    private int|string $sequenceNumber;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $formatVersion;

    #[ORM\Column(length: 32, enumType: IntegrityEventKind::class)]
    private IntegrityEventKind $kind;

    /** @var string|resource */
    #[ORM\Column(type: Types::BINARY, length: 32)]
    private mixed $commitment;

    /** @var string|resource|null */
    #[ORM\Column(type: Types::BINARY, length: 32, nullable: true)]
    private mixed $previousEventHash;

    /** @var string|resource */
    #[ORM\Column(type: Types::BINARY, length: 32)]
    private mixed $eventHash;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(CivicSubject $subject, int $sequenceNumber, IntegrityEventKind $kind, string $commitment, ?string $previousEventHash, string $eventHash, \DateTimeImmutable $createdAt)
    {
        if ($sequenceNumber < 1) {
            throw new \InvalidArgumentException('Secvența începe de la 1.');
        }
        if (($sequenceNumber === 1) !== ($previousEventHash === null)) {
            throw new \InvalidArgumentException('previous_event_hash e nul doar la primul eveniment al subiectului.');
        }
        $this->id = new Ulid();
        $this->subject = $subject;
        $this->sequenceNumber = $sequenceNumber;
        $this->formatVersion = self::FORMAT_VERSION;
        $this->kind = $kind;
        $this->commitment = Bytes::require32($commitment, 'commitment');
        $this->previousEventHash = $previousEventHash === null ? null : Bytes::require32($previousEventHash, 'previous_event_hash');
        $this->eventHash = Bytes::require32($eventHash, 'event_hash');
        $this->createdAt = $createdAt;
    }

    public function getId(): Ulid { return $this->id; }
    public function getSubject(): CivicSubject { return $this->subject; }
    public function getSequenceNumber(): int { return (int) $this->sequenceNumber; }
    public function getFormatVersion(): int { return $this->formatVersion; }
    public function getKind(): IntegrityEventKind { return $this->kind; }
    public function getCommitment(): string { return (string) Bytes::read($this->commitment); }
    public function getPreviousEventHash(): ?string { return Bytes::read($this->previousEventHash); }
    public function getEventHash(): string { return (string) Bytes::read($this->eventHash); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
