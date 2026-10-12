<?php

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Entity\CivicSubject;
use App\Entity\SubjectRevision;
use App\Entity\TreeNode;
use App\Entity\User;
use App\Entity\VoteConsent;
use App\Entity\VoteResult;
use App\Entity\VoteType;
use App\Subject\SubjectType;
use App\Tests\Support\Database;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

/** Step 4.b1 (ADR-0007): constrângerile schemei de vot, verificate direct în PostgreSQL. */
final class VoteSchemaTest extends KernelTestCase
{
    private Connection $db;
    private EntityManagerInterface $em;
    private User $user;
    private CivicSubject $subject;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->db = $c->get(Connection::class);
        $this->em = $c->get(EntityManagerInterface::class);
        Database::reset($this->db);

        $this->user = new User('ion@validez.test', 'Ion');
        $node = new TreeNode('Drumuri', $this->user, null, 0);
        $this->subject = new CivicSubject($node, $this->user, SubjectType::ISSUE, 'Groapă', 'Pe DN1');
        foreach ([$this->user, $node, $this->subject] as $e) {
            $this->em->persist($e);
        }
        $this->em->flush();
    }

    private function sid(): string { return $this->subject->getId()->toRfc4122(); }

    private function assertRejected(string $sql, array $params = []): void
    {
        try {
            $this->db->executeStatement($sql, $params);
        } catch (DBALException $e) {
            self::assertTrue(true);

            return;
        }
        self::fail('PostgreSQL a acceptat: '.$sql);
    }

    /** Inserează în jurnal; valorile binare ca hex. */
    private function logRow(int $seq, ?string $prevHex, ?string $commitHex = null, ?string $hashHex = null): string
    {
        $id = (new Ulid())->toRfc4122();
        $this->db->executeStatement(
            "INSERT INTO integrity_event_log (id, subject_id, sequence_number, format_version, kind, commitment, previous_event_hash, event_hash, created_at)
             VALUES (?, ?, ?, 1, 'VOTE_SET', decode(?, 'hex'), decode(?, 'hex'), decode(?, 'hex'), NOW())",
            [$id, $this->sid(), $seq, $commitHex ?? str_repeat('aa', 32), $prevHex, $hashHex ?? bin2hex(random_bytes(32))],
        );

        return $id;
    }

    public function testVoteTypesAreSeededWithValidRanges(): void
    {
        $rows = $this->db->fetchAllKeyValue('SELECT code, min_value || \'-\' || max_value FROM vote_type ORDER BY code');
        self::assertSame(['IMPORTANCE' => '1-5', 'VALIDITY' => '0-1', 'YES_NO' => '0-1'], $rows);
        $this->assertRejected("INSERT INTO vote_type (code, min_value, max_value) VALUES ('BAD', 3, 1)");
        self::assertFalse((new VoteType('X', 1, 5))->accepts(0));
    }

    public function testRevisionAndSubjectNumbersArePositive(): void
    {
        self::assertSame(1, (int) $this->db->fetchOne('SELECT revision_number FROM civic_subject WHERE id = ?', [$this->sid()]));
        $this->assertRejected('UPDATE civic_subject SET revision_number = 0 WHERE id = ?', [$this->sid()]);
        $this->assertRejected(
            "INSERT INTO subject_revision (id, subject_id, number, type, title, description, created_by, created_at) VALUES (?, ?, 0, 'ISSUE', 't', 'd', ?, NOW())",
            [(new Ulid())->toRfc4122(), $this->sid(), $this->user->getId()->toRfc4122()],
        );
    }

    public function testRevisionSnapshotKeepsTypeAndHidesEstimateOnIneligibleTypes(): void
    {
        $this->subject->setEstimate('970.00', 'EUR', 'Kiss & Ride');
        $issue = new SubjectRevision($this->subject, 1, $this->user);
        self::assertSame(SubjectType::ISSUE, $issue->getType());
        self::assertNull($issue->publicEstimate());

        $this->subject->edit(null, null, SubjectType::PROPOSAL);
        $proposal = new SubjectRevision($this->subject, 2, $this->user);
        self::assertSame(['costEstimate' => '970.00', 'costCurrency' => 'EUR', 'costEstimateScope' => 'Kiss & Ride'], $proposal->publicEstimate());
    }

    public function testIntegrityLogEnforcesChainShapeAndLengths(): void
    {
        $this->assertRejected("INSERT INTO integrity_event_log (id, subject_id, sequence_number, format_version, kind, commitment, event_hash, created_at) VALUES (?, ?, 0, 1, 'VOTE_SET', decode(?, 'hex'), decode(?, 'hex'), NOW())", [(new Ulid())->toRfc4122(), $this->sid(), str_repeat('aa', 32), str_repeat('bb', 32)]);
        // primul eveniment cu hash anterior
        $this->assertRejected("INSERT INTO integrity_event_log (id, subject_id, sequence_number, format_version, kind, commitment, previous_event_hash, event_hash, created_at) VALUES (?, ?, 1, 1, 'VOTE_SET', decode(?, 'hex'), decode(?, 'hex'), decode(?, 'hex'), NOW())", [(new Ulid())->toRfc4122(), $this->sid(), str_repeat('aa', 32), str_repeat('cc', 32), str_repeat('bb', 32)]);
        // commitment de 31 de octeți
        $this->assertRejected("INSERT INTO integrity_event_log (id, subject_id, sequence_number, format_version, kind, commitment, event_hash, created_at) VALUES (?, ?, 1, 1, 'VOTE_SET', decode(?, 'hex'), decode(?, 'hex'), NOW())", [(new Ulid())->toRfc4122(), $this->sid(), str_repeat('aa', 31), str_repeat('bb', 32)]);
        // tip necunoscut
        $this->assertRejected("INSERT INTO integrity_event_log (id, subject_id, sequence_number, format_version, kind, commitment, event_hash, created_at) VALUES (?, ?, 1, 1, 'OTHER', decode(?, 'hex'), decode(?, 'hex'), NOW())", [(new Ulid())->toRfc4122(), $this->sid(), str_repeat('aa', 32), str_repeat('bb', 32)]);

        $first = bin2hex(random_bytes(32));
        $this->logRow(1, null, null, $first);
        // al doilea fără hash anterior / cu hash anterior scurt / secvență duplicată
        $this->assertRejected("INSERT INTO integrity_event_log (id, subject_id, sequence_number, format_version, kind, commitment, event_hash, created_at) VALUES (?, ?, 2, 1, 'VOTE_SET', decode(?, 'hex'), decode(?, 'hex'), NOW())", [(new Ulid())->toRfc4122(), $this->sid(), str_repeat('aa', 32), str_repeat('dd', 32)]);
        $this->assertRejected("INSERT INTO integrity_event_log (id, subject_id, sequence_number, format_version, kind, commitment, previous_event_hash, event_hash, created_at) VALUES (?, ?, 2, 1, 'VOTE_SET', decode(?, 'hex'), decode(?, 'hex'), decode(?, 'hex'), NOW())", [(new Ulid())->toRfc4122(), $this->sid(), str_repeat('aa', 32), str_repeat('ee', 16), str_repeat('dd', 32)]);
        $this->assertRejected("INSERT INTO integrity_event_log (id, subject_id, sequence_number, format_version, kind, commitment, event_hash, created_at) VALUES (?, ?, 1, 1, 'VOTE_SET', decode(?, 'hex'), decode(?, 'hex'), NOW())", [(new Ulid())->toRfc4122(), $this->sid(), str_repeat('aa', 32), str_repeat('dd', 32)]);
        // același event_hash
        $this->assertRejected("INSERT INTO integrity_event_log (id, subject_id, sequence_number, format_version, kind, commitment, previous_event_hash, event_hash, created_at) VALUES (?, ?, 2, 1, 'VOTE_SET', decode(?, 'hex'), decode(?, 'hex'), decode(?, 'hex'), NOW())", [(new Ulid())->toRfc4122(), $this->sid(), str_repeat('aa', 32), $first, $first]);

        $this->logRow(2, $first);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT MAX(sequence_number) FROM integrity_event_log WHERE subject_id = ?', [$this->sid()]));
    }

    public function testIntegrityLogIsAppendOnly(): void
    {
        $id = $this->logRow(1, null);
        $this->assertRejected("UPDATE integrity_event_log SET kind = 'VOTE_WITHDRAWN' WHERE id = ?", [$id]);
        $this->assertRejected('DELETE FROM integrity_event_log WHERE id = ?', [$id]);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM integrity_event_log'));
    }

    public function testHistoryNonceLengthAndActionShape(): void
    {
        $event = $this->logRow(1, null);
        $revision = new SubjectRevision($this->subject, 1, $this->user);
        $this->em->persist($revision);
        $this->em->flush();
        $sql = "INSERT INTO user_vote_history (id, user_id, subject_id, vote_type_code, action, value_before, value_after, revision_before_id, revision_after_id, nonce, integrity_event_id, created_at)
                VALUES (?, ?, ?, 'VALIDITY', ?, NULL, ?, NULL, ?, decode(?, 'hex'), ?, NOW())";
        $base = fn (string $action, ?int $after, ?string $rev, string $nonceHex): array => [(new Ulid())->toRfc4122(), $this->user->getId()->toRfc4122(), $this->sid(), $action, $after, $rev, $nonceHex, $event];
        $rev = $revision->getId()->toRfc4122();

        $this->assertRejected($sql, $base('VOTE', 1, $rev, str_repeat('ab', 31)));
        $this->assertRejected($sql, $base('VOTE', null, null, str_repeat('ab', 32)));
        $this->assertRejected($sql, $base('WITHDRAW', 1, $rev, str_repeat('ab', 32)));
        $this->db->executeStatement($sql, $base('VOTE', 1, $rev, str_repeat('ab', 32)));
        $this->db->executeStatement($sql, $base('WITHDRAW', null, null, str_repeat('ab', 32)));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_vote_history'));
    }

    public function testOneVotePerSubjectUserAndType(): void
    {
        $revision = new SubjectRevision($this->subject, 1, $this->user);
        $this->em->persist($revision);
        $this->em->flush();
        $sql = "INSERT INTO vote (id, user_id, subject_id, vote_type_code, revision_id, value, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())";
        $args = fn (string $type): array => [(new Ulid())->toRfc4122(), $this->user->getId()->toRfc4122(), $this->sid(), $type, $revision->getId()->toRfc4122()];

        $this->db->executeStatement($sql, $args('VALIDITY'));
        $this->db->executeStatement($sql, $args('IMPORTANCE'));
        $this->assertRejected($sql, $args('VALIDITY'));
    }

    public function testOnlyOneActiveConsentPerUser(): void
    {
        $first = new VoteConsent($this->user, '2026-10-12');
        $this->em->persist($first);
        $this->em->flush();
        $this->assertRejected(
            "INSERT INTO vote_consent (id, user_id, notice_version, granted_at) VALUES (?, ?, 'v2', NOW())",
            [(new Ulid())->toRfc4122(), $this->user->getId()->toRfc4122()],
        );

        // Doctrine face INSERT-urile înaintea UPDATE-urilor: retragerea trebuie scrisă înainte de acordarea nouă.
        $first->withdraw();
        $this->em->flush();
        $this->em->persist(new VoteConsent($this->user, 'v2'));
        $this->em->flush();
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM vote_consent WHERE user_id = ?', [$this->user->getId()->toRfc4122()]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM vote_consent WHERE withdrawn_at IS NULL'));
    }

    public function testResultCountsStayAJsonObject(): void
    {
        $validity = $this->em->find(VoteType::class, 'VALIDITY');
        $result = new VoteResult($this->subject, $validity);
        $this->em->persist($result);
        $this->em->flush();
        // rezultat gol
        self::assertSame('{}', $this->db->fetchOne('SELECT current_counts::text FROM vote_result'));

        // chei 0 și 1 — tipul json standard le-ar scrie ca listă [3, 5]
        $result->replace([0 => 3, 1 => 5], 2);
        $this->em->flush();
        self::assertSame('object', $this->db->fetchOne('SELECT jsonb_typeof(current_counts) FROM vote_result'));
        self::assertSame('{"0": 3, "1": 5}', $this->db->fetchOne('SELECT current_counts::text FROM vote_result'));
        self::assertSame([8, 2], array_map('intval', $this->db->fetchNumeric('SELECT current_total, stale_total FROM vote_result')));

        $this->em->clear();
        $reloaded = $this->em->find(VoteResult::class, ['subject' => $this->subject->getId(), 'voteType' => 'VALIDITY']);
        self::assertSame([0 => 3, 1 => 5], $reloaded->getCurrentCounts());

        $this->assertRejected("UPDATE vote_result SET current_counts = '[3, 5]'::jsonb");
        $this->assertRejected('UPDATE vote_result SET stale_total = -1');
    }

    public function testResultRejectsValuesOutsideTheTypeRange(): void
    {
        $result = new VoteResult($this->subject, $this->em->find(VoteType::class, 'IMPORTANCE'));
        $this->expectException(\InvalidArgumentException::class);
        $result->replace([0 => 1], 0);
    }
}
