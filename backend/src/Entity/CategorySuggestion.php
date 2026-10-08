<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

// The controller uses DBAL for atomic review and duplicate checks under TreeLock.
#[ORM\Entity]
#[ORM\Table(name: 'category_suggestion')]
#[ORM\Index(name: 'idx_category_suggestion_author', columns: ['author_id'])]
#[ORM\Index(name: 'idx_category_suggestion_parent', columns: ['parent_id'])]
#[ORM\Index(name: 'idx_category_suggestion_node', columns: ['node_id'])]
class CategorySuggestion
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;
    #[ORM\ManyToOne(targetEntity: TreeNode::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TreeNode $parent = null;
    #[ORM\Column(length: 120)]
    private string $name;
    #[ORM\Column(type: Types::TEXT)]
    private string $reason;
    #[ORM\Column(length: 16)]
    private string $status;
    #[ORM\ManyToOne(targetEntity: TreeNode::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TreeNode $node = null;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;
}
