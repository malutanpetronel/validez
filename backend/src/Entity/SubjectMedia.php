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
 * Fotografie sau link atașat unui subiect (ADR-0011). Nu face parte din revizie (p.2): adăugarea sau scoaterea
 * nu atinge SubjectRevision. La scoatere fișierele se șterg de pe disc, dar rândul rămâne cu removedAt, pentru
 * istoricul public al adăugărilor și scoaterilor.
 */
#[ORM\Entity(repositoryClass: SubjectMediaRepository::class)]
#[ORM\Table(name: 'subject_media')]
#[ORM\Index(name: 'idx_subject_media_subject', columns: ['subject_id', 'position'])]
#[ORM\Index(name: 'idx_subject_media_status', columns: ['status'])]
class SubjectMedia
{
    public const KIND_PHOTO = 'PHOTO';
    public const KIND_LINK = 'LINK';
    /** Variantele unei fotografii pe disc (MediaStorage); scoaterea și reaplicarea ștergerilor le parcurg pe toate. */
    public const VARIANTS = ['display', 'thumb', 'og'];

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
    private string $kind;

    #[ORM\Column(length: 16, enumType: MediaStatus::class)]
    private MediaStatus $status;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(nullable: true)]
    private ?int $width = null;

    #[ORM\Column(nullable: true)]
    private ?int $height = null;

    /** Dimensiunea variantei de afișare (originalul nu se păstrează). */
    #[ORM\Column(nullable: true)]
    private ?int $byteSize = null;

    /** Linkuri: doar http/https (ADR-0011 p.8); serverul nu descarcă pagina. */
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $removedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'removed_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $removedBy = null;

    private function __construct(string $kind, CivicSubject $subject, User $uploadedBy, MediaStatus $status, int $position)
    {
        $this->id = new Ulid();
        $this->kind = $kind;
        $this->subject = $subject;
        $this->uploadedBy = $uploadedBy;
        $this->status = $status;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function photo(CivicSubject $subject, User $uploadedBy, MediaStatus $status, int $position, int $width, int $height, int $byteSize): self
    {
        $m = new self(self::KIND_PHOTO, $subject, $uploadedBy, $status, $position);
        $m->width = $width;
        $m->height = $height;
        $m->byteSize = $byteSize;

        return $m;
    }

    public static function link(CivicSubject $subject, User $uploadedBy, MediaStatus $status, int $position, string $url, ?string $label): self
    {
        $m = new self(self::KIND_LINK, $subject, $uploadedBy, $status, $position);
        $m->url = $url;
        $m->label = $label;

        return $m;
    }

    public function getId(): Ulid { return $this->id; }
    public function getSubject(): CivicSubject { return $this->subject; }
    public function getUploadedBy(): ?User { return $this->uploadedBy; }
    public function getKind(): string { return $this->kind; }
    public function isPhoto(): bool { return $this->kind === self::KIND_PHOTO; }
    public function getStatus(): MediaStatus { return $this->status; }
    public function getPosition(): int { return $this->position; }
    public function getWidth(): ?int { return $this->width; }
    public function getHeight(): ?int { return $this->height; }
    public function getByteSize(): ?int { return $this->byteSize; }
    public function getUrl(): ?string { return $this->url; }
    public function getLabel(): ?string { return $this->label; }
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

    /** Ordonarea fotografiilor (16.10.2026); nu creează revizie și nu intră în istoricul public (ADR-0011 p.2). */
    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    /** @param ?User $by null la reaplicarea din registrul de ștergeri (ADR-0011 p.10) */
    public function markRemoved(?User $by, ?\DateTimeImmutable $at = null): void
    {
        $this->removedAt = $at ?? new \DateTimeImmutable();
        $this->removedBy = $by;
    }

    /** Cheile din MediaStorage; aceeași formă pe disc la dev și prod (ADR-0011 p.3). */
    public function storageKey(string $variant): string
    {
        return $this->subject->getId()->toBase32().'/'.$this->id->toBase32().'-'.$variant.($variant === 'og' ? '.jpg' : '.webp');
    }
}
