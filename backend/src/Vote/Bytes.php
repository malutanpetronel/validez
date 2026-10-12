<?php

declare(strict_types=1);

namespace App\Vote;

/** BYTEA vine din PDO ca resursă; entitățile expun valorile criptografice ca string binar de 32 de octeți. */
final class Bytes
{
    public const LENGTH = 32;

    public static function read(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (\is_resource($value)) {
            $value = stream_get_contents($value, -1, 0);
        }

        return (string) $value;
    }

    public static function require32(string $value, string $field): string
    {
        if (\strlen($value) !== self::LENGTH) {
            throw new \InvalidArgumentException(sprintf('%s trebuie să aibă %d octeți.', $field, self::LENGTH));
        }

        return $value;
    }
}
