<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Tip de vot configurabil (ADR-0003): un tip nou e un rând nou, fără schimbare de schemă. Etichetele stau în FE. */
#[ORM\Entity]
#[ORM\Table(name: 'vote_type')]
class VoteType
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $minValue;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $maxValue;

    public function __construct(string $code, int $minValue, int $maxValue)
    {
        if ($minValue > $maxValue) {
            throw new \InvalidArgumentException('min_value trebuie să fie <= max_value.');
        }
        $this->code = $code;
        $this->minValue = $minValue;
        $this->maxValue = $maxValue;
    }

    public function getCode(): string { return $this->code; }
    public function getMinValue(): int { return $this->minValue; }
    public function getMaxValue(): int { return $this->maxValue; }

    public function accepts(int $value): bool
    {
        return $value >= $this->minValue && $value <= $this->maxValue;
    }
}
