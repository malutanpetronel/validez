<?php

declare(strict_types=1);

namespace App\Subject;

use App\Entity\CivicSubject;
use App\Entity\SubjectRevision;
use App\Entity\SubjectVoteType;
use App\Entity\User;
use App\Entity\VoteType;
use App\Vote\DefaultVoteTypes;
use App\Vote\VoteResultCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Reviziile subiectului (ADR-0007 §3). Se apelează din procesoarele de creare/editare, în tranzacția lor
 * și sub lockurile lor (autor, apoi rândul subiectului).
 *
 * Revizie nouă = diferență efectivă în instantaneu (titlu, descriere, estimare brută, tip), comparată pe valori,
 * nu pe câmpurile prezente în payload. Stadiul și vizibilitatea nu fac parte din instantaneu.
 */
final class SubjectRevisions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VoteResultCalculator $results,
    ) {
    }

    /** Revizia 1 și tipurile de vot implicite, la crearea subiectului. */
    public function initialize(CivicSubject $subject, User $author): void
    {
        $this->em->persist(new SubjectRevision($subject, 1, $author));
        foreach (DefaultVoteTypes::forSubjectType($subject->getType()) as $code) {
            $this->em->persist(new SubjectVoteType($subject, $this->em->getReference(VoteType::class, $code)));
        }
    }

    /** @return array<string, mixed> */
    public static function snapshot(CivicSubject $subject): array
    {
        return ['type' => $subject->getType(), 'title' => $subject->getTitle(), 'description' => $subject->getDescription()] + $subject->storedEstimate();
    }

    /** Tipul se blochează după primul vot înregistrat vreodată (ADR-0007 §3); fără excepție pentru admin. */
    public static function assertTypeChangeAllowed(CivicSubject $subject, ?SubjectType $requested): void
    {
        if ($requested !== null && $requested !== $subject->getType() && $subject->isTypeLocked()) {
            throw new UnprocessableEntityHttpException('Tipul nu se mai poate schimba după primul vot.');
        }
    }

    /** @param array<string, mixed> $before instantaneul luat după lock, înaintea modificărilor */
    public function recordIfChanged(CivicSubject $subject, array $before, User $editor): ?SubjectRevision
    {
        if (self::snapshot($subject) === $before) {
            return null;
        }
        $revision = new SubjectRevision($subject, $subject->advanceRevision(), $editor);
        $this->em->persist($revision);
        // Voturile de pe revizia anterioară devin nereconfirmate, în aceeași tranzacție.
        $this->results->recalculate($subject);

        return $revision;
    }
}
