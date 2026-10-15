<?php

declare(strict_types=1);

namespace App\Vote;

use Symfony\Component\Uid\Ulid;

/**
 * Registrul ștergerilor pentru restaurare (ADR-0009, ADR-0007 §8).
 *
 * Un fișier append-only, în afara bazei de date (deci în afara `pg_dump`), cu date minime: id-ul vizat (utilizatorul
 * pentru o retragere de consimțământ, fotografia/linkul pentru o scoatere de media - ADR-0011 p.10), acțiunea și
 * momentul ei, în UTC. După restaurarea unei copii a bazei, `app:votes:reapply-erasures` reaplică ce s-a întâmplat
 * după copie, ca o restaurare să nu reactiveze un consimțământ retras și să nu readucă o fotografie scoasă.
 *
 * Retenție proprie: intrările mai vechi de `$retentionDays` (copiile bazei trăiesc cel mult 30 de zile) se elimină
 * la fiecare scriere. Scrierea și citirea folosesc `flock`; scrierea face `fsync`.
 */
final class ErasureRegistry
{
    public const CONSENT_WITHDRAWN = 'consent_withdrawn';
    public const MEDIA_REMOVED = 'media_removed';

    /** Acțiune => cheia id-ului în linie (liniile existente, cu `user`, rămân valide). */
    private const ID_KEYS = [self::CONSENT_WITHDRAWN => 'user', self::MEDIA_REMOVED => 'media'];

    private const FORMAT = 1;
    private const FILE = 'registry.jsonl';
    private const TIME = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private readonly string $directory,
        private readonly int $retentionDays,
    ) {
    }

    public function path(): string
    {
        return rtrim($this->directory, '/').'/'.self::FILE;
    }

    public function record(Ulid $id, string $action, \DateTimeImmutable $at): void
    {
        if (!isset(self::ID_KEYS[$action])) {
            throw new \InvalidArgumentException('Acțiune necunoscută în registrul de ștergeri: '.$action);
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Nu pot crea directorul registrului de ștergeri: '.$this->directory);
        }

        $handle = $this->open('c+');
        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException('Nu pot bloca registrul de ștergeri.');
            }
            $cutoff = (new \DateTimeImmutable())->modify('-'.$this->retentionDays.' days');
            $kept = array_values(array_filter(
                $this->parse(stream_get_contents($handle) ?: ''),
                static fn (array $entry): bool => $entry['at'] >= $cutoff,
            ));
            $kept[] = [self::ID_KEYS[$action] => $id->toBase32(), 'action' => $action, 'at' => $at];

            $content = '';
            foreach ($kept as $entry) {
                $key = self::ID_KEYS[$entry['action']];
                $content .= json_encode([
                    'v' => self::FORMAT,
                    $key => $entry[$key],
                    'action' => $entry['action'],
                    'at' => $entry['at']->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME),
                ], \JSON_THROW_ON_ERROR)."\n";
            }
            if (!ftruncate($handle, 0) || !rewind($handle) || fwrite($handle, $content) !== \strlen($content) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('Scrierea în registrul de ștergeri a eșuat.');
            }
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
        @chmod($this->path(), 0640);
    }

    /**
     * Intrările, în ordine cronologică. Un fișier lipsă înseamnă un registru gol; o linie invalidă oprește citirea,
     * ca o restaurare să nu continue cu un registru citit pe jumătate.
     *
     * @return list<array{action: string, at: \DateTimeImmutable, user?: string, media?: string}>
     */
    public function entries(): array
    {
        if (!is_file($this->path())) {
            return [];
        }
        $handle = $this->open('r');
        try {
            if (!flock($handle, \LOCK_SH)) {
                throw new \RuntimeException('Nu pot bloca registrul de ștergeri.');
            }
            $entries = $this->parse(stream_get_contents($handle) ?: '');
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
        usort($entries, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $entries;
    }

    /** @return resource */
    private function open(string $mode)
    {
        $handle = @fopen($this->path(), $mode);
        if ($handle === false) {
            throw new \RuntimeException('Nu pot deschide registrul de ștergeri: '.$this->path());
        }

        return $handle;
    }

    /** @return list<array{action: string, at: \DateTimeImmutable, user?: string, media?: string}> */
    private function parse(string $content): array
    {
        $entries = [];
        foreach (explode("\n", $content) as $number => $line) {
            if (trim($line) === '') {
                continue;
            }
            $data = json_decode($line, true);
            $at = \is_array($data) && \is_string($data['at'] ?? null) ? \DateTimeImmutable::createFromFormat(self::TIME, $data['at']) : false;
            $key = \is_array($data) && \is_string($data['action'] ?? null) ? (self::ID_KEYS[$data['action']] ?? null) : null;
            if ($key === null || ($data['v'] ?? null) !== self::FORMAT || !\is_string($data[$key] ?? null) || !Ulid::isValid($data[$key]) || $at === false) {
                throw new \RuntimeException('Linie invalidă în registrul de ștergeri: '.($number + 1));
            }
            $entries[] = [$key => $data[$key], 'action' => $data['action'], 'at' => $at];
        }

        return $entries;
    }
}
