<?php

declare(strict_types=1);

namespace App\Seo;

/** Etichetele afișate în shell-ul SEO — aceleași ca în frontend/src/subjects/labels.js. */
final class Labels
{
    public const TYPES = [
        'ISSUE' => 'Problemă',
        'PROPOSAL' => 'Propunere',
        'PETITION' => 'Petiție',
        'PROJECT' => 'Proiect',
        'PROMISE' => 'Promisiune',
        'ELECTORAL_EVALUATION' => 'Evaluare aleși',
        'TOPIC_EVALUATION' => 'Evaluare temă',
    ];

    public const STAGES = ['OPEN' => 'Deschis', 'IN_PROGRESS' => 'În lucru', 'RESOLVED' => 'Rezolvat', 'CLOSED' => 'Închis'];

    /** Text simplu pe un rând, tăiat la cuvânt (~160 de caractere), pentru meta description. */
    public static function excerpt(string $text, int $max = 160): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($flat) <= $max) {
            return $flat;
        }
        $cut = mb_substr($flat, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        return ($space !== false && $space > 80 ? mb_substr($cut, 0, $space) : $cut).'…';
    }
}
