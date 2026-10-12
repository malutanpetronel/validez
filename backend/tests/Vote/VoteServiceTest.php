<?php

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Entity\CivicSubject;
use App\Entity\SubjectVoteType;
use App\Entity\TreeNode;
use App\Entity\User;
use App\Entity\UserVoteHistory;
use App\Subject\SubjectRevisions;
use App\Subject\SubjectStage;
use App\Subject\SubjectType;
use App\Subject\SubjectVisibility;
use App\Tests\Support\Database;
use App\Vote\IntegrityChain;
use App\Vote\VoteException;
use App\Vote\VoteHistoryAction;
use App\Vote\VoteService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Step 4.b3 (ADR-0007): VoteService — reguli, jurnal, istoric privat, agregate, consimțământ. */
final class VoteServiceTest extends KernelTestCase
{
    private Connection $db;
    private EntityManagerInterface $em;
    private VoteService $votes;
    private IntegrityChain $chain;
    private SubjectRevisions $revisions;
    /** @var array<string, User> */
    private array $u = [];
    private TreeNode $node;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->db = $c->get(Connection::class);
        $this->em = $c->get(EntityManagerInterface::class);
        $this->votes = $c->get(VoteService::class);
        $this->chain = $c->get(IntegrityChain::class);
        $this->revisions = $c->get(SubjectRevisions::class);
        Database::reset($this->db);

