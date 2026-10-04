<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateUserCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $db = static::getContainer()->get(Connection::class);
        foreach (['civic_subject', 'tree_node', 'refresh_token', 'app_user'] as $t) {
            $db->executeStatement("DELETE FROM $t");
        }
        $this->tester = new CommandTester((new Application($kernel))->find('app:user:create'));
    }

    public function testCreeazaAdministrator(): void
    {
        $this->tester->setInputs(['parola-lunga-1', 'parola-lunga-1']);
        $this->tester->execute(['email' => ' Admin@Validez.test', 'displayName' => 'Admina', '--admin' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $user = static::getContainer()->get(UserRepository::class)->loadUserByIdentifier('admin@validez.test');
        self::assertNotNull($user);
        self::assertTrue($user->isAdmin());
        self::assertNotSame('parola-lunga-1', $user->getPassword(), 'parola trebuie stocata ca hash');
    }

    public function testRespingeParolaScurtaParoleDiferiteSiEmailDuplicat(): void
    {
        $this->tester->setInputs(['scurta']);
        self::assertSame(Command::FAILURE, $this->tester->execute(['email' => 'a@validez.test', 'displayName' => 'A']));

        $this->tester->setInputs(['parola-lunga-1', 'alta-parola-2']);
        self::assertSame(Command::FAILURE, $this->tester->execute(['email' => 'a@validez.test', 'displayName' => 'A']));

        $this->tester->setInputs(['parola-lunga-1', 'parola-lunga-1']);
        self::assertSame(Command::SUCCESS, $this->tester->execute(['email' => 'a@validez.test', 'displayName' => 'A']));
        self::assertSame(Command::FAILURE, $this->tester->execute(['email' => 'A@validez.test', 'displayName' => 'A']));
        self::assertStringContainsString('Există deja', $this->tester->getDisplay());
    }

    public function testRespingeEmailInvalid(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute(['email' => 'nu-e-email', 'displayName' => 'A']));
    }
}
