<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Partea comună a furnizorilor HTTP: POST JSON cu timeout, erori fără secrete, durata măsurată. */
abstract class HttpAnalyzer implements ImageAnalyzer
{
    public function __construct(
        protected readonly HttpClientInterface $http,
        protected readonly ?string $key = null,
        protected readonly int $maxOutputTokens = 1500,
        protected readonly float $timeoutSeconds = 60.0,
    ) {
    }

    public function configured(): bool
    {
        return $this->key !== null && trim($this->key) !== '';
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: int} răspunsul decodat și durata în ms
     */
    protected function post(string $url, array $headers, array $body): array
    {
        if (!$this->configured()) {
            throw AiException::short(sprintf('Cheia API pentru %s nu este configurată.', $this->provider()));
        }
        $start = hrtime(true);
        try {
            $response = $this->http->request('POST', $url, ['headers' => $headers, 'json' => $body, 'timeout' => $this->timeoutSeconds]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw AiException::short(sprintf('%s: apelul a eșuat (%s).', $this->provider(), $e->getMessage()));
        } catch (\JsonException) {
            throw AiException::short(sprintf('%s: răspuns care nu e JSON.', $this->provider()));
        }
        $duration = (int) round((hrtime(true) - $start) / 1e6);
        if ($status >= 400) {
            $message = $data['error']['message'] ?? 'fără detalii';
            throw AiException::short(sprintf('%s: HTTP %d — %s', $this->provider(), $status, \is_string($message) ? $message : 'fără detalii'));
        }

        return [$data, $duration];
    }

    /** @return array<string, mixed> */
    protected static function jsonObject(mixed $text, string $provider): array
    {
        if (!\is_string($text)) {
            throw AiException::short($provider.': răspunsul nu conține text.');
        }
        try {
            $data = json_decode($text, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw AiException::short($provider.': răspunsul nu e un obiect JSON valid.');
        }
        if (!\is_array($data) || array_is_list($data)) {
            throw AiException::short($provider.': răspunsul nu e un obiect JSON.');
        }

        return $data;
    }
}
