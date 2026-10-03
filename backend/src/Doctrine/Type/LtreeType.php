<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Coloana PostgreSQL `ltree` (ADR-0001), reprezentata in PHP ca string ("A.B.C").
 *
 * Operatorii ltree (<@, @>) nu au echivalent DQL: interogarile pe subarbore/stramosi
 * se scriu in SQL nativ, in repository (Step 1.3).
 */
final class LtreeType extends Type
{
    public const NAME = 'ltree';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        if (!$platform instanceof PostgreSQLPlatform) {
            throw new \LogicException('Tipul ltree necesita PostgreSQL (extensia ltree).');
        }

        return 'ltree';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
