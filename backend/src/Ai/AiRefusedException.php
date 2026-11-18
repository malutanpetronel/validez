<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Cererea nu pleacă la furnizor: AI oprit, cheie lipsă, limită atinsă. Mesajul e afișabil în formular;
 * `status` e codul HTTP potrivit (429 la limită, 503 când funcția nu e disponibilă).
 */
final class AiRefusedException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function limit(string $message): self { return new self($message, 429); }

    public static function unavailable(string $message): self { return new self($message, 503); }
}
