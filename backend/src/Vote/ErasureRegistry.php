<?php

declare(strict_types=1);

namespace App\Vote;

use Symfony\Component\Uid\Ulid;

/**
 * Registrul ștergerilor pentru restaurare (ADR-0009, ADR-0007 §8).
 *
 * Un fișier append-only, în afara bazei de date (deci în afara `pg_dump`), cu date minime: id-ul utilizatorului,
 * acțiunea și momentul ei, în UTC. După restaurarea unei copii a bazei, `app:votes:reapply-erasures` reaplică
 * retragerile ulterioare copiei, ca o restaurare să nu reactiveze un consimțământ retras.
 *
 * Retenție proprie: intrările mai vechi de `$retentionDays` (copiile bazei trăiesc cel mult 30 de zile) se elimină
 * la fiecare scriere. Scrierea și citirea folosesc `flock`; scrierea face `fsync`.
 */
final class ErasureRegistry
{
    public const CONSENT_WITHDRAWN = 'consent_withdrawn';

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

    public function record(Ulid $userId, string $action, \DateTimeImmutable $at): void
    {
        if ($action !== self::CONSENT_WITHDRAWN) {
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
            $kept[] = ['user' => $userId->toBase32(), 'action' => $action, 'at' => $at];

            $content = '';
            foreach ($kept as $entry) {
                $content .= json_encode([
                    'v' => self::FORMAT,
                    'user' => $entry['user'],
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
     * @return list<array{user: string, action: string, at: \DateTimeImmutable}>
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

    /** @return list<array{user: string, action: string, at: \DateTimeImmutable}> */
    private function parse(string $content): array
    {
        $entries = [];
        foreach (explode("\n", $content) as $number => $line) {
            if (trim($line) === '') {
                continue;
            }
            $data = json_decode($line, true);
            $at = \is_array($data) && \is_string($data['at'] ?? null) ? \DateTimeImmutable::createFromFormat(self::TIME, $data['at']) : false;
            if (!\is_array($data) || ($data['v'] ?? null) !== self::FORMAT || !\is_string($data['user'] ?? null) || !Ulid::isValid($data['user'])
                || ($data['action'] ?? null) !== self::CONSENT_WITHDRAWN || $at === false) {
                throw new \RuntimeException('Linie invalidă în registrul de ștergeri: '.($number + 1));
            }
            $entries[] = ['user' => $data['user'], 'action' => $data['action'], 'at' => $at];
        }

        return $entries;
    }
}
