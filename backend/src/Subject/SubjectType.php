<?php

declare(strict_types=1);

namespace App\Subject;

/** Tipurile de CivicSubject (ADR-0002). */
enum SubjectType: string
{
    case ISSUE = 'ISSUE';
    case PROPOSAL = 'PROPOSAL';
    case PROJECT = 'PROJECT';
    case PROMISE = 'PROMISE';
    case ELECTORAL_EVALUATION = 'ELECTORAL_EVALUATION';
    case PETITION = 'PETITION';
    case TOPIC_EVALUATION = 'TOPIC_EVALUATION';

    /** Decizie Step 2: utilizatorii creează doar ISSUE/PROPOSAL/PETITION; restul doar adminii. */
    public function isAdminOnly(): bool
    {
        return !\in_array($this, [self::ISSUE, self::PROPOSAL, self::PETITION], true);
    }
}
