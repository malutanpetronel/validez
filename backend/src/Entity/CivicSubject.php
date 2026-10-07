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
use App\Repository\CivicSubjectRepository;
use App\State\CivicSubjectCreateProcessor;
use App\State\CivicSubjectProvider;
use App\State\CivicSubjectUpdateProcessor;
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
 * `status` din domain.puml e împărțit în visibility (PUBLISHED/HIDDEN) și stage (OPEN…CLOSED) - decizie Step 2.
 * Regulile de acces: CivicSubjectVoter + procesoarele (tipuri doar-admin, stage/visibility doar admin).
 */
#[ApiResource(
    operations: [
        // ?node={ulid} = subarborele; &scope=direct = doar nodul; filtre ?type=, ?stage=; 20/pagină.
        new GetCollection(provider: CivicSubjectProvider::class, paginationItemsPerPage: 20),
        new Get(provider: CivicSubjectProvider::class),
        new Post(security: "is_granted('ROLE_USER')", input: CivicSubjectCreateInput::class, processor: CivicSubjectCreateProcessor::class),
        new Patch(security: "is_granted('SUBJECT_EDIT', object)", provider: CivicSubjectProvider::class, input: CivicSubjectUpdateInput::class, processor: CivicSubjectUpdateProcessor::class),
    ],
    normalizationContext: ['groups' => ['subject:read']],
)]
#[ORM\Entity(repositoryClass: CivicSubjectRepository::class)]
#[ORM\Table(name: 'civic_subject')]
#[ORM\Index(name: 'idx_civic_subject_node_created', columns: ['node_id', 'created_at'])]
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

    #[ORM\Column(length: 16, enumType: SubjectStage::class)]
    #[Groups(['subject:read'])]
    private SubjectStage $stage = SubjectStage::OPEN;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups(['subject:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups(['subject:read'])]
    private \DateTimeImmutable $updatedAt;

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
    public function getDescription(): string { return $this->description; }
    public function getVisibility(): SubjectVisibility { return $this->visibility; }
    public function getStage(): SubjectStage { return $this->stage; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

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
}
