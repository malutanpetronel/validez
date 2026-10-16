<?php

declare(strict_types=1);

namespace App\Entity;

use App\Geo\BoundaryAccuracyStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * ADR-0012 p.2, p.6, p.7 (G.1b): o versiune importată a limitelor administrative.
 *
 * Poligoanele stau în tabela `administrative_boundary`, gestionată prin SQL (exclusă din ORM prin schema_filter):
 * geometria originală, în cadrul sursei (`geom_source`, SRID `sourceSrid`), și cea transformată în WGS84 (`geom`).
 *
 * - artifactChecksum: SHA-256 al artefactului local importat (de ex. manifest.json al exportului); cheia de
 *   idempotență — același artefact nu creează un set nou.
 * - declaredCrs: cadrul declarat de sursă, exact cum l-a dat sursa (de ex. WKT-ul „Romania_double_stereo”).
 * - transformation: transformarea aplicată la import, exact cum a fost aplicată (de ex. definiția PROJ cu
 *   parametrii de datum), ca rezultatul să poată fi reprodus și verificat.
 * - accuracy*: verificarea preciziei (ADR-0012 p.2). uncertaintyDistanceMeters = distanța D a zonei de
 *   incertitudine de lângă frontieră (p.6), justificată în accuracyJustification.
 * - activatedAt/deactivatedAt: setul folosit pentru derivare. Un singur set activ (index unic parțial) și doar
 *   cu verificarea acceptată (CHECK) — nu se activează un set al cărui cadru n-a fost lămurit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'boundary_set')]
