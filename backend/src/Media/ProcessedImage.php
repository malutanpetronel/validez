<?php

declare(strict_types=1);

namespace App\Media;

final class ProcessedImage
{
    public function __construct(
        public readonly string $display,
        public readonly string $thumb,
        public readonly int $width,
        public readonly int $height,
    ) {
    }
}
