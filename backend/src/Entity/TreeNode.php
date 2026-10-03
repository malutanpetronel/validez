<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Doctrine\Type\LtreeType;
use App\Dto\TreeNodeCreateInput;
use App\Dto\TreeNodeRenameInput;
use App\Repository\TreeNodeRepository;
use App\State\TreeNodeCreateProcessor;
use App\State\TreeNodeProvider;
use App\State\TreeNodeRenameProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Structura pura a arborelui (ADR-0001, ADR-0002): fara continut votabil.
 *
 * - id: ULID generat in constructor, inainte de persistare, ca path sa poata fi construit imediat.
 * - path: ltree din ULID-urile (base32) stramosilor + al nodului; redenumirea nu il afecteaza.
 * - position: ordinea intre frati. Unicitatea (inclusiv intre radacini) si position >= 0 sunt
 *   impuse in baza de date; pozitiile dense 0..n sunt regula serviciului de mutare/ordonare (1.3).
 *
 * Constrangerile pe care Doctrine nu le poate exprima (GiST, NULLS NOT DISTINCT, DEFERRABLE, CHECK)
 * sunt in migrare; maparea declara doar indexurile cu acelasi nume si aceleasi coloane,
 * ca diff-ul Doctrine sa ramana gol.
 *
 * Amanate explicit: created_by (odata cu modelul User), type (semantica nedecisa).
 */
#[ApiResource(
    operations: [
        // Un nivel: radacini (fara ?parent) sau copiii lui ?parent={ulid}. Fara paginare: incarcare pe ramuri.
        new GetCollection(paginationEnabled: false, provider: TreeNodeProvider::class),
        new Get(provider: TreeNodeProvider::class),
        new Post(security: "is_granted('TREE_EDIT')", input: TreeNodeCreateInput::class, processor: TreeNodeCreateProcessor::class),
        new Patch(security: "is_granted('TREE_EDIT')", input: TreeNodeRenameInput::class, processor: TreeNodeRenameProcessor::class),
    ],
    normalizationContext: ['groups' => ['tree:read']],
)]
#[ORM\Entity(repositoryClass: TreeNodeRepository::class)]
#[ORM\Table(name: 'tree_node')]
#[ORM\Index(name: 'idx_tree_node_path_gist', columns: ['path'])]
#[ORM\UniqueConstraint(name: 'uniq_tree_node_parent_position', columns: ['parent_id', 'position'])]
#[ORM\HasLifecycleCallbacks]
class TreeNode
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    #[Groups(['tree:read'])]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?TreeNode $parent;

    #[ORM\Column(type: LtreeType::NAME)]
    private string $path;

    #[ORM\Column(length: 255)]
    #[Groups(['tree:read'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['tree:read'])]
    #[Assert\PositiveOrZero]
    private int $position;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** Calculat de repository (o interogare per nivel), nu persistat. */
    private ?bool $hasChildren = null;

    public function __construct(string $name, ?TreeNode $parent = null, int $position = 0)
    {
        if ($position < 0) {
            throw new \InvalidArgumentException('Pozitia nu poate fi negativa.');
        }

        $this->id = new Ulid();
        $this->parent = $parent;
        $this->name = trim($name);
        $this->position = $position;
        $this->path = self::buildPath($parent, $this->id);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /** Eticheta ltree a unui nod: ULID in base32 (26 caractere [0-9A-Z]). */
    public static function label(Ulid $id): string
    {
        return $id->toBase32();
    }

    public static function buildPath(?TreeNode $parent, Ulid $id): string
    {
        return $parent === null ? self::label($id) : $parent->getPath().'.'.self::label($id);
    }

    public function rename(string $name): void
    {
        $this->name = trim($name);
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getParent(): ?TreeNode
    {
        return $this->parent;
    }

    /** ULID-ul parintelui ca string base32 (FE pastreaza ULID ca string; fara parser numeric). */
    #[Groups(['tree:read'])]
    public function getParentId(): ?string
    {
        return $this->parent?->getId()->toBase32();
    }

    #[Groups(['tree:read'])]
    public function getHasChildren(): ?bool
    {
        return $this->hasChildren;
    }

    public function setHasChildren(bool $hasChildren): void
    {
        $this->hasChildren = $hasChildren;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /** Adancimea: 0 pentru radacina. */
    #[Groups(['tree:read'])]
    public function getDepth(): int
    {
        return substr_count($this->path, '.');
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
