<?php

declare(strict_types=1);

namespace App\Media;

/** Mesajul ajunge la utilizator (422/413), deci e în română și fără detalii interne. */
final class InvalidImageException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $tooLarge = false)
    {
        parent::__construct($message);
    }
}
