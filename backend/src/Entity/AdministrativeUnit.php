<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\JsonObjectType;
use App\Doctrine\Type\LtreeType;
use App\Geo\AdministrativeLevel;
use App\Geo\AdministrativeNameNormalizer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * ADR-0012 p.2 (G.1b): unitate administrativă — țara, un județ (inclusiv 403 București) sau un UAT
 * (municipiu, oraș, comună, sector al Bucureștiului).
 *
 * - code: identificatorul aplicației. `RO` pentru țară (nomenclatorul SIRUTA nu o conține), codul SIRUTA
 *   ca text pentru celelalte. Stabil: legăturile și ltree-ul îl folosesc.
 * - siruta: obligatoriu pentru județe și UAT-uri, NULL doar pentru țară (CHECK în migrare).
 * - path: ltree din coduri, de la țară (`RO.127.54975`); filtrul pe județ include direct UAT-urile lui.
 * - officialName: denumirea originală din sursă; displayName: forma normalizată (AdministrativeNameNormalizer).
 * - sirutaParentCode: SIRSUP original (proveniență). La sectoare e 179132, deși în ierarhia aplicației
 *   părintele e județul 403 (decizie 16.10.2026).
 * - externalIds: identificatori ai altor surse, de ex. {"ancpi_natcode": "1"} la țară — nu sunt coduri SIRUTA.
 * - retiredAt: unitate dispărută dintr-o versiune nouă a nomenclatorului; rândul rămâne, pentru legăturile existente.
 *
 * Constrângerile pe care Doctrine nu le poate exprima (CHECK, GiST) sunt în migrarea Version20261016210000.
 */
#[ORM\Entity]
#[ORM\Table(name: 'administrative_unit')]
#[ORM\UniqueConstraint(name: 'uniq_administrative_unit_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_administrative_unit_siruta', columns: ['siruta'])]
#[ORM\Index(name: 'idx_administrative_unit_parent', columns: ['parent_id'])]
#[ORM\Index(name: 'idx_administrative_unit_path_gist', columns: ['path'])]
#[ORM\HasLifecycleCallbacks]
class AdministrativeUnit
{
    public const COUNTRY_CODE = 'RO';

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(length: 16)]
    private string $code;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $siruta;

    #[ORM\Column(length: 16, enumType: AdministrativeLevel::class)]
    private AdministrativeLevel $level;

    /** TIP din SIRUTA (40 județ, 1–4 municipiu/oraș/comună, 6 sector); NULL la țară. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $sirutaType;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?AdministrativeUnit $parent;

    #[ORM\Column(type: LtreeType::NAME)]
    private string $path;

    #[ORM\Column(length: 255)]
    private string $officialName;

    #[ORM\Column(length: 255)]
    private string $displayName;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $sirutaParentCode;

    /** @var array<string, string> */
    #[ORM\Column(type: JsonObjectType::NAME)]
    private array $externalIds;

    /** Versiunea nomenclatorului care a confirmat ultima dată unitatea (de ex. SIRUTA_s1_2026). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $nomenclatureVersion;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, string> $externalIds */
    private function __construct(AdministrativeLevel $level, string $code, ?int $siruta, ?int $sirutaType, ?self $parent,
        string $officialName, ?int $sirutaParentCode, array $externalIds, ?string $nomenclatureVersion)
    {
        if ($parent?->level !== $level->parentLevel()) {
            throw new \InvalidArgumentException(sprintf('Părintele unei unități %s trebuie să fie %s.', $level->value, $level->parentLevel()?->value ?? 'absent'));
        }
        if ($level === AdministrativeLevel::COUNTRY ? $siruta !== null : ($siruta === null || $siruta <= 0)) {
            throw new \InvalidArgumentException('Codul SIRUTA e obligatoriu și pozitiv pentru județe și UAT-uri și lipsește la țară.');
        }
        if ($level !== AdministrativeLevel::COUNTRY && $code !== (string) $siruta) {
            throw new \InvalidArgumentException('Codul unui județ sau UAT este codul SIRUTA.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $code)) {
            throw new \InvalidArgumentException('Codul trebuie să fie o etichetă ltree validă.');
        }

        $this->id = new Ulid();
        $this->level = $level;
        $this->code = $code;
        $this->siruta = $siruta;
        $this->sirutaType = $sirutaType;
        $this->parent = $parent;
        $this->path = $parent === null ? $code : $parent->path.'.'.$code;
        $this->rename($officialName);
        $this->sirutaParentCode = $sirutaParentCode;
        $this->externalIds = $externalIds;
        $this->nomenclatureVersion = $nomenclatureVersion;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /** @param array<string, string> $externalIds de ex. ['ancpi_natcode' => '1'] */
    public static function country(string $officialName, array $externalIds = []): self
    {
        return new self(AdministrativeLevel::COUNTRY, self::COUNTRY_CODE, null, null, null, $officialName, null, $externalIds, null);
    }

    public static function county(self $country, int $siruta, int $sirutaType, string $officialName, ?int $sirutaParentCode, ?string $nomenclatureVersion): self
    {
        return new self(AdministrativeLevel::COUNTY, (string) $siruta, $siruta, $sirutaType, $country, $officialName, $sirutaParentCode, [], $nomenclatureVersion);
    }

    public static function uat(self $county, int $siruta, int $sirutaType, string $officialName, ?int $sirutaParentCode, ?string $nomenclatureVersion): self
    {
        return new self(AdministrativeLevel::UAT, (string) $siruta, $siruta, $sirutaType, $county, $officialName, $sirutaParentCode, [], $nomenclatureVersion);
    }

    /** Denumirea originală din sursă; forma de afișare se recalculează. */
    public function rename(string $officialName): void
    {
        if (trim($officialName) === '') {
            throw new \InvalidArgumentException('Denumirea nu poate fi goală.');
        }
        $this->officialName = $officialName;
        $this->displayName = AdministrativeNameNormalizer::display($officialName);
    }

    public function confirmIn(string $nomenclatureVersion): void
    {
        $this->nomenclatureVersion = $nomenclatureVersion;
        $this->retiredAt = null;
    }

    public function retire(): void
    {
        $this->retiredAt ??= new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Ulid { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getSiruta(): ?int { return $this->siruta; }
    public function getLevel(): AdministrativeLevel { return $this->level; }
    public function getSirutaType(): ?int { return $this->sirutaType; }
    public function getParent(): ?self { return $this->parent; }
    public function getPath(): string { return $this->path; }
    public function getOfficialName(): string { return $this->officialName; }
    public function getDisplayName(): string { return $this->displayName; }
    public function getSirutaParentCode(): ?int { return $this->sirutaParentCode; }
    /** @return array<string, string> */
    public function getExternalIds(): array { return $this->externalIds; }
    public function getNomenclatureVersion(): ?string { return $this->nomenclatureVersion; }
    public function getRetiredAt(): ?\DateTimeImmutable { return $this->retiredAt; }
}
