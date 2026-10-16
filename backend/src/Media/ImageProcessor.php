<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Re-encodarea fotografiilor (ADR-0011 p.5-6): tipul se verifică după conținut, nu după extensie; imaginea se
 * rotește după EXIF, apoi toate metadatele se elimină; rezultă o variantă de afișare și o miniatură, WebP.
 * Originalul nu se păstrează. A treia variantă, `og`, e imaginea de share (og:image): JPEG 1200×630, decupată
 * din centru (decizie 16.10.2026), pentru că nu toate platformele citesc WebP.
 */
final class ImageProcessor
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    /** Împotriva imaginilor mici pe disc, dar uriașe decomprimate. */
    public const MAX_PIXELS = 40_000_000;
    public const DISPLAY_SIZE = 1600;
    public const THUMB_SIZE = 400;
    public const OG_WIDTH = 1200;
    public const OG_HEIGHT = 630;

    /** Tipul detectat => coderul ImageMagick impus la citire (nu lăsăm ImageMagick să ghicească formatul). */
    private const FORMATS = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WEBP',
        'image/heic' => 'HEIC',
        'image/heif' => 'HEIC',
    ];

    public function process(string $path): ProcessedImage
    {
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            throw new InvalidImageException('Fișierul este gol.');
        }
        if ($size > self::MAX_BYTES) {
            throw new InvalidImageException('Fotografia depășește 10 MB.', tooLarge: true);
        }
        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->file($path);
        $format = self::FORMATS[$mime] ?? throw new InvalidImageException('Sunt acceptate doar fotografii JPEG, PNG, WebP sau HEIC.');

        try {
            $probe = new \Imagick();
            $probe->pingImage($format.':'.$path);
            if ($probe->getImageWidth() * $probe->getImageHeight() > self::MAX_PIXELS) {
                throw new InvalidImageException('Fotografia are o rezoluție prea mare (maximum 40 megapixeli).');
            }
            $probe->clear();

            $all = new \Imagick();
            $all->readImage($format.':'.$path);
            $all->setFirstIterator();
            $image = $all->getImage(); // primul cadru (WebP animat, HEIC cu mai multe imagini)
            $all->clear();

            $image->autoOrient();
            if ($image->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
                $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }
            $image->stripImage();

            $thumb = clone $image;
            $this->fit($image, self::DISPLAY_SIZE);
            $this->fit($thumb, self::THUMB_SIZE);
            // Din varianta de afișare (1600 px): aceeași sursă ca la backfill (app:media:og-backfill).
            $og = $this->og(clone $image);

            return new ProcessedImage($this->webp($image, 80), $this->webp($thumb, 75), $og, $image->getImageWidth(), $image->getImageHeight());
        } catch (\ImagickException) {
            throw new InvalidImageException('Fotografia nu poate fi citită.');
        }
    }

    /** Varianta `og` dintr-o variantă de afișare deja salvată (WebP fără metadate) - pentru fotografiile vechi. */
    public function ogFromDisplay(string $displayBytes): string
    {
        try {
            $image = new \Imagick();
            $image->readImageBlob($displayBytes);

            return $this->og($image);
        } catch (\ImagickException) {
            throw new InvalidImageException('Fotografia nu poate fi citită.');
        }
    }

    private function og(\Imagick $image): string
    {
        $image->cropThumbnailImage(self::OG_WIDTH, self::OG_HEIGHT); // acoperă 1200×630, apoi decupează centrul
        $image->setImagePage(0, 0, 0, 0);
        // PNG/WebP cu transparență: JPEG nu are canal alfa, fundalul devine alb, nu negru.
        $image->setImageBackgroundColor('white');
        $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(82);
        $image->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
        $image->stripImage();

        return $image->getImageBlob();
    }

    private function fit(\Imagick $image, int $max): void
    {
        if ($image->getImageWidth() > $max || $image->getImageHeight() > $max) {
            $image->resizeImage($max, $max, \Imagick::FILTER_LANCZOS, 1, true);
        }
    }

    private function webp(\Imagick $image, int $quality): string
    {
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality($quality);
        $image->stripImage();

        return $image->getImageBlob();
    }
}
