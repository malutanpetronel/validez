<?php

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Vote\ErasureRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

/** ADR-0009: fișierul registrului de ștergeri — scriere, ordine, retenție, linii invalide. */
final class ErasureRegistryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/validez-erasure-'.bin2hex(random_bytes(6)).'/sub';
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/registry.jsonl');
        @rmdir($this->dir);
        @rmdir(\dirname($this->dir));
    }

    public function testMissingFileIsEmptyAndRecordCreatesDirectoryWithMinimalData(): void
    {
        $registry = new ErasureRegistry($this->dir, 45);
        self::assertSame([], $registry->entries());

        $user = new Ulid();
        $at = new \DateTimeImmutable('-1 hour');
        $registry->record($user, ErasureRegistry::CONSENT_WITHDRAWN, $at);

        $entries = $registry->entries();
        self::assertCount(1, $entries);
        self::assertSame($user->toBase32(), $entries[0]['user']);
        self::assertSame(ErasureRegistry::CONSENT_WITHDRAWN, $entries[0]['action']);
        self::assertEquals($at, $entries[0]['at'], 'momentul se păstrează cu microsecunde');
        self::assertSame(['v', 'user', 'action', 'at'], array_keys(json_decode(trim(file_get_contents($registry->path())), true)), 'doar date minime');
        self::assertSame('0640', substr(sprintf('%o', fileperms($registry->path())), -4));
    }

    public function testEntriesAreChronologicalAndOldOnesArePrunedOnWrite(): void
    {
        $registry = new ErasureRegistry($this->dir, 45);
        $old = new Ulid();
        $registry->record($old, ErasureRegistry::CONSENT_WITHDRAWN, new \DateTimeImmutable('-46 days'));
        $later = new Ulid();
        $earlier = new Ulid();
        $registry->record($later, ErasureRegistry::CONSENT_WITHDRAWN, new \DateTimeImmutable('-1 day'));
        $registry->record($earlier, ErasureRegistry::CONSENT_WITHDRAWN, new \DateTimeImmutable('-2 days'));

        self::assertSame([$earlier->toBase32(), $later->toBase32()], array_column($registry->entries(), 'user'), 'intrarea de acum 46 de zile a dispărut');
    }

    public function testUnknownActionAndInvalidLinesAreRejected(): void
    {
        $registry = new ErasureRegistry($this->dir, 45);
        try {
            $registry->record(new Ulid(), 'vote_withdrawn', new \DateTimeImmutable());
            self::fail('acțiune necunoscută acceptată');
        } catch (\InvalidArgumentException) {
        }

        $registry->record(new Ulid(), ErasureRegistry::CONSENT_WITHDRAWN, new \DateTimeImmutable());
        file_put_contents($registry->path(), "{\"v\":1,\"user\":\"nu-e-ulid\"}\n", \FILE_APPEND);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Linie invalidă în registrul de ștergeri: 2');
        $registry->entries();
    }

    /** ADR-0011 p.10: scoaterile de media stau în același registru, cu id-ul media în loc de utilizator. */
    public function testMediaRemovalUsesItsOwnIdKeyNextToConsentLines(): void
    {
        $registry = new ErasureRegistry($this->dir, 45);
        $user = new Ulid();
        $media = new Ulid();
        $registry->record($user, ErasureRegistry::CONSENT_WITHDRAWN, new \DateTimeImmutable('-2 hours'));
        $registry->record($media, ErasureRegistry::MEDIA_REMOVED, new \DateTimeImmutable('-1 hour'));

        $entries = $registry->entries();
        self::assertSame([$user->toBase32(), null], array_map(static fn (array $e): ?string => $e['user'] ?? null, $entries));
        self::assertSame($media->toBase32(), $entries[1]['media']);
        $lines = array_map(static fn (string $l): array => array_keys(json_decode($l, true)), explode("\n", trim(file_get_contents($registry->path()))));
        self::assertSame([['v', 'user', 'action', 'at'], ['v', 'media', 'action', 'at']], $lines);

        // O linie de media cu cheia `user` nu e validă.
        file_put_contents($registry->path(), json_encode(['v' => 1, 'user' => (new Ulid())->toBase32(), 'action' => 'media_removed', 'at' => '2026-10-15T10:00:00.000000+00:00'])."\n", \FILE_APPEND);
        $this->expectExceptionMessage('Linie invalidă în registrul de ștergeri: 3');
        $registry->entries();
    }
}
