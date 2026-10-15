<?php

declare(strict_types=1);

namespace App\Vote;

use App\Entity\CivicSubject;
use App\Entity\SubjectVoteType;
use App\Entity\VoteResult;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recalculează agregatele unui subiect din `vote` (ADR-0007 §3, §8): pe revizia curentă, pe valori,
 * și numărul voturilor de pe revizii anterioare (nereconfirmate). Rânduri pentru toate tipurile configurate,
 * inclusiv cele dezactivate (rezultatele lor rămân vizibile).
 *
 * Se apelează sub lockul de rând al subiectului, după ce voturile au fost scrise (flush).
 * Revizia curentă vine din entitate, nu din baza de date, ca să fie corectă și înaintea flush-ului subiectului.
 */
final class VoteResultCalculator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly int $locationConfirmMin = 3,
    ) {
    }

    public function recalculate(CivicSubject $subject): void
    {
        $computed = $this->compute($subject);
        foreach ($this->em->getRepository(SubjectVoteType::class)->findBy(['subject' => $subject]) as $configured) {
            $type = $configured->getVoteType();
            $result = $this->em->find(VoteResult::class, ['subject' => $subject->getId(), 'voteType' => $type->getCode()]);
            if ($result === null) {
                $result = new VoteResult($subject, $type);
                $this->em->persist($result);
            }
            [$counts, $stale] = $computed[$type->getCode()] ?? [[], 0];
            $result->replace($counts, $stale);
        }
        $this->refreshLocationConfirmation($subject);
    }

    /**
     * ADR-0011 p.9: locul e confirmat de utilizatori cu cel puțin LOCATION_CONFIRM_MIN voturi „Da” pe revizia
     * curentă și mai multe „Da” decât „Nu”. Votul autorului nu se numără. Mutarea pinului face revizie nouă,
     * deci aici numărătoarea pornește automat de la zero.
     */
    private function refreshLocationConfirmation(CivicSubject $subject): void
    {
        if (!$subject->hasLocation()) {
            $subject->applyLocationVotes(0, false);

            return;
        }
        $counts = $this->em->getConnection()->fetchAllKeyValue(
            'SELECT v.value, COUNT(*) FROM vote v JOIN subject_revision r ON r.id = v.revision_id
             WHERE v.subject_id = ? AND v.vote_type_code = ? AND r.number = ? AND v.user_id <> ?
             GROUP BY v.value',
            [$subject->getId()->toRfc4122(), DefaultVoteTypes::LOCATION, $subject->getRevisionNumber(), $subject->getAuthor()->getId()->toRfc4122()],
        );
        $yes = (int) ($counts[1] ?? 0);
        $no = (int) ($counts[0] ?? 0);
        $subject->applyLocationVotes($yes, $yes >= $this->locationConfirmMin && $yes > $no);
    }

    /**
     * Numărătoarea din `vote`, fără scriere (folosită și de app:votes:verify).
     *
     * @return array<string, array{0: array<int, int>, 1: int}> cod tip => [contoare pe revizia curentă, nereconfirmate]
     */
    public function compute(CivicSubject $subject): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT code, value, is_current, COUNT(*) AS n FROM (
                 SELECT v.vote_type_code AS code, v.value, (r.number = ?)::int AS is_current
                 FROM vote v JOIN subject_revision r ON r.id = v.revision_id
                 WHERE v.subject_id = ?
             ) AS x GROUP BY code, value, is_current',
            [$subject->getRevisionNumber(), $subject->getId()->toRfc4122()],
        );
        $computed = [];
        foreach ($rows as $row) {
            $computed[$row['code']] ??= [[], 0];
            if ((int) $row['is_current'] === 1) {
                $computed[$row['code']][0][(int) $row['value']] = (int) $row['n'];
            } else {
                $computed[$row['code']][1] += (int) $row['n'];
            }
        }
        foreach ($computed as &$entry) {
            ksort($entry[0]);
        }
        unset($entry);

        return $computed;
    }
}
