<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Eșec al unui apel AI (cheie lipsă, eroare HTTP, răspuns invalid, refuz). Mesajul ajunge în ai_request.error:
 * nu conține niciodată cheia API sau imaginea.
 */
final class AiException extends \RuntimeException
{
    public static function short(string $message): self
    {
        return new self(mb_substr($message, 0, 500));
    }
}
