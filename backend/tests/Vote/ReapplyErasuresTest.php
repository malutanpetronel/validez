<?php

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Entity\CivicSubject;
use App\Entity\TreeNode;
use App\Entity\User;
use App\Subject\SubjectRevisions;
use App\Subject\SubjectType;
use App\Tests\Support\Database;
use App\Vote\ErasureRegistry;
use App\Vote\VoteService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Ulid;

/**
 * ADR-0009: retragerea acordului se notează în registru, iar după o restaurare se reaplică.
 * „Copia restaurată” e simulată prin starea curentă a bazei (acord activ + voturi), cu o retragere notată după ea.
 */
final class ReapplyErasuresTest extends KernelTestCase
{
    private Connection $db;
    private EntityManagerInterface $em;
    private VoteService $votes;
    private ErasureRegistry $registry;
    /** @var array<string, User> */
    private array $u = [];
    private CivicSubject $subject;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->db = $c->get(Connection::class);
        $this->em = $c->get(EntityManagerInterface::class);
        $this->votes = $c->get(VoteService::class);
        $this->registry = $c->get(ErasureRegistry::class);
        Database::reset($this->db);
        @unlink($this->registry->path());

        $this->u = ['ion' => new User('ion@validez.test', 'Ion'), 'maria' => new User('maria@validez.test', 'Maria')];
        foreach ($this->u as $user) {
            $this->em->persist($user);
        }
        $node = new TreeNode('Drumuri', $this->u['ion'], null, 0);
        $this->em->persist($node);
        $this->subject = new CivicSubject($node, $this->u['ion'], SubjectType::ISSUE, 'Groapă', 'Pe DN1');
        $this->em->persist($this->subject);
        $c->get(SubjectRevisions::class)->initialize($this->subject, $this->u['ion']);
        $this->em->flush();
        foreach ($this->u as $user) {
            $this->votes->grantConsent($user, $this->votes->getCurrentNoticeVersion());
            $this->votes->cast($user, $this->subject, 'VALIDITY', 1);
        }
    }

    private function rows(string $sql, User $user): int
    {
        return (int) $this->db->fetchOne($sql, [$user->getId()->toRfc4122()]);
    }

    private function state(User $user): array
    {
        return [
            $this->rows('SELECT COUNT(*) FROM vote_consent WHERE user_id = ? AND withdrawn_at IS NULL', $user),
            $this->rows('SELECT COUNT(*) FROM vote WHERE user_id = ?', $user),
            $this->rows('SELECT COUNT(*) FROM user_vote_history WHERE user_id = ?', $user),
        ];
    }

    private function reapply(bool $dryRun = false): string
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:votes:reapply-erasures'));
        self::assertSame(0, $tester->execute($dryRun ? ['--dry-run' => true] : []));

        return $tester->getDisplay();
    }

    public function testWithdrawConsentRecordsTheMomentOfWithdrawal(): void
    {
        $this->votes->withdrawConsent($this->u['ion']);

        $entries = $this->registry->entries();
        self::assertCount(1, $entries);
        self::assertSame($this->u['ion']->getId()->toBase32(), $entries[0]['user']);
        // În bază momentul are secunde întregi (Doctrine, datetimetz); în registru păstrează și microsecundele.
        $withdrawnAt = new \DateTimeImmutable($this->db->fetchOne('SELECT withdrawn_at FROM vote_consent WHERE user_id = ?', [$this->u['ion']->getId()->toRfc4122()]));
        self::assertSame($withdrawnAt->getTimestamp(), $entries[0]['at']->getTimestamp());
    }

    public function testWithdrawalAfterTheBackupIsReappliedOnlyForThatUser(): void
    {
        $this->registry->record($this->u['ion']->getId(), ErasureRegistry::CONSENT_WITHDRAWN, new \DateTimeImmutable('+1 second'));

        $display = $this->reapply(true);
        self::assertStringContainsString('Simulare', $display);
        self::assertSame([1, 1, 1], $this->state($this->u['ion']), '--dry-run nu modifică nimic');

        $this->reapply();
        self::assertSame([0, 0, 0], $this->state($this->u['ion']));
        self::assertSame([1, 1, 1], $this->state($this->u['maria']), 'ceilalți utilizatori rămân neatinși');
        self::assertCount(1, $this->registry->entries(), 'reaplicarea nu adaugă intrări');
        self::assertSame(0, (new CommandTester((new Application(static::$kernel))->find('app:votes:verify')))->execute([]), 'datele de vot rămân consistente');

        $this->reapply();
        self::assertSame([0, 0, 0], $this->state($this->u['ion']), 'a doua rulare nu schimbă nimic');
    }

    public function testConsentGrantedAgainAfterTheWithdrawalIsKept(): void
    {
        $this->registry->record($this->u['ion']->getId(), ErasureRegistry::CONSENT_WITHDRAWN, new \DateTimeImmutable('-1 hour'));

        $this->reapply();
        self::assertSame([1, 1, 1], $this->state($this->u['ion']), 'acordul de după retragere și voturile lui rămân');
    }

    public function testUserMissingFromTheRestoredCopyIsSkipped(): void
    {
        $this->registry->record(new Ulid(), ErasureRegistry::CONSENT_WITHDRAWN, new \DateTimeImmutable('+1 second'));

        self::assertMatchesRegularExpression('/inexistenți în copia restaurată\s+1/', $this->reapply());
        self::assertSame([1, 1, 1], $this->state($this->u['ion']));
    }
}
