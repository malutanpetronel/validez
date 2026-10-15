<?php

declare(strict_types=1);

namespace App\Entity;

use App\Subject\LocationSource;
use App\Subject\SubjectType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Instantaneu complet al câmpurilor de sens ale unui subiect (ADR-0007 §3–4).
 * Include tipul: înaintea primului vot, tipul se mai poate schimba.
 * Estimarea se păstrează brută, ca în CivicSubject; se expune doar pentru PROPOSAL.
 * Revizia 1 a subiectelor existente la migrarea Step 4.b1 e starea găsită atunci, nu conținutul de la creare.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subject_revision')]
#[ORM\UniqueConstraint(name: 'uniq_subject_revision_number', columns: ['subject_id', 'number'])]
class SubjectRevision
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: CivicSubject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CivicSubject $subject;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(length: 32, enumType: SubjectType::class)]
    private SubjectType $type;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $costEstimate;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $costCurrency;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $costEstimateScope;

    /** ADR-0011 p.11: punctul de pe hartă face parte din revizie; la reconfirmare se văd vechiul și noul punct. */
    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: 6, nullable: true)]
    private ?string $locationLat = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: 6, nullable: true)]
    private ?string $locationLng = null;

    #[ORM\Column(length: 16, nullable: true, enumType: LocationSource::class)]
    private ?LocationSource $locationSource = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(CivicSubject $subject, int $number, User $createdBy)
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('Numărul reviziei începe de la 1.');
        }
        $this->id = new Ulid();
        $this->subject = $subject;
        $this->number = $number;
        $this->type = $subject->getType();
        $this->title = $subject->getTitle();
        $this->description = $subject->getDescription();
        ['costEstimate' => $this->costEstimate, 'costCurrency' => $this->costCurrency, 'costEstimateScope' => $this->costEstimateScope] = $subject->storedEstimate();
        ['locationLat' => $this->locationLat, 'locationLng' => $this->locationLng] = $subject->storedLocation();
        $this->locationSource = $subject->getLocationSource();
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Ulid { return $this->id; }
    public function getSubject(): CivicSubject { return $this->subject; }
    public function getNumber(): int { return $this->number; }
    public function getType(): SubjectType { return $this->type; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): string { return $this->description; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCreatedBy(): User { return $this->createdBy; }

    public function belongsTo(CivicSubject $subject): bool
    {
        return $this->subject->getId()->equals($subject->getId());
    }

    /** @return array{lat: float, lng: float, source: ?string}|null */
    public function getLocation(): ?array
    {
        return $this->locationLat === null ? null
            : ['lat' => (float) $this->locationLat, 'lng' => (float) $this->locationLng, 'source' => $this->locationSource?->value];
    }

    /** Estimarea publică a reviziei: null pe tipurile neeligibile, chiar dacă datele există intern. */
    public function publicEstimate(): ?array
    {
        if ($this->type !== SubjectType::PROPOSAL || $this->costEstimate === null) {
            return null;
        }

        return ['costEstimate' => $this->costEstimate, 'costCurrency' => $this->costCurrency, 'costEstimateScope' => $this->costEstimateScope];
    }
}
