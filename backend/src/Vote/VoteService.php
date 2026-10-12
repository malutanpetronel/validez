<?php

declare(strict_types=1);

namespace App\Vote;

use App\Entity\CivicSubject;
use App\Entity\SubjectRevision;
use App\Entity\SubjectVoteType;
use App\Entity\User;
use App\Entity\UserVoteHistory;
use App\Entity\Vote;
use App\Entity\VoteConsent;
use App\Entity\VoteType;
use App\Subject\SubjectStage;
use App\Subject\SubjectVisibility;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Votul (ADR-0007): exprimare, schimbare, reconfirmare, retragere; consimțământul.
 *
 * Fiecare operațiune rulează într-o tranzacție: lock de rând pe utilizator, apoi pe subiect (aceeași ordine ca la
 * editarea subiectului). Verificările (email, consimțământ, vizibilitate, stadiu, tip activ, interval, revizie)
 * se fac după lockuri. Datele private, jurnalul și agregatele se scriu în aceeași tranzacție.
 *
 * Tranzacția nu folosește EntityManager::wrapInTransaction, care închide EntityManager-ul la orice excepție:
 * refuzurile de domeniu sunt normale și nu trebuie să invalideze EM-ul.
 */
final class VoteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IntegrityChain $chain,
        private readonly VoteResultCalculator $results,
    ) {
    }

    public function hasActiveConsent(User $user): bool
    {
        return $this->em->getRepository(VoteConsent::class)->count(['user' => $user, 'withdrawnAt' => null]) > 0;
    }

    /** Acordă consimțământul pentru versiunea dată a informării; o versiune nouă înlocuiește acordul activ. */
    public function grantConsent(User $user, string $noticeVersion): VoteConsent
    {
        return $this->transactional(function () use ($user, $noticeVersion): VoteConsent {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            $active = $this->em->getRepository(VoteConsent::class)->findOneBy(['user' => $user, 'withdrawnAt' => null]);
            if ($active !== null && $active->getNoticeVersion() === $noticeVersion) {
                return $active;
            }
            if ($active !== null) {
                // Doctrine scrie INSERT-urile înaintea UPDATE-urilor: retragerea se scrie separat, înainte.
                $active->withdraw();
                $this->em->flush();
            }
            $consent = new VoteConsent($user, $noticeVersion);
            $this->em->persist($consent);
            $this->em->flush();

            return $consent;
        });
    }

    /**
     * Votează, schimbă sau reconfirmă.
     *
     * @return VoteHistoryAction|null null dacă votul există deja cu aceeași valoare pe revizia curentă
     */
    public function cast(User $user, CivicSubject $subject, string $voteTypeCode, int $value): ?VoteHistoryAction
    {
        return $this->transactional(function () use ($user, $subject, $voteTypeCode, $value): ?VoteHistoryAction {
            $this->lock($user, $subject);
            if (!$user->isEmailVerified()) {
                throw VoteException::because(VoteException::EMAIL_NOT_VERIFIED);
            }
            if (!$this->hasActiveConsent($user)) {
                throw VoteException::because(VoteException::CONSENT_REQUIRED);
            }
            if ($subject->getVisibility() !== SubjectVisibility::PUBLISHED) {
                throw VoteException::because(VoteException::SUBJECT_NOT_VOTABLE);
            }
            if (self::isFrozen($subject)) {
                throw VoteException::because(VoteException::SUBJECT_FROZEN);
            }
            $configured = $this->em->find(SubjectVoteType::class, ['subject' => $subject->getId(), 'voteType' => $voteTypeCode]);
            if ($configured === null || !$configured->isActive()) {
                throw VoteException::because(VoteException::TYPE_NOT_ACTIVE);
            }
            $type = $configured->getVoteType();
            if (!$type->accepts($value)) {
                throw VoteException::because(VoteException::VALUE_OUT_OF_RANGE);
            }
            $revision = $this->currentRevision($subject);

            $vote = $this->existingVote($user, $subject, $type);
            $sameRevision = $vote !== null && $vote->getRevision()->getId()->equals($revision->getId());
            if ($vote !== null && $sameRevision && $vote->getValue() === $value) {
                return null;
            }
            $action = $vote === null ? VoteHistoryAction::VOTE : ($sameRevision ? VoteHistoryAction::CHANGE : VoteHistoryAction::RECONFIRM);
            $before = $vote?->getRevision();
            $valueBefore = $vote?->getValue();

            [$event, $nonce] = $this->chain->append($subject, IntegrityEventKind::VOTE_SET, IntegrityChain::privateContent(
                $subject, $type->getCode(), $before?->getNumber(), $valueBefore, $revision->getNumber(), $value,
            ));
            $this->em->persist(new UserVoteHistory($user, $subject, $type, $action, $valueBefore, $value, $before, $revision, $nonce, $event));
            if ($vote === null) {
                $this->em->persist(new Vote($user, $subject, $type, $revision, $value));
            } else {
                $vote->update($revision, $value);
            }
            $subject->markVoted();
            $this->em->flush();
            $this->results->recalculate($subject);
            $this->em->flush();

            return $action;
        });
    }

    /**
     * Retrage votul. Permis și pe subiecte înghețate, nepublicate sau pe tipuri dezactivate (ADR-0007 §5).
     *
     * @return bool false dacă nu exista vot
     */
    public function withdraw(User $user, CivicSubject $subject, string $voteTypeCode): bool
    {
        return $this->transactional(function () use ($user, $subject, $voteTypeCode): bool {
            $this->lock($user, $subject);
            $type = $this->em->find(VoteType::class, $voteTypeCode);
            $vote = $type === null ? null : $this->existingVote($user, $subject, $type);
            if ($vote === null) {
                return false;
            }
            [$event, $nonce] = $this->chain->append($subject, IntegrityEventKind::VOTE_WITHDRAWN, IntegrityChain::privateContent(
                $subject, $type->getCode(), $vote->getRevision()->getNumber(), $vote->getValue(), null, null,
            ));
            $this->em->persist(new UserVoteHistory($user, $subject, $type, VoteHistoryAction::WITHDRAW, $vote->getValue(), null, $vote->getRevision(), null, $nonce, $event));
            $this->em->remove($vote);
            $this->em->flush();
            $this->results->recalculate($subject);
            $this->em->flush();

            return true;
        });
    }

    /**
     * Retragerea consimțământului (ADR-0007 §6, §8): retrage toate voturile active, cu câte un eveniment fără
     * referință la persoană, apoi șterge voturile și întregul istoric privat, inclusiv nonce-urile.
     * Lock pe utilizator, apoi pe subiectele afectate în ordinea id-ului; funcționează pe orice stare a subiectului.
     *
     * @return int numărul voturilor retrase
     */
    public function withdrawConsent(User $user): int
    {
        return $this->transactional(function () use ($user): int {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            foreach ($this->em->getRepository(VoteConsent::class)->findBy(['user' => $user, 'withdrawnAt' => null]) as $consent) {
                $consent->withdraw();
            }
            $this->em->flush();

            return $this->eraseVotes($user);
        });
    }

    /** Partea de vot a ștergerii contului; apelantul ține lockul utilizatorului într-o tranzacție. */
    public function eraseVotes(User $user): int
    {
        /** @var list<Vote> $votes */
        $votes = $this->em->getRepository(Vote::class)->findBy(['user' => $user]);
        $bySubject = [];
        foreach ($votes as $vote) {
            $bySubject[$vote->getSubject()->getId()->toRfc4122()][] = $vote;
        }
        ksort($bySubject, \SORT_STRING);
        foreach ($bySubject as $subjectVotes) {
            $subject = $subjectVotes[0]->getSubject();
            $this->em->refresh($subject, LockMode::PESSIMISTIC_WRITE);
            foreach ($subjectVotes as $vote) {
                // Fără istoric: nonce-ul nu se păstrează nicăieri, deci commitmentul nu mai poate fi legat de persoană.
                $this->chain->append($subject, IntegrityEventKind::VOTE_WITHDRAWN, IntegrityChain::privateContent(
                    $subject, $vote->getVoteType()->getCode(), $vote->getRevision()->getNumber(), $vote->getValue(), null, null,
                ));
                $this->em->remove($vote);
            }
            $this->em->flush();
            $this->results->recalculate($subject);
        }
        $this->em->flush();
        $this->em->getConnection()->executeStatement('DELETE FROM user_vote_history WHERE user_id = ?', [$user->getId()->toRfc4122()]);

        return \count($votes);
    }

    public static function isFrozen(CivicSubject $subject): bool
    {
        return \in_array($subject->getStage(), [SubjectStage::RESOLVED, SubjectStage::CLOSED], true);
    }

    private function lock(User $user, CivicSubject $subject): void
    {
        $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($subject, LockMode::PESSIMISTIC_WRITE);
    }

    private function currentRevision(CivicSubject $subject): SubjectRevision
    {
        $revision = $this->em->getRepository(SubjectRevision::class)->findOneBy(['subject' => $subject, 'number' => $subject->getRevisionNumber()]);
        if ($revision === null || !$revision->belongsTo($subject)) {
            throw new \LogicException('Subiectul nu are revizia curentă '.$subject->getRevisionNumber().'.');
        }

        return $revision;
    }

    private function existingVote(User $user, CivicSubject $subject, VoteType $type): ?Vote
    {
        return $this->em->getRepository(Vote::class)->findOneBy(['user' => $user, 'subject' => $subject, 'voteType' => $type]);
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $result = $operation();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }
}
