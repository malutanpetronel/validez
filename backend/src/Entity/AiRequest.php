<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * ADR-0014 p.12: jurnalul cererilor AI — sursa pentru cote, costuri și depanare. Scris prin DBAL de
 * SubjectSuggestionService (ca CategorySuggestion); entitatea ține doar schema. Nu conține niciodată cheia API sau
 * fotografia; `response` servește cache-ului pe hash și se golește după 24 de ore.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_request')]
#[ORM\Index(name: 'idx_ai_request_hash', columns: ['request_hash'])]
#[ORM\Index(name: 'idx_ai_request_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_ai_request_requester', columns: ['requester_id', 'created_at'])]
class AiRequest
{
    public const PENDING = 'PENDING';
    public const SUCCESS = 'SUCCESS';
    public const ERROR = 'ERROR';

    public const PURPOSE_SUBJECT = 'SUBJECT_SUGGESTION';

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $requester = null;

    #[ORM\Column(length: 20)]
    private string $provider;

    #[ORM\Column(length: 100)]
    private string $model;

    #[ORM\Column(length: 30)]
    private string $purpose = self::PURPOSE_SUBJECT;

    #[ORM\Column(length: 16)]
    private string $status = self::PENDING;

    /** sha256(furnizor | model | prompt | sha256(imagine)). */
    #[ORM\Column(length: 64)]
    private string $requestHash;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $inputTokens = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $outputTokens = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
    private ?string $costUsd = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $durationMs = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $response = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    private function __construct()
    {
        $this->id = new Ulid();
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function hash(string $provider, string $model, string $prompt, string $image): string
    {
        return hash('sha256', $provider."\0".$model."\0".$prompt."\0".hash('sha256', $image));
    }
}
