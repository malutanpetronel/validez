<?php

declare(strict_types=1);

namespace App\Media;

final class ProcessedImage
{
    public function __construct(
        public readonly string $display,
        public readonly string $thumb,
        /** JPEG 1200×630 pentru og:image. */
        public readonly string $og,
        public readonly int $width,
        public readonly int $height,
        /** WebP 1200 px, compresie mai mare — coperta din liste și din pagina subiectului (audit viteză 18.11.2026). */
        public readonly string $card = '',
    ) {
    }
}
