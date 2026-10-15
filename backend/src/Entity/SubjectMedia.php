<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubjectMediaRepository;
use App\Subject\MediaStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Fotografie atașată unui subiect (ADR-0011). Nu face parte din revizie (p.2): adăugarea sau scoaterea nu
 * atinge SubjectRevision. La scoatere fișierele se șterg de pe disc, dar rândul rămâne cu removedAt, pentru
 * istoricul public al adăugărilor și scoaterilor (M.2).
 */
#[ORM\Entity(repositoryClass: SubjectMediaRepository::class)]
#[ORM\Table(name: 'subject_media')]
#[ORM\Index(name: 'idx_subject_media_subject', columns: ['subject_id', 'position'])]
#[ORM\Index(name: 'idx_subject_media_status', columns: ['status'])]
class SubjectMedia
{
    public const KIND_PHOTO = 'PHOTO';

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: CivicSubject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CivicSubject $subject;

    /** Nul după ștergerea contului: fotografia rămâne a subiectului. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy;

    #[ORM\Column(length: 16)]
    private string $kind = self::KIND_PHOTO;

    #[ORM\Column(length: 16, enumType: MediaStatus::class)]
    private MediaStatus $status;

    #[ORM\Column]
    private int $position;

    #[ORM\Column]
    private int $width;

    #[ORM\Column]
    private int $height;

    /** Dimensiunea variantei de afișare (originalul nu se păstrează). */
    #[ORM\Column]
    private int $byteSize;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $removedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'removed_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $removedBy = null;

    public function __construct(CivicSubject $subject, User $uploadedBy, MediaStatus $status, int $position, int $width, int $height, int $byteSize)
    {
        $this->id = new Ulid();
        $this->subject = $subject;
        $this->uploadedBy = $uploadedBy;
        $this->status = $status;
        $this->position = $position;
        $this->width = $width;
        $this->height = $height;
        $this->byteSize = $byteSize;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Ulid { return $this->id; }
    public function getSubject(): CivicSubject { return $this->subject; }
    public function getUploadedBy(): ?User { return $this->uploadedBy; }
    public function getKind(): string { return $this->kind; }
    public function getStatus(): MediaStatus { return $this->status; }
    public function getPosition(): int { return $this->position; }
    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }
    public function getByteSize(): int { return $this->byteSize; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
    public function getRemovedAt(): ?\DateTimeImmutable { return $this->removedAt; }
    public function getRemovedBy(): ?User { return $this->removedBy; }
    public function isRemoved(): bool { return $this->removedAt !== null; }

    public function isUploadedBy(?User $user): bool
    {
        return $user !== null && $this->uploadedBy !== null && $this->uploadedBy->getId()->equals($user->getId());
    }

    public function approve(): void
    {
        $this->status = MediaStatus::PUBLISHED;
        $this->approvedAt = new \DateTimeImmutable();
    }

    public function markRemoved(User $by): void
    {
        $this->removedAt = new \DateTimeImmutable();
        $this->removedBy = $by;
    }

    /** Cheile din MediaStorage; aceeași formă pe disc la dev și prod (ADR-0011 p.3). */
    public function storageKey(string $variant): string
    {
        return $this->subject->getId()->toBase32().'/'.$this->id->toBase32().'-'.$variant.'.webp';
    }
}
