<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\JsonObjectType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Agregat public per (subiect, tip) (ADR-0007 §3): numărătoarea pe revizia curentă, pe valori,
 * și numărul voturilor nereconfirmate de pe revizii anterioare. Se poate recalcula din `vote`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vote_result')]
class VoteResult
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: CivicSubject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', onDelete: 'RESTRICT')]
    private CivicSubject $subject;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: VoteType::class)]
    #[ORM\JoinColumn(name: 'vote_type_code', referencedColumnName: 'code', onDelete: 'RESTRICT')]
    private VoteType $voteType;

    /** @var array<int, int> valoare => număr de voturi pe revizia curentă; obiect JSON, nu listă */
    #[ORM\Column(type: JsonObjectType::NAME, options: ['default' => '{}'])]
    private array $currentCounts = [];

    #[ORM\Column(options: ['default' => 0])]
    private int $currentTotal = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $staleTotal = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(CivicSubject $subject, VoteType $voteType)
    {
        $this->subject = $subject;
        $this->voteType = $voteType;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return array<int, int> */
    public function getCurrentCounts(): array { return $this->currentCounts; }
    public function getCurrentTotal(): int { return $this->currentTotal; }
    public function getStaleTotal(): int { return $this->staleTotal; }

    /**
     * Înlocuiește agregatul cu o recalculare; verifică consistența contoarelor cu totalul.
     *
     * @param array<int, int> $currentCounts
     */
    public function replace(array $currentCounts, int $staleTotal): void
    {
        foreach ($currentCounts as $value => $count) {
            if (!\is_int($value) || !$this->voteType->accepts($value) || $count < 0) {
                throw new \InvalidArgumentException('Contor invalid pentru tipul '.$this->voteType->getCode().'.');
            }
        }
        if ($staleTotal < 0) {
            throw new \InvalidArgumentException('Totalul nereconfirmat nu poate fi negativ.');
        }
        $counts = array_filter($currentCounts, static fn (int $c): bool => $c > 0);
        ksort($counts);
        $this->currentCounts = $counts;
        $this->currentTotal = array_sum($counts);
        $this->staleTotal = $staleTotal;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
