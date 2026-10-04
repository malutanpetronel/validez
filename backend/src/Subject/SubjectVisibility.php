<?php

declare(strict_types=1);

namespace App\Subject;

/** Vizibilitate (separată de stadiu): HIDDEN = văzut doar de admin și de autor. Moderarea (Step 5) va adăuga reguli. */
enum SubjectVisibility: string
{
    case PUBLISHED = 'PUBLISHED';
    case HIDDEN = 'HIDDEN';
}
