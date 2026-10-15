<?php

declare(strict_types=1);

namespace App\Media;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;

/**
 * Fișierele media, în afara public/ (ADR-0011 p.3). Le servește nginx doar prin X-Accel-Redirect, după ce
 * Symfony verifică vizibilitatea (p.4). Pe dev și prod directorul e montat la aceeași cale în php și nginx.
 */
final class MediaStorage
{
    public const ACCEL_PREFIX = '/_protected_media/';

    private readonly Filesystem $fs;

    public function __construct(string $directory, string $projectDir)
    {
        $root = str_starts_with($directory, '/') ? $directory : $projectDir.'/'.$directory;
        $this->fs = new Filesystem(new LocalFilesystemAdapter($root, PortableVisibilityConverter::fromArray([
            'file' => ['public' => 0644, 'private' => 0640],
            'dir' => ['public' => 0755, 'private' => 0750],
        ])));
    }

    public function write(string $key, string $bytes): void
    {
        $this->fs->write($key, $bytes, ['visibility' => 'public', 'directory_visibility' => 'public']);
    }

    public function delete(string $key): void
    {
        $this->fs->delete($key);
    }

    public function exists(string $key): bool
    {
        return $this->fs->fileExists($key);
    }

    public function accelPath(string $key): string
    {
        return self::ACCEL_PREFIX.$key;
    }
}
