<?php

declare(strict_types=1);

namespace App\Dto;

use App\Subject\SubjectStage;
use App\Subject\SubjectVisibility;
use Symfony\Component\Validator\Constraints as Assert;

/** Merge-patch: câmpurile lipsă rămân null și nu se schimbă. stage/visibility: doar adminul. */
final class CivicSubjectUpdateInput
{
    #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
    #[Assert\Length(min: 3, max: 200, normalizer: 'trim')]
    public ?string $title = null;

    #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
    #[Assert\Length(max: 10000)]
    public ?string $description = null;

    #[Assert\Choice(callback: [CivicSubjectCreateInput::class, 'types'], message: 'Tip necunoscut.')]
    public ?string $type = null;

    #[Assert\Choice(callback: [self::class, 'stages'], message: 'Stadiu necunoscut.')]
    public ?string $stage = null;

    #[Assert\Choice(callback: [self::class, 'visibilities'], message: 'Vizibilitate necunoscută.')]
    public ?string $visibility = null;

    // Uninitialized means omitted; explicit null clears a field in merge-patch.
    public ?string $costEstimate;
    public ?string $costCurrency;
    public ?string $costEstimateScope;

    /** @return list<string> */
    public static function stages(): array
    {
        return array_map(static fn (SubjectStage $s) => $s->value, SubjectStage::cases());
    }

    /** @return list<string> */
    public static function visibilities(): array
    {
        return array_map(static fn (SubjectVisibility $v) => $v->value, SubjectVisibility::cases());
    }
}
