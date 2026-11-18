<?php

declare(strict_types=1);

namespace App\Ai;

/** Furnizorii disponibili, după cod (ImageAnalyzer::ANTHROPIC / OPENAI). */
final class AiAnalyzers
{
    /** @var array<string, ImageAnalyzer> */
    private array $byProvider;

    public function __construct(AnthropicAnalyzer $anthropic, OpenAiAnalyzer $openai)
    {
        $this->byProvider = [$anthropic->provider() => $anthropic, $openai->provider() => $openai];
    }

    public function get(string $provider): ?ImageAnalyzer
    {
        return $this->byProvider[$provider] ?? null;
    }

    /** @return array<string, ImageAnalyzer> */
    public function all(): array
    {
        return $this->byProvider;
    }
}