        $unverified = new User('nou@validez.test', 'Nou');
        $unverified->requireEmailConfirmation();
        $this->u = ['ion' => new User('ion@validez.test', 'Ion'), 'maria' => new User('maria@validez.test', 'Maria'), 'nou' => $unverified];
        foreach ($this->u as $user) {
            $this->em->persist($user);
        }
        $this->node = new TreeNode('Drumuri', $this->u['ion'], null, 0);
        $this->em->persist($this->node);
        $this->em->flush();
        foreach (['ion', 'maria', 'nou'] as $who) {
            $this->votes->grantConsent($this->u[$who], $this->votes->getCurrentNoticeVersion());
        }
    }

    private function subject(SubjectType $type = SubjectType::ISSUE): CivicSubject
    {
        $subject = new CivicSubject($this->node, $this->u['ion'], $type, 'Groapă', 'Pe DN1');
        $this->em->persist($subject);
        $this->revisions->initialize($subject, $this->u['ion']);
        $this->em->flush();

        return $subject;
    }

    private function rejected(string $reason, callable $operation): void
    {
        try {
            $operation();
        } catch (VoteException $e) {
            self::assertSame($reason, $e->reason);

            return;
        }
        self::fail('Votul trebuia refuzat: '.$reason);
    }

    /** @return array{0: array<int, int>, 1: int, 2: int} */
    private function tally(CivicSubject $subject, string $type): array
    {
        $row = $this->db->fetchAssociative('SELECT current_counts::text AS c, current_total, stale_total FROM vote_result WHERE subject_id = ? AND vote_type_code = ?', [$subject->getId()->toRfc4122(), $type]);
        $counts = [];
        foreach (json_decode($row['c'], true) as $value => $count) {
            $counts[(int) $value] = $count;
        }

        return [$counts, (int) $row['current_total'], (int) $row['stale_total']];
    }

    private function log(CivicSubject $subject): array
    {
        return $this->db->fetchAllAssociative('SELECT sequence_number, kind FROM integrity_event_log WHERE subject_id = ? ORDER BY sequence_number', [$subject->getId()->toRfc4122()]);
    }

    private function edit(CivicSubject $subject, string $description): void
    {
        $before = SubjectRevisions::snapshot($subject);
        $subject->edit(null, $description, null);
        $this->revisions->recordIfChanged($subject, $before, $this->u['ion']);
        $this->em->flush();
    }

    public function testPreconditions(): void
    {
        $s = $this->subject();
        $this->rejected(VoteException::EMAIL_NOT_VERIFIED, fn () => $this->votes->cast($this->u['nou'], $s, 'VALIDITY', 1));
        $this->votes->withdrawConsent($this->u['maria']);
        $this->rejected(VoteException::CONSENT_REQUIRED, fn () => $this->votes->cast($this->u['maria'], $s, 'VALIDITY', 1));
        $this->rejected(VoteException::TYPE_NOT_ACTIVE, fn () => $this->votes->cast($this->u['ion'], $s, 'YES_NO', 1));
        $this->rejected(VoteException::VALUE_OUT_OF_RANGE, fn () => $this->votes->cast($this->u['ion'], $s, 'VALIDITY', 2));
        $this->rejected(VoteException::VALUE_OUT_OF_RANGE, fn () => $this->votes->cast($this->u['ion'], $s, 'IMPORTANCE', 0));
        foreach ([SubjectVisibility::PENDING, SubjectVisibility::HIDDEN] as $visibility) {
            $s->moderate(null, $visibility);
            $this->em->flush();
            $this->rejected(VoteException::SUBJECT_NOT_VOTABLE, fn () => $this->votes->cast($this->u['ion'], $s, 'VALIDITY', 1));
        }
        self::assertSame([], $this->log($s), 'refuzurile nu scriu nimic');
        self::assertNull($s->getFirstVotedAt());
    }

    public function testVoteChangeAndNoOpWriteChainHistoryAndResults(): void
    {
        $s = $this->subject();
        self::assertSame(VoteHistoryAction::VOTE, $this->votes->cast($this->u['ion'], $s, 'VALIDITY', 1));
        self::assertSame(VoteHistoryAction::VOTE, $this->votes->cast($this->u['maria'], $s, 'VALIDITY', 1));
        self::assertNotNull($s->getFirstVotedAt());
        self::assertSame([[1 => 2], 2, 0], $this->tally($s, 'VALIDITY'));

        self::assertNull($this->votes->cast($this->u['ion'], $s, 'VALIDITY', 1), 'același vot: nimic de scris');
        self::assertSame(VoteHistoryAction::CHANGE, $this->votes->cast($this->u['ion'], $s, 'VALIDITY', 0));
        self::assertSame([[0 => 1, 1 => 1], 2, 0], $this->tally($s, 'VALIDITY'));
        self::assertSame(VoteHistoryAction::VOTE, $this->votes->cast($this->u['ion'], $s, 'IMPORTANCE', 5));

        self::assertSame([1, 2, 3, 4], array_map('intval', array_column($this->log($s), 'sequence_number')));
        self::assertNull($this->chain->firstBrokenSequence($s));
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_vote_history WHERE user_id = ?', [$this->u['ion']->getId()->toRfc4122()]));
    }

    public function testCommitmentVerifiesOnlyWithPrivateDataAndNonce(): void
    {
        $s = $this->subject();
        $this->votes->cast($this->u['ion'], $s, 'VALIDITY', 1);
        /** @var UserVoteHistory $h */
        $h = $this->em->getRepository(UserVoteHistory::class)->findOneBy(['user' => $this->u['ion']]);
        $stored = $h->getIntegrityEvent()->getCommitment();

        self::assertTrue(hash_equals($stored, IntegrityChain::commitment(IntegrityChain::privateContent($s, 'VALIDITY', null, null, 1, 1), $h->getNonce())));
        // Spațiul mic de opțiuni nu ajunge: fără nonce, „ghicirea” valorii nu reproduce commitmentul.
        self::assertFalse(hash_equals($stored, IntegrityChain::commitment(IntegrityChain::privateContent($s, 'VALIDITY', null, null, 1, 0), $h->getNonce())));
        self::assertFalse(hash_equals($stored, IntegrityChain::commitment(IntegrityChain::privateContent($s, 'VALIDITY', null, null, 1, 1), str_repeat("\0", 32))));
        $columns = $this->db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'integrity_event_log'");
        self::assertSame([], array_intersect($columns, ['user_id', 'vote_id', 'value', 'nonce', 'ip']));
    }

    public function testNewRevisionMakesVotesUnconfirmedAndReconfirmRestoresThem(): void
    {
        $s = $this->subject();
        $this->votes->cast($this->u['ion'], $s, 'VALIDITY', 1);
        $this->votes->cast($this->u['maria'], $s, 'VALIDITY', 0);
        $this->edit($s, 'Pe DN1, km 12');
        self::assertSame([[], 0, 2], $this->tally($s, 'VALIDITY'));

        self::assertSame(VoteHistoryAction::RECONFIRM, $this->votes->cast($this->u['ion'], $s, 'VALIDITY', 1), 'aceeași valoare pe revizie nouă');
        self::assertSame([[1 => 1], 1, 1], $this->tally($s, 'VALIDITY'));
        self::assertSame(VoteHistoryAction::RECONFIRM, $this->votes->cast($this->u['maria'], $s, 'VALIDITY', 1), 'altă valoare pe revizie nouă');
        self::assertSame([[1 => 2], 2, 0], $this->tally($s, 'VALIDITY'));
        self::assertNull($this->chain->firstBrokenSequence($s));
    }

    public function testFreezeAllowsOnlyWithdrawalAndReopeningDoesNotReactivate(): void
    {
        $s = $this->subject();
        $this->votes->cast($this->u['ion'], $s, 'VALIDITY', 1);
        $this->votes->cast($this->u['maria'], $s, 'VALIDITY', 1);
        $this->edit($s, 'Revizie înainte de închidere');

        foreach ([SubjectStage::RESOLVED, SubjectStage::CLOSED] as $stage) {
            $s->moderate($stage, null);
            $this->em->flush();
            $this->rejected(VoteException::SUBJECT_FROZEN, fn () => $this->votes->cast($this->u['ion'], $s, 'VALIDITY', 0));
            $this->rejected(VoteException::SUBJECT_FROZEN, fn () => $this->votes->cast($this->u['ion'], $s, 'VALIDITY', 1));
        }
        self::assertTrue($this->votes->withdraw($this->u['ion'], $s, 'VALIDITY'));
        self::assertSame([[], 0, 1], $this->tally($s, 'VALIDITY'), 'retragerea scade și contorul nereconfirmat');

        $s->moderate(SubjectStage::OPEN, null);
        $this->em->flush();
        self::assertSame([[], 0, 1], $this->tally($s, 'VALIDITY'), 'redeschiderea nu reactivează nimic');
        self::assertSame(VoteHistoryAction::VOTE, $this->votes->cast($this->u['ion'], $s, 'VALIDITY', 0), 'vot nou pe revizia curentă');
        self::assertSame([[0 => 1], 1, 1], $this->tally($s, 'VALIDITY'));
    }

    public function testWithdrawalWorksOnHiddenSubjectsAndInactiveTypes(): void
    {
        $s = $this->subject();
        $this->votes->cast($this->u['ion'], $s, 'VALIDITY', 1);
        $this->votes->cast($this->u['ion'], $s, 'IMPORTANCE', 3);

        $config = $this->em->find(SubjectVoteType::class, ['subject' => $s->getId(), 'voteType' => 'IMPORTANCE']);
        $config->setActive(false);
        $this->em->flush();
        $this->rejected(VoteException::TYPE_NOT_ACTIVE, fn () => $this->votes->cast($this->u['ion'], $s, 'IMPORTANCE', 4));
        self::assertTrue($this->votes->withdraw($this->u['ion'], $s, 'IMPORTANCE'));

        $s->moderate(null, SubjectVisibility::HIDDEN);
        $this->em->flush();
        self::assertTrue($this->votes->withdraw($this->u['ion'], $s, 'VALIDITY'));
        self::assertFalse($this->votes->withdraw($this->u['ion'], $s, 'VALIDITY'), 'nimic de retras');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM vote'));
        self::assertSame(['VOTE_SET', 'VOTE_SET', 'VOTE_WITHDRAWN', 'VOTE_WITHDRAWN'], array_column($this->log($s), 'kind'));
        self::assertNotNull($s->getFirstVotedAt(), 'tipul rămâne blocat după retragere');
        self::assertNull($this->chain->firstBrokenSequence($s));
    }

    public function testConsentWithdrawalErasesVotesAndPrivateHistoryEverywhere(): void
    {
        $a = $this->subject();
        $b = $this->subject(SubjectType::PROPOSAL);
        $this->votes->cast($this->u['ion'], $a, 'VALIDITY', 1);
        $this->votes->cast($this->u['ion'], $a, 'IMPORTANCE', 2);
        $this->votes->cast($this->u['ion'], $b, 'YES_NO', 1);
        $this->votes->cast($this->u['maria'], $a, 'VALIDITY', 0);
        $b->moderate(SubjectStage::CLOSED, null);
        $this->em->flush();

        self::assertSame(3, $this->votes->withdrawConsent($this->u['ion']));

        $ion = $this->u['ion']->getId()->toRfc4122();
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM vote WHERE user_id = ?', [$ion]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_vote_history WHERE user_id = ?', [$ion]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM vote_consent WHERE user_id = ? AND withdrawn_at IS NULL', [$ion]));
        self::assertSame([[0 => 1], 1, 0], $this->tally($a, 'VALIDITY'), 'votul Mariei rămâne');
        self::assertSame([[], 0, 0], $this->tally($a, 'IMPORTANCE'));
        self::assertSame([[], 0, 0], $this->tally($b, 'YES_NO'), 'și pe subiectul închis');
        self::assertSame(['VOTE_SET', 'VOTE_SET', 'VOTE_SET', 'VOTE_WITHDRAWN', 'VOTE_WITHDRAWN'], array_column($this->log($a), 'kind'));
        self::assertNull($this->chain->firstBrokenSequence($a));
        self::assertNull($this->chain->firstBrokenSequence($b));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_vote_history'), 'istoricul Mariei rămâne');

        $this->rejected(VoteException::CONSENT_REQUIRED, fn () => $this->votes->cast($this->u['ion'], $a, 'VALIDITY', 1));
        $this->votes->grantConsent($this->u['ion'], $this->votes->getCurrentNoticeVersion());
        self::assertSame(VoteHistoryAction::VOTE, $this->votes->cast($this->u['ion'], $a, 'VALIDITY', 1), 'voturile retrase nu se reactivează; vot nou');
    }

    public function testOutdatedNoticeBlocksVotingButKeepsExistingVotes(): void
    {
        $current = $this->votes->getCurrentNoticeVersion();
        self::assertSame($current, $this->votes->grantConsent($this->u['ion'], $current)->getNoticeVersion(), 'același acord, fără rând nou');
        $this->rejected(VoteException::NOTICE_VERSION_MISMATCH, fn () => $this->votes->grantConsent($this->u['ion'], 'alta'));

        $s = $this->subject();
        $this->votes->cast($this->u['ion'], $s, 'VALIDITY', 1);
        // Informarea se actualizează: acordul lui Ion e pe versiunea veche.
        $this->db->executeStatement("UPDATE vote_consent SET notice_version = 'veche' WHERE user_id = ?", [$this->u['ion']->getId()->toRfc4122()]);
        $this->em->clear();
        $ion = $this->em->find(User::class, $this->u['ion']->getId());
        $s = $this->em->find(CivicSubject::class, $s->getId());

        self::assertSame(['active' => true, 'current' => false, 'noticeVersion' => 'veche', 'currentNoticeVersion' => $current], $this->votes->consentState($ion));
        $this->rejected(VoteException::CONSENT_REQUIRED, fn () => $this->votes->cast($ion, $s, 'VALIDITY', 0));
        self::assertSame([[1 => 1], 1, 0], $this->tally($s, 'VALIDITY'), 'votul existent rămâne');

        $this->votes->grantConsent($ion, $current);
        $rows = $this->db->fetchAllKeyValue('SELECT notice_version, withdrawn_at IS NULL FROM vote_consent WHERE user_id = ? ORDER BY granted_at, notice_version', [$ion->getId()->toRfc4122()]);
        self::assertEquals(['veche' => false, $current => true], $rows);
        self::assertSame(VoteHistoryAction::CHANGE, $this->votes->cast($ion, $s, 'VALIDITY', 0));
    }
}
