<?php

declare(strict_types=1);

namespace App\Vote;

use App\Subject\SubjectType;

/** Tipurile de vot implicite la crearea subiectului (ADR-0007 §1). Aceeași regulă e scrisă în SQL în migrarea Step 4.b1. */
final class DefaultVoteTypes
{
    public const VALIDITY = 'VALIDITY';
    public const YES_NO = 'YES_NO';
    public const IMPORTANCE = 'IMPORTANCE';

    /** @return list<string> */
    public static function forSubjectType(SubjectType $type): array
    {
        return match ($type) {
            SubjectType::ISSUE => [self::VALIDITY, self::IMPORTANCE],
            SubjectType::PROPOSAL => [self::YES_NO, self::IMPORTANCE],
            SubjectType::PETITION => [self::YES_NO],
            default => [],
        };
    }
}
