<?php

declare(strict_types=1);

namespace App\Vote;

use App\Entity\CivicSubject;
use App\Entity\IntegrityEventLog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lanțul de integritate per subiect (ADR-0007 §7). Se apelează sub lockul de rând al subiectului.
 *
 * commitment = SHA-256(domeniu ‖ conținutul privat al operațiunii ‖ nonce de 32 octeți), serializare canonică.
 * event_hash = SHA-256(domeniu ‖ format ‖ subiect ‖ secvență ‖ tip ‖ commitment ‖ hash anterior ‖ moment).
 * Nonce-ul rămâne exclusiv în zona privată (UserVoteHistory); fără el commitmentul nu se poate verifica.
 */
final class IntegrityChain
{
    public const COMMITMENT_DOMAIN = 'validez/vote-commitment/v1';
    public const EVENT_DOMAIN = 'validez/integrity-event/v1';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Conținutul privat: subiect, tip de vot, revizia și valoarea înainte/după (după = null la retragere).
     *
     * @return list<string|int|null>
     */
    public static function privateContent(CivicSubject $subject, string $voteType, ?int $revisionBefore, ?int $valueBefore, ?int $revisionAfter, ?int $valueAfter): array
    {
        return [$subject->getId()->toRfc4122(), $voteType, $revisionBefore, $valueBefore, $revisionAfter, $valueAfter];
    }

    /** @param list<string|int|null> $privateContent */
    public static function commitment(array $privateContent, string $nonce): string
    {
        return CanonicalEncoder::sha256(self::COMMITMENT_DOMAIN, [...$privateContent, Bytes::require32($nonce, 'nonce')]);
    }

    /**
     * Adaugă un eveniment la capătul lanțului subiectului.
     *
     * @param list<string|int|null> $privateContent
     *
     * @return array{0: IntegrityEventLog, 1: string} evenimentul și nonce-ul (de păstrat doar în zona privată)
     */
    public function append(CivicSubject $subject, IntegrityEventKind $kind, array $privateContent): array
    {
        $head = $this->em->getConnection()->fetchAssociative(
            "SELECT sequence_number, encode(event_hash, 'hex') AS event_hash FROM integrity_event_log WHERE subject_id = ? ORDER BY sequence_number DESC LIMIT 1",
            [$subject->getId()->toRfc4122()],
        );
        // Un eveniment al aceluiași subiect poate fi deja persistat în această tranzacție, dar încă nescris.
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof IntegrityEventLog && $pending->getSubject() === $subject
                && ($head === false || $pending->getSequenceNumber() > (int) $head['sequence_number'])) {
                $head = ['sequence_number' => $pending->getSequenceNumber(), 'event_hash' => bin2hex($pending->getEventHash())];
            }
        }
        $sequence = $head === false ? 1 : (int) $head['sequence_number'] + 1;
        $previous = $head === false ? null : hex2bin($head['event_hash']);

        $nonce = random_bytes(Bytes::LENGTH);
        $commitment = self::commitment($privateContent, $nonce);
        $createdAt = new \DateTimeImmutable('@'.time()); // secunde, UTC: aceeași precizie ca în coloană
        $hash = self::eventHash($subject->getId()->toRfc4122(), $sequence, IntegrityEventLog::FORMAT_VERSION, $kind, $commitment, $previous, $createdAt);

        $event = new IntegrityEventLog($subject, $sequence, $kind, $commitment, $previous, $hash, $createdAt);
        $this->em->persist($event);

        return [$event, $nonce];
    }

    public static function eventHash(string $subjectId, int $sequence, int $formatVersion, IntegrityEventKind $kind, string $commitment, ?string $previous, \DateTimeInterface $createdAt): string
    {
        $moment = \DateTimeImmutable::createFromInterface($createdAt)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        return CanonicalEncoder::sha256(self::EVENT_DOMAIN, [$formatVersion, $subjectId, $sequence, $kind->value, $commitment, $previous, $moment]);
    }

    /**
     * Verifică structura păstrată a lanțului: secvențe consecutive, legăturile și hashurile.
     *
     * @return int|null prima secvență invalidă sau null dacă lanțul e consistent
     */
    public function firstBrokenSequence(CivicSubject $subject): ?int
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT sequence_number, format_version, kind, encode(commitment, 'hex') AS commitment, encode(previous_event_hash, 'hex') AS previous,
                    encode(event_hash, 'hex') AS event_hash, created_at
             FROM integrity_event_log WHERE subject_id = ? ORDER BY sequence_number",
            [$subject->getId()->toRfc4122()],
        );
        $expectedPrevious = null;
        foreach ($rows as $i => $row) {
            $sequence = (int) $row['sequence_number'];
            $previous = $row['previous'] === null ? null : hex2bin($row['previous']);
            $hash = self::eventHash(
                $subject->getId()->toRfc4122(),
                $sequence,
                (int) $row['format_version'],
                IntegrityEventKind::from($row['kind']),
                hex2bin($row['commitment']),
                $previous,
                new \DateTimeImmutable($row['created_at']),
            );
            if ($sequence !== $i + 1 || $previous !== $expectedPrevious || !hash_equals($hash, hex2bin($row['event_hash']))) {
                return $sequence;
            }
            $expectedPrevious = $hash;
        }

        return null;
    }
}
