<?php

declare(strict_types=1);

namespace App\Vote;

/**
 * Serializare canonică pentru commitmenturi și hashuri (ADR-0007 §7): eticheta de domeniu, apoi câmpurile
 * în ordine fixă. Fiecare câmp: 0x00 pentru null, altfel 0x01 + lungime (4 octeți, big-endian) + octeți.
 * Fără ambiguitate între câmpuri adiacente și între null și șirul gol.
 */
final class CanonicalEncoder
{
    /** @param list<string|int|null> $fields */
    public static function encode(string $domain, array $fields): string
    {
        $out = self::field($domain);
        foreach ($fields as $field) {
            $out .= self::field($field === null ? null : (string) $field);
        }

        return $out;
    }

    /** @param list<string|int|null> $fields */
    public static function sha256(string $domain, array $fields): string
    {
        return hash('sha256', self::encode($domain, $fields), true);
    }

    private static function field(?string $value): string
    {
        return $value === null ? "\x00" : "\x01".pack('N', \strlen($value)).$value;
    }
}
