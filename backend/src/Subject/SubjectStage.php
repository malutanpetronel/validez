<?php

declare(strict_types=1);

namespace App\Subject;

/** Stadiul subiectului; îl schimbă doar adminul. */
enum SubjectStage: string
{
    case OPEN = 'OPEN';
    case IN_PROGRESS = 'IN_PROGRESS';
    case RESOLVED = 'RESOLVED';
    case CLOSED = 'CLOSED';
}
