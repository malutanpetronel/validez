<?php

declare(strict_types=1);

namespace App\Subject;

/** Vizibilitate separată de stadiu: PENDING și HIDDEN sunt vizibile doar autorului și administratorilor (ADR-0006). */
enum SubjectVisibility: string
{
    case PUBLISHED = 'PUBLISHED';
    case PENDING = 'PENDING';
    case HIDDEN = 'HIDDEN';
}
