<?php

declare(strict_types=1);

namespace App\Media;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Validarea linkurilor (ADR-0011 p.8): doar http/https, cu host, fără credențiale înglobate și fără caractere
 * de control sau spații. Schema și hostul se normalizează la litere mici (pentru detectarea duplicatelor).
 * Serverul nu accesează URL-ul.
 */
final class LinkUrl
{
    public const MAX_LENGTH = 2048;
    public const MAX_LABEL = 200;

    public static function normalize(mixed $url): string
    {
        if (!\is_string($url) || ($url = trim($url)) === '') {
            throw new UnprocessableEntityHttpException('Lipsește adresa linkului.');
        }
        if (\strlen($url) > self::MAX_LENGTH) {
            throw new UnprocessableEntityHttpException('Adresa linkului depășește 2048 de caractere.');
        }
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new UnprocessableEntityHttpException('Adresa linkului nu poate conține spații sau caractere de control.');
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($parts === false || !\in_array($scheme, ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            throw new UnprocessableEntityHttpException('Sunt acceptate doar linkuri http:// sau https://.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnprocessableEntityHttpException('Linkul nu poate conține nume de utilizator sau parolă.');
        }

        return $scheme.'://'.strtolower($parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    public static function label(mixed $label): ?string
    {
        if ($label === null) {
            return null;
        }
        if (!\is_string($label)) {
            throw new UnprocessableEntityHttpException('Eticheta linkului trebuie să fie text.');
        }
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        if (mb_strlen($label) > self::MAX_LABEL) {
            throw new UnprocessableEntityHttpException('Eticheta linkului depășește 200 de caractere.');
        }

        return $label === '' ? null : $label;
    }
}
