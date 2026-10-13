<?php

declare(strict_types=1);

namespace App\Seo;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Reguli pure pentru slug-uri (ADR-0008), fără bază de date — folosite și de migrarea care completează
 * slug-urile existente, ca rezultatul să fie identic cu cel din aplicație.
 */
final class SlugGenerator
{
    /** Lungimea maximă a slug-ului de bază; sufixul `-N` se adaugă peste (coloana are 100). */
    public const MAX_LENGTH = 80;

    /** `subiect` separă calea categoriei de slug-ul subiectului în URL: nicio categorie nu îl poate avea. */
    public const RESERVED_NODE_SLUGS = ['subiect'];

    public const NODE_FALLBACK = 'categorie';
    public const SUBJECT_FALLBACK = 'subiect';

    /** „Școală, Țară!” → `scoala-tara`; tăiat la cratimă peste MAX_LENGTH; gol → $fallback. */
    public static function base(string $text, string $fallback): string
    {
        $slug = (string) (new AsciiSlugger('ro'))->slug($text)->lower();
        if (\strlen($slug) > self::MAX_LENGTH) {
            $cut = substr($slug, 0, self::MAX_LENGTH);
            $dash = strrpos($cut, '-');
            // Tăiem la ultima cratimă doar dacă nu pierdem prea mult; altfel tăiem în cuvânt.
            $slug = rtrim($dash !== false && $dash >= 40 ? substr($cut, 0, $dash) : $cut, '-');
        }

        return $slug === '' ? $fallback : $slug;
    }

    /**
     * Primul slug liber din familia $base: `base`, `base-2`, `base-3`…
     *
     * @param iterable<string> $taken slug-urile deja ocupate (în același spațiu de unicitate)
     * @param list<string>     $reserved
     */
    public static function firstFree(string $base, iterable $taken, array $reserved = []): string
    {
        $blocked = array_fill_keys($reserved, true);
        foreach ($taken as $slug) {
            $blocked[$slug] = true;
        }
        if (!isset($blocked[$base])) {
            return $base;
        }
        for ($i = 2; ; ++$i) {
            if (!isset($blocked[$base.'-'.$i])) {
                return $base.'-'.$i;
            }
        }
    }
}
