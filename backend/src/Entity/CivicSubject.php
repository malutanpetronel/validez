<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Dto\CivicSubjectCreateInput;
use App\Dto\CivicSubjectUpdateInput;
use App\Geo\PinClassificationState;
use App\Repository\CivicSubjectRepository;
use App\State\CivicSubjectCreateProcessor;
use App\State\CivicSubjectProvider;
use App\State\CivicSubjectUpdateProcessor;
use App\Subject\LocationSource;
use App\Subject\SubjectStage;
use App\Subject\SubjectType;
use App\Subject\SubjectVisibility;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Ulid;

/**
 * Conținut civic asociat unui nod (ADR-0002): votabil, comentabil, moderabil în pașii următori.
 * `status` din domain.puml e împărțit în visibility (PUBLISHED/PENDING/HIDDEN) și stage (OPEN…CLOSED) - decizie Step 2.
 * Regulile de acces: CivicSubjectVoter + procesoarele (tipuri doar-admin, stage/visibility doar admin).
 */
#[ApiResource(
    operations: [
        // ?node={ulid} = subarborele; &scope=direct = doar nodul; filtre ?type=, ?stage=; 20/pagină.
        new GetCollection(provider: CivicSubjectProvider::class, paginationItemsPerPage: 20),
        // ADR-0008: subiectul dupa slug, curent sau din istoric; raspunsul are canonicalPath. Aceleasi reguli
        // de vizibilitate ca detaliul. Prioritate fata de /{id}/... (un slug poate fi si „votes”).
        new Get(uriTemplate: '/civic_subjects/by-slug/{slug}', uriVariables: ['slug'], priority: 10, provider: CivicSubjectProvider::class, name: 'civic_subject_by_slug', normalizationContext: ['groups' => ['subject:read'], 'item_uri_template' => '/civic_subjects/{id}{._format}']),
        new Get(provider: CivicSubjectProvider::class),
        new Post(security: "is_granted('ROLE_USER')", input: CivicSubjectCreateInput::class, processor: CivicSubjectCreateProcessor::class),
        new Patch(security: "is_granted('SUBJECT_EDIT', object)", provider: CivicSubjectProvider::class, input: CivicSubjectUpdateInput::class, processor: CivicSubjectUpdateProcessor::class),
    ],
    normalizationContext: ['groups' => ['subject:read']],
)]
#[ORM\Entity(repositoryClass: CivicSubjectRepository::class)]
#[ORM\Table(name: 'civic_subject')]
#[ORM\Index(name: 'idx_civic_subject_node_created', columns: ['node_id', 'created_at'])]
// G.2: coada încadrărilor nedeterminate (moderare); index parțial, creat în Version20261016230000.
// Predicatul e scris exact în forma normalizată de PostgreSQL (pg_get_expr), altfel diff-ul Doctrine îl recreează.
#[ORM\Index(name: 'idx_civic_subject_pin_admin_open', columns: ['pin_admin_state'], options: ['where' => "((pin_admin_state)::text = ANY ((ARRAY['AMBIGUOUS'::character varying, 'UNMATCHED'::character varying, 'OUTSIDE'::character varying])::text[]))"])]
#[ORM\UniqueConstraint(name: 'uniq_civic_subject_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
class CivicSubject
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    #[Groups(['subject:read'])]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: TreeNode::class)]
    #[ORM\JoinColumn(name: 'node_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private TreeNode $node;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\Column(length: 32, enumType: SubjectType::class)]
    #[Groups(['subject:read'])]
    private SubjectType $type;

    #[ORM\Column(length: 200)]
    #[Groups(['subject:read'])]
    private string $title;

    /** ADR-0008: unic global; atribuit de App\Seo\SlugRegistry (la persist și la schimbarea titlului). */
    #[ORM\Column(length: 100)]
    #[Groups(['subject:read'])]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['subject:read'])]
    private string $description;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $costEstimate = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $costCurrency = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $costEstimateScope = null;

    #[ORM\Column(length: 16, enumType: SubjectVisibility::class)]
    #[Groups(['subject:read'])]
    private SubjectVisibility $visibility = SubjectVisibility::PUBLISHED;

    #[ORM\Column(options: ['default' => false])]
    private bool $approvedContribution = false;

    public function markApprovedContribution(): void { $this->approvedContribution = true; }

    #[ORM\Column(length: 16, enumType: SubjectStage::class)]
    #[Groups(['subject:read'])]
    private SubjectStage $stage = SubjectStage::OPEN;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups(['subject:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups(['subject:read'])]
    private \DateTimeImmutable $updatedAt;

    /** Numărul reviziei curente (ADR-0007 §3); revizia 1 se creează împreună cu subiectul (Step 4.b2). */
    #[ORM\Column(options: ['default' => 1])]
    private int $revisionNumber = 1;

    /**
     * Localizarea (ADR-0011 p.9, p.11): punctul face parte din revizie; sursa și confirmarea nu.
     * Coordonatele se țin ca text zecimal normalizat (6 zecimale), ca instantaneul reviziei să compare exact.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: 6, nullable: true)]
    private ?string $locationLat = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: 6, nullable: true)]
    private ?string $locationLng = null;

    #[ORM\Column(length: 16, nullable: true, enumType: LocationSource::class)]
    private ?LocationSource $locationSource = null;

    /** Confirmarea unui administrator; se anulează la mutarea pinului. */
    #[ORM\Column(options: ['default' => false])]
    private bool $locationAdminConfirmed = false;

    /** Calculate de VoteResultCalculator din votul LOCATION pe revizia curentă, fără votul autorului. */
    #[ORM\Column(options: ['default' => false])]
    private bool $locationUsersConfirmed = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $locationConfirmations = 0;

    /** Primul vot înregistrat vreodată; blochează tipul, chiar dacă votul a fost retras (ADR-0007 §3). */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstVotedAt = null;

    /**
     * Încadrarea administrativă a pinului (ADR-0012 p.6, G.2) — partea „clasificare curentă” din p.7: calculată de
     * App\Geo\PinClassifier față de setul de limite activ, la punerea sau mutarea pinului. NULL = fără pin sau
     * fără set activ. Candidații: [{code, name, county, contains, distanceM}], în ordinea propunerii.
     */
    #[ORM\Column(length: 20, nullable: true, enumType: PinClassificationState::class)]
    private ?PinClassificationState $pinAdminState = null;

    #[ORM\ManyToOne(targetEntity: AdministrativeUnit::class)]
    #[ORM\JoinColumn(name: 'pin_admin_unit_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?AdministrativeUnit $pinAdminUnit = null;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $pinAdminCandidates = null;

    #[ORM\ManyToOne(targetEntity: BoundarySet::class)]
    #[ORM\JoinColumn(name: 'pin_admin_boundary_set_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?BoundarySet $pinAdminBoundarySet = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pinAdminComputedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'pin_admin_resolved_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $pinAdminResolvedBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pinAdminResolvedAt = null;

    public function __construct(TreeNode $node, User $author, SubjectType $type, string $title, string $description)
    {
        $this->id = new Ulid();
        $this->node = $node;
        $this->author = $author;
        $this->type = $type;
        $this->title = trim($title);
        $this->description = trim($description);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Ulid { return $this->id; }
    public function getNode(): TreeNode { return $this->node; }
    public function getAuthor(): User { return $this->author; }
    public function getType(): SubjectType { return $this->type; }
    public function getTitle(): string { return $this->title; }
    public function getSlug(): ?string { return $this->slug; }
    public function hasSlug(): bool { return $this->slug !== null; }
    /** Doar prin App\Seo\SlugRegistry. */
    public function assignSlug(string $slug): void { $this->slug = $slug; }

    /** URL-ul public canonic (ADR-0008): calea categoriei curente + slug-ul subiectului. */
    #[Groups(['subject:read'])]
    public function getCanonicalPath(): string
    {
        return '/categorie/'.$this->node->getSlugPath().'/subiect/'.$this->slug;
    }

    public function getDescription(): string { return $this->description; }
    public function getVisibility(): SubjectVisibility { return $this->visibility; }
    public function getStage(): SubjectStage { return $this->stage; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    /** Revizia curentă; FE o compară cu revizia votului propriu. */
    #[Groups(['subject:read'])]
    public function getRevisionNumber(): int { return $this->revisionNumber; }
    public function getFirstVotedAt(): ?\DateTimeImmutable { return $this->firstVotedAt; }

    /** Tipul nu se mai poate schimba după primul vot; formularul de editare dezactivează câmpul. */
    #[Groups(['subject:read'])]
    public function isTypeLocked(): bool { return $this->firstVotedAt !== null; }

    /** @return int numărul noii revizii curente */
    public function advanceRevision(): int
    {
        return ++$this->revisionNumber;
    }

    /** La primul vot înregistrat vreodată; nu se resetează la retragere (blocarea tipului rămâne). */
    public function markVoted(): void
    {
        $this->firstVotedAt ??= new \DateTimeImmutable();
    }

    #[Groups(['subject:read'])]
    public function getNodeId(): string { return $this->node->getId()->toBase32(); }

    /** Nodul fiecărui subiect e afișat în lista pe subarbore (decizie Step 2). */
    #[Groups(['subject:read'])]
    public function getNodeName(): string { return $this->node->getName(); }

    #[Groups(['subject:read'])]
    public function getAuthorId(): string { return $this->author->getId()->toBase32(); }

    #[Groups(['subject:read'])]
    public function getAuthorName(): string { return $this->author->getDisplayName(); }

    #[Groups(['subject:read'])]
    public function getCostEstimate(): ?string { return $this->type === SubjectType::PROPOSAL ? $this->costEstimate : null; }

    #[Groups(['subject:read'])]
    public function getCostCurrency(): ?string { return $this->type === SubjectType::PROPOSAL ? $this->costCurrency : null; }

    #[Groups(['subject:read'])]
    public function getCostEstimateScope(): ?string { return $this->type === SubjectType::PROPOSAL ? $this->costEstimateScope : null; }

    /** Stored values are needed for partial updates, including restoration after a type change. */
    public function storedEstimate(): array
    {
        return ['costEstimate' => $this->costEstimate, 'costCurrency' => $this->costCurrency, 'costEstimateScope' => $this->costEstimateScope];
    }

    public function setEstimate(?string $amount, ?string $currency, ?string $scope): void
    {
        $this->costEstimate = $amount;
        $this->costCurrency = $currency;
        $this->costEstimateScope = $scope;
    }

    public function isAuthoredBy(?User $user): bool
    {
        return $user !== null && $user->getId()->equals($this->author->getId());
    }

    public function edit(?string $title, ?string $description, ?SubjectType $type): void
    {
        if ($title !== null) $this->title = trim($title);
        if ($description !== null) $this->description = trim($description);
        if ($type !== null) $this->type = $type;
    }

    public function moderate(?SubjectStage $stage, ?SubjectVisibility $visibility): void
    {
        if ($stage !== null) $this->stage = $stage;
        if ($visibility !== null) $this->visibility = $visibility;
    }

    public function hasLocation(): bool { return $this->locationLat !== null; }

    /** @return array{locationLat: ?string, locationLng: ?string} partea de instantaneu a reviziei */
    public function storedLocation(): array
    {
        return ['locationLat' => $this->locationLat, 'locationLng' => $this->locationLng];
    }

    public function getLocationSource(): ?LocationSource { return $this->locationSource; }

    /** @param ?string $lat coordonate deja normalizate (SubjectLocation) */
    public function setLocation(?string $lat, ?string $lng, ?LocationSource $source): void
    {
        if ($lat !== $this->locationLat || $lng !== $this->locationLng) {
            // Alt punct: confirmarea veche nu mai spune nimic despre el.
            $this->locationAdminConfirmed = false;
        }
        $this->locationLat = $lat;
        $this->locationLng = $lng;
        $this->locationSource = $lat === null ? null : $source;
    }

    public function confirmLocationByAdmin(bool $confirmed): void
    {
        $this->locationAdminConfirmed = $confirmed && $this->hasLocation();
    }

    public function applyLocationVotes(int $confirmations, bool $usersConfirmed): void
    {
        $this->locationConfirmations = $confirmations;
        $this->locationUsersConfirmed = $usersConfirmed && $this->hasLocation();
    }

    /** @param list<array<string, mixed>> $candidates */
    public function applyPinClassification(PinClassificationState $state, ?AdministrativeUnit $unit, array $candidates, BoundarySet $set): void
    {
        if ($state === PinClassificationState::RESOLVED_MANUALLY) {
            throw new \LogicException('Rezolvarea manuală trece prin resolvePinClassification.');
        }
        if (($state === PinClassificationState::RESOLVED) !== ($unit !== null)) {
            throw new \LogicException('Doar o încadrare RESOLVED are UAT-ul determinat automat.');
        }
        $this->pinAdminState = $state;
        $this->pinAdminUnit = $unit;
        $this->pinAdminCandidates = $candidates;
        $this->pinAdminBoundarySet = $set;
        $this->pinAdminComputedAt = new \DateTimeImmutable();
        $this->pinAdminResolvedBy = null;
        $this->pinAdminResolvedAt = null;
    }

    /** Fără pin sau fără set de limite activ: nicio încadrare. */
    public function clearPinClassification(): void
    {
        $this->pinAdminState = null;
        $this->pinAdminUnit = null;
        $this->pinAdminCandidates = null;
        $this->pinAdminBoundarySet = null;
        $this->pinAdminComputedAt = null;
        $this->pinAdminResolvedBy = null;
        $this->pinAdminResolvedAt = null;
    }

    /**
     * ADR-0012 p.6 (G.2b): rezolvarea manuală a unei încadrări nedeterminate — doar AMBIGUOUS/UNMATCHED și doar
     * un candidat din listă. Candidații rămân (proveniența alegerii). Mutarea pinului o anulează (classify).
     *
     * @throws \DomainException cu codul motivului: not_resolvable | not_a_candidate
     */
    public function resolvePinClassification(AdministrativeUnit $unit, User $by): void
    {
        if (!\in_array($this->pinAdminState, [PinClassificationState::AMBIGUOUS, PinClassificationState::UNMATCHED], true)) {
            throw new \DomainException('not_resolvable');
        }
        if (!\in_array($unit->getCode(), array_column($this->getPinAdminCandidates(), 'code'), true)) {
            throw new \DomainException('not_a_candidate');
        }
        $this->pinAdminState = PinClassificationState::RESOLVED_MANUALLY;
        $this->pinAdminUnit = $unit;
        $this->pinAdminResolvedBy = $by;
        $this->pinAdminResolvedAt = new \DateTimeImmutable();
    }

    public function getPinAdminResolvedBy(): ?User { return $this->pinAdminResolvedBy; }
    public function getPinAdminResolvedAt(): ?\DateTimeImmutable { return $this->pinAdminResolvedAt; }

    public function getPinAdminState(): ?PinClassificationState { return $this->pinAdminState; }
    public function getPinAdminUnit(): ?AdministrativeUnit { return $this->pinAdminUnit; }
    /** @return list<array<string, mixed>> */
    public function getPinAdminCandidates(): array { return $this->pinAdminCandidates ?? []; }
    public function getPinAdminBoundarySet(): ?BoundarySet { return $this->pinAdminBoundarySet; }

    /**
     * Încadrarea administrativă a pinului, pentru afișare (ADR-0012 p.6). Candidații apar doar la stările
     * nedeterminate; UAT-ul și județul, doar când încadrarea e determinată.
     *
     * @return array{state: string, unit: ?array{code: string, name: string}, county: ?array{code: string, name: string}, candidates: list<array<string, mixed>>, provisional: bool}|null
     */
    public function getPinAdministrative(): ?array
    {
        if ($this->pinAdminState === null) {
            return null;
        }
        $unit = $this->pinAdminUnit;
        $county = $unit?->getParent();

        return [
            'state' => $this->pinAdminState->value,
            'unit' => $unit === null ? null : ['code' => $unit->getCode(), 'name' => $unit->getDisplayName()],
            'county' => $county === null ? null : ['code' => $county->getCode(), 'name' => $county->getDisplayName()],
            'candidates' => $this->pinAdminState->isDetermined() ? [] : $this->getPinAdminCandidates(),
            // ADR-0012 p.2: setul de limite activ e provizoriu (precizie neconfirmată oficial)
            'provisional' => $this->pinAdminBoundarySet?->getAccuracyStatus() === \App\Geo\BoundaryAccuracyStatus::PROVISIONAL,
        ];
    }

    /** @return array{lat: float, lng: float, source: string, confirmed: bool, confirmedBy: ?string, confirmations: int, administrative: ?array}|null */
    #[Groups(['subject:read'])]
    public function getLocation(): ?array
    {
        if (!$this->hasLocation()) {
            return null;
        }
        $confirmedBy = $this->locationAdminConfirmed ? 'ADMIN' : ($this->locationUsersConfirmed ? 'USERS' : null);

        return [
            'lat' => (float) $this->locationLat,
            'lng' => (float) $this->locationLng,
            'source' => ($this->locationSource ?? LocationSource::MANUAL)->value,
            'confirmed' => $confirmedBy !== null,
            'confirmedBy' => $confirmedBy,
            'confirmations' => $this->locationConfirmations,
            'administrative' => $this->getPinAdministrative(),
        ];
    }
}
