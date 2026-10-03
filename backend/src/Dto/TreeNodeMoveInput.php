<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * parent: ULID-ul noului parinte, null = radacina (explicit si pentru simpla reordonare).
 * position: indexul printre fratii de la destinatie, fara nodul mutat; null = la capat.
 */
final class TreeNodeMoveInput
{
    #[Assert\Ulid]
    public ?string $parent = null;

    #[Assert\PositiveOrZero]
    public ?int $position = null;
}
