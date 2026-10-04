<?php

declare(strict_types=1);

namespace App\Dto;

use App\Subject\SubjectType;
use Symfony\Component\Validator\Constraints as Assert;

final class CivicSubjectCreateInput
{
    #[Assert\NotBlank]
    #[Assert\Ulid]
    public string $node = '';

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [self::class, 'types'], message: 'Tip necunoscut.')]
    public string $type = '';

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(min: 3, max: 200, normalizer: 'trim')]
    public string $title = '';

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 10000)]
    public string $description = '';

    /** @return list<string> */
    public static function types(): array
    {
        return array_map(static fn (SubjectType $t) => $t->value, SubjectType::cases());
    }
}
