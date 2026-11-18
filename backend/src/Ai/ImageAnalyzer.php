<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * ADR-0014 (preluat din ArtaNFT ADR-0002): un furnizor AI cu vedere care, dintr-o imagine și un prompt, întoarce un
 * obiect JSON conform schemei date. Implementări: AnthropicAnalyzer, OpenAiAnalyzer. Cheile vin doar din env.
 */
interface ImageAnalyzer
{
    public const ANTHROPIC = 'anthropic';
    public const OPENAI = 'openai';

    public function provider(): string;

    /** Cheia API e setată în env (panoul arată doar „configurată / lipsă”, niciodată cheia). */
    public function configured(): bool;

    /**
     * @param string $image conținutul binar al variantei reprocesate (fără metadate), nu fișierul încărcat
     * @param array<string, mixed> $schema JSON Schema de tip obiect; pentru OpenAI (strict) toate proprietățile
     *                                     sunt în `required`, cu `additionalProperties: false`
     * @throws AiException
     */
    public function analyze(string $image, string $mime, string $prompt, array $schema, string $model): AiResult;
}
