<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** OpenAI Chat Completions cu imagine (data URI) și răspuns structurat (json_schema, strict). */
final class OpenAiAnalyzer extends HttpAnalyzer
{
    public const URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        HttpClientInterface $http,
        #[Autowire('%env(default::OPENAI_API_KEY)%')] ?string $key = null,
    ) {
        // gpt-6-luna e model cu „reasoning”: tokenii de gândire intră în limita de ieșire, deci limita e mai largă.
        parent::__construct($http, $key, 4000);
    }

    public function provider(): string { return self::OPENAI; }

    public function analyze(string $image, string $mime, string $prompt, array $schema, string $model): AiResult
    {
        [$data, $duration] = $this->post(self::URL, ['Authorization' => 'Bearer '.$this->key], [
            'model' => $model,
            'max_completion_tokens' => $this->maxOutputTokens,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($image)]],
                ],
            ]],
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'raspuns', 'strict' => true, 'schema' => $schema]],
        ]);

        $message = $data['choices'][0]['message'] ?? null;
        if (!\is_array($message)) {
            throw AiException::short('openai: răspuns fără mesaj.');
        }
        if (\is_string($message['refusal'] ?? null) && $message['refusal'] !== '') {
            throw AiException::short('openai: modelul a refuzat — '.$message['refusal']);
        }
        if (($data['choices'][0]['finish_reason'] ?? null) === 'length') {
            throw AiException::short('openai: răspuns trunchiat (limita de tokeni).');
        }

        return new AiResult(self::OPENAI, (string) ($data['model'] ?? $model), self::jsonObject($message['content'] ?? null, 'openai'),
            (int) ($data['usage']['prompt_tokens'] ?? 0), (int) ($data['usage']['completion_tokens'] ?? 0), $duration);
    }
}
