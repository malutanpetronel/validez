<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Anthropic Messages API cu imagine base64. Răspunsul structurat se obține forțând un singur tool („raspuns”) cu
 * schema dată ca input_schema: blocul tool_use conține obiectul deja decodat.
 */
final class AnthropicAnalyzer extends HttpAnalyzer
{
    public const URL = 'https://api.anthropic.com/v1/messages';
    public const API_VERSION = '2023-06-01';
    private const TOOL = 'raspuns';

    public function __construct(
        HttpClientInterface $http,
        #[Autowire('%env(default::ANTHROPIC_API_KEY)%')] ?string $key = null,
    ) {
        parent::__construct($http, $key);
    }

    public function provider(): string { return self::ANTHROPIC; }

    public function analyze(string $image, string $mime, string $prompt, array $schema, string $model): AiResult
    {
        [$data, $duration] = $this->post(self::URL, ['x-api-key' => (string) $this->key, 'anthropic-version' => self::API_VERSION], [
            'model' => $model,
            'max_tokens' => $this->maxOutputTokens,
            'tools' => [['name' => self::TOOL, 'description' => 'Întoarce rezultatul cerut, conform schemei.', 'input_schema' => $schema]],
            'tool_choice' => ['type' => 'tool', 'name' => self::TOOL],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($image)]],
                    ['type' => 'text', 'text' => $prompt],
                ],
            ]],
        ]);

        if (($data['stop_reason'] ?? null) === 'max_tokens') {
            throw AiException::short('anthropic: răspuns trunchiat (limita de tokeni).');
        }
        $object = null;
        foreach ($data['content'] ?? [] as $block) {
            if (\is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === self::TOOL) {
                $object = $block['input'] ?? null;
                break;
            }
        }
        if (!\is_array($object) || ($object !== [] && array_is_list($object))) {
            throw AiException::short('anthropic: răspunsul nu conține obiectul cerut.');
        }

        return new AiResult(self::ANTHROPIC, (string) ($data['model'] ?? $model), $object,
            (int) ($data['usage']['input_tokens'] ?? 0), (int) ($data['usage']['output_tokens'] ?? 0), $duration);
    }
}
