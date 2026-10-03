<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class TreeNodeCreateInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    public string $name = '';

    /** ULID (base32) al parintelui; null = radacina. */
    #[Assert\Ulid]
    public ?string $parent = null;
}
