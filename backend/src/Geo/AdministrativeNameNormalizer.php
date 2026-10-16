<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * ADR-0012 p.2: forma de afișare a unei denumiri din SIRUTA. Doar transformările decise:
 *  - Unicode NFC;
 *  - sedilă → virgulă: Ş ş Ţ ţ (U+015E/F, U+0162/3) → Ș ș Ț ț (U+0218/9, U+021A/B);
 *  - spații repetate (inclusiv tab/NBSP) → un singur spațiu, fără spații la capete.
 * Literele, cratimele, prefixele și majusculele rămân ca în sursă; denumirea originală se păstrează separat.
 */
final class AdministrativeNameNormalizer
{
    private const COMMA_BELOW = ['Ş' => 'Ș', 'ş' => 'ș', 'Ţ' => 'Ț', 'ţ' => 'ț'];

    public static function display(string $original): string
    {
        $name = \Normalizer::normalize($original, \Normalizer::FORM_C);
        if ($name === false) {
            throw new \InvalidArgumentException('Denumire cu Unicode invalid.');
        }
        $name = strtr($name, self::COMMA_BELOW);
        $name = preg_replace('/[\s\x{00A0}]+/u', ' ', $name);

        return trim((string) $name);
    }
}
