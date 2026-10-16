<?php

declare(strict_types=1);

namespace App\Seo;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Shell-ul HTML al paginilor publice (ADR-0008, S.3): index.html-ul build-ului FE curent (copiat de ansible
 * la deploy-ul FE), cu titlul, meta, canonical, Open Graph și JSON-LD ale paginii în <head> și un rezumat
 * HTML în #root. Fișierul de pe disc nu se modifică; React înlocuiește rezumatul la pornire.
 */
final class SeoShell
{
    /** Pagină minimă când șablonul lipsește (ex. înainte de primul deploy FE cu pasul ansible): conținut fără aplicație. */
    private const FALLBACK = '<!doctype html><html lang="ro"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><meta property="og:site_name" content="VALIDEZ"/></head><body><div id="root"></div></body></html>';

    private readonly string $siteUrl;
    private readonly string $templatePath;

    public function __construct(
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default:site_url_default:SITE_URL)%')] string $siteUrl,
        #[Autowire('%env(default:seo_shell_template_default:SEO_SHELL_TEMPLATE)%')] string $templatePath,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ) {
        $this->siteUrl = rtrim($siteUrl, '/');
        $this->templatePath = str_starts_with($templatePath, '/') ? $templatePath : $projectDir.'/'.$templatePath;
    }

    public function url(string $path): string
    {
        return $this->siteUrl.$path;
    }

    /**
     * @param array{title: string, description: string, path?: ?string, noindex?: bool, ogType?: string, jsonLd?: list<array<string, mixed>>, image?: ?array{path: string, width: int, height: int, alt: string}} $meta
     *        title fără brand; path = calea canonică (absolutizată aici); image = og:image al paginii (altfel rămâne cel din șablon)
     */
    public function render(array $meta, string $bodyHtml, int $status = Response::HTTP_OK): Response
    {
        $canonical = isset($meta['path']) && !($meta['noindex'] ?? false) ? $this->url($meta['path']) : null;
        $image = $meta['image'] ?? null;
        $head = $this->twig->render('seo/head.html.twig', [
            'title' => $meta['title'].' — VALIDEZ',
            'description' => $meta['description'],
            'canonical' => $canonical,
            'noindex' => $meta['noindex'] ?? false,
            'ogType' => $meta['ogType'] ?? 'website',
            'image' => $image === null ? null : ['url' => $this->url($image['path'])] + $image,
            'jsonLd' => array_map(
                static fn (array $ld): string => json_encode($ld, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
                $meta['jsonLd'] ?? [],
            ),
        ]);

        $html = $this->template();
        // Scoatem ce aducem noi, apoi inserăm prin poziție (nu prin preg_replace: textul utilizatorilor poate conține „$1”).
        $remove = [
            '#<title>.*?</title>#is',
            '#<meta\s+name="(?:description|robots)"[^>]*>#i',
            '#<meta\s+property="og:(?:title|description|url|type)"[^>]*>#i',
            '#<link\s+rel="canonical"[^>]*>#i',
        ];
        if ($image !== null) {
            $remove[] = '#<meta\s+(?:property|name)="(?:og:image(?::[a-z_]+)?|twitter:image(?::alt)?)"[^>]*>#i';
        }
        $html = (string) preg_replace($remove, '', $html);
        $html = self::insertBefore($html, '</head>', $head);
        $html = self::insertBefore($html, '</div>', $bodyHtml, '<div id="root">');

        $response = new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        $response->setPublic();
        $response->setMaxAge($status === Response::HTTP_OK ? 300 : 60);

        return $response;
    }

    private function template(): string
    {
        $html = is_readable($this->templatePath) ? file_get_contents($this->templatePath) : false;
        if (\is_string($html) && stripos($html, '</head>') !== false && str_contains($html, '<div id="root"></div>')) {
            return $html;
        }
        $this->logger->error('SEO shell: șablonul index.html lipsește sau nu are </head> / <div id="root"></div>; servesc pagina minimă.', ['path' => $this->templatePath]);

        return self::FALLBACK;
    }

    /** Inserează $insert înaintea primei apariții a $needle (după $after, dacă e dat). */
    private static function insertBefore(string $html, string $needle, string $insert, ?string $after = null): string
    {
        $from = $after === null ? 0 : stripos($html, $after);
        if ($from === false) {
            return $html;
        }
        $pos = stripos($html, $needle, $from + ($after === null ? 0 : \strlen($after)));

        return $pos === false ? $html : substr_replace($html, $insert, $pos, 0);
    }
}
