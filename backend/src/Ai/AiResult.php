<?php

declare(strict_types=1);

namespace App\Ai;

/** Rezultatul unui apel reușit: obiectul JSON decodat + consumul raportat de furnizor (pentru cote și costuri). */
final class AiResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly array $data,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $durationMs,
    ) {
    }
}