#[ORM\UniqueConstraint(name: 'uniq_boundary_set_artifact_checksum', columns: ['artifact_checksum'])]
class BoundarySet
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(length: 255)]
    private string $source;

    #[ORM\Column(type: Types::TEXT)]
    private string $sourceUrl;

    #[ORM\Column(length: 64)]
    private string $license;

    /** Data datelor declarată de sursă (de ex. 2024-02-19 sau 20250324), nu data importului. */
    #[ORM\Column(length: 64)]
    private string $dataVersion;

    #[ORM\Column(length: 64)]
    private string $artifactChecksum;

    #[ORM\Column(type: Types::TEXT)]
    private string $declaredCrs;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sourceSrid;

    #[ORM\Column(type: Types::TEXT)]
    private string $transformation;

    #[ORM\Column(length: 16, enumType: BoundaryAccuracyStatus::class)]
    private BoundaryAccuracyStatus $accuracyStatus = BoundaryAccuracyStatus::UNVERIFIED;

    /** Precizia pozițională stabilită (m), din sursă și transformare. */
    #[ORM\Column(type: Types::DECIMAL, precision: 7, scale: 2, nullable: true)]
    private ?string $accuracyMeters = null;

    /** D din ADR-0012 p.6 (m). */
    #[ORM\Column(type: Types::DECIMAL, precision: 7, scale: 2, nullable: true)]
    private ?string $uncertaintyDistanceMeters = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $accuracyJustification = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $accuracyVerifiedAt = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $unitCount = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    public function __construct(string $source, string $sourceUrl, string $license, string $dataVersion, string $artifactChecksum,
        string $declaredCrs, int $sourceSrid, string $transformation)
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $artifactChecksum)) {
            throw new \InvalidArgumentException('Suma de control trebuie să fie SHA-256 hex (64 de caractere mici).');
        }
        foreach (['source' => $source, 'license' => $license, 'dataVersion' => $dataVersion, 'declaredCrs' => $declaredCrs, 'transformation' => $transformation] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException("Câmpul $field e obligatoriu.");
            }
        }
        if ($sourceSrid <= 0) {
            throw new \InvalidArgumentException('SRID-ul sursei trebuie să fie pozitiv.');
        }
        $this->id = new Ulid();
        $this->source = $source;
        $this->sourceUrl = $sourceUrl;
        $this->license = $license;
        $this->dataVersion = $dataVersion;
        $this->artifactChecksum = $artifactChecksum;
        $this->declaredCrs = $declaredCrs;
        $this->sourceSrid = $sourceSrid;
        $this->transformation = $transformation;
        $this->importedAt = new \DateTimeImmutable();
    }

    /** Rezultatul verificării preciziei; ACCEPTED cere precizia, D și justificarea. Un set activ nu se mai reevaluează. */
    public function recordAccuracy(BoundaryAccuracyStatus $status, ?string $accuracyMeters, ?string $uncertaintyDistanceMeters, string $justification): void
    {
        if ($this->isActive()) {
            throw new \LogicException('Dezactivează setul înainte de a-i reevalua precizia.');
        }
        if (trim($justification) === '') {
            throw new \InvalidArgumentException('Verificarea preciziei cere o justificare.');
        }
        if ($status === BoundaryAccuracyStatus::ACCEPTED && ($accuracyMeters === null || $uncertaintyDistanceMeters === null)) {
            throw new \InvalidArgumentException('Un set acceptat cere precizia și distanța de incertitudine.');
        }
        foreach ([$accuracyMeters, $uncertaintyDistanceMeters] as $m) {
            if ($m !== null && (!is_numeric($m) || (float) $m < 0)) {
                throw new \InvalidArgumentException('Distanțele sunt în metri, nenegative.');
            }
        }
        $this->accuracyStatus = $status;
        $this->accuracyMeters = $accuracyMeters;
        $this->uncertaintyDistanceMeters = $uncertaintyDistanceMeters;
        $this->accuracyJustification = $justification;
        $this->accuracyVerifiedAt = $status === BoundaryAccuracyStatus::UNVERIFIED ? null : new \DateTimeImmutable();
    }

    public function activate(): void
    {
        if ($this->accuracyStatus !== BoundaryAccuracyStatus::ACCEPTED) {
            throw new \LogicException('Doar un set cu precizia acceptată poate fi activat.');
        }
        if ($this->deactivatedAt !== null) {
            throw new \LogicException('Un set dezactivat nu se reactivează; importă-l ca set nou.');
        }
        $this->activatedAt ??= new \DateTimeImmutable();
    }

    public function deactivate(): void
    {
        if ($this->isActive()) {
            $this->deactivatedAt = new \DateTimeImmutable();
        }
    }

    public function setUnitCount(int $count): void
    {
        $this->unitCount = max(0, $count);
    }

    public function isActive(): bool { return $this->activatedAt !== null && $this->deactivatedAt === null; }
    public function getId(): Ulid { return $this->id; }
    public function getSource(): string { return $this->source; }
    public function getSourceUrl(): string { return $this->sourceUrl; }
    public function getLicense(): string { return $this->license; }
    public function getDataVersion(): string { return $this->dataVersion; }
    public function getArtifactChecksum(): string { return $this->artifactChecksum; }
    public function getDeclaredCrs(): string { return $this->declaredCrs; }
    public function getSourceSrid(): int { return $this->sourceSrid; }
    public function getTransformation(): string { return $this->transformation; }
    public function getAccuracyStatus(): BoundaryAccuracyStatus { return $this->accuracyStatus; }
    public function getAccuracyMeters(): ?string { return $this->accuracyMeters; }
    public function getUncertaintyDistanceMeters(): ?string { return $this->uncertaintyDistanceMeters; }
    public function getAccuracyJustification(): ?string { return $this->accuracyJustification; }
    public function getAccuracyVerifiedAt(): ?\DateTimeImmutable { return $this->accuracyVerifiedAt; }
    public function getUnitCount(): int { return $this->unitCount; }
    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }
    public function getActivatedAt(): ?\DateTimeImmutable { return $this->activatedAt; }
    public function getDeactivatedAt(): ?\DateTimeImmutable { return $this->deactivatedAt; }
}
