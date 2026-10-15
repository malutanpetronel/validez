<?php

declare(strict_types=1);

namespace App\Subject;

/** ADR-0011 p.12: media autorilor sub moderare (ADR-0006) așteaptă aprobare individual. */
enum MediaStatus: string
{
    case PUBLISHED = 'PUBLISHED';
    case PENDING = 'PENDING';
}
