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
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function recalculate(CivicSubject $subject): void
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT code, value, is_current, COUNT(*) AS n FROM (
                 SELECT v.vote_type_code AS code, v.value, (r.number = ?)::int AS is_current
                 FROM vote v JOIN subject_revision r ON r.id = v.revision_id
                 WHERE v.subject_id = ?
             ) AS x GROUP BY code, value, is_current',
            [$subject->getRevisionNumber(), $subject->getId()->toRfc4122()],
        );
        $current = [];
        $stale = [];
        foreach ($rows as $row) {
            if ((int) $row['is_current'] === 1) {
                $current[$row['code']][(int) $row['value']] = (int) $row['n'];
            } else {
                $stale[$row['code']] = ($stale[$row['code']] ?? 0) + (int) $row['n'];
            }
        }

        foreach ($this->em->getRepository(SubjectVoteType::class)->findBy(['subject' => $subject]) as $configured) {
            $type = $configured->getVoteType();
            $result = $this->em->find(VoteResult::class, ['subject' => $subject->getId(), 'voteType' => $type->getCode()]);
            if ($result === null) {
                $result = new VoteResult($subject, $type);
                $this->em->persist($result);
            }
            $result->replace($current[$type->getCode()] ?? [], $stale[$type->getCode()] ?? 0);
        }
    }
}
