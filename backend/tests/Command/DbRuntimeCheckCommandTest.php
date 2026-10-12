<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Pe baza de test conexiunea folosește utilizatorul de bootstrap (superuser): comanda trebuie să refuze.
 * Cazul pozitiv (validez_app cu rolurile configurate) e verificat de bin/test-deploy-db.sh pe un stack de producție simulat.
 */
final class DbRuntimeCheckCommandTest extends KernelTestCase
{
    public function testRejectsAConnectionThatIsNotTheRuntimeRole(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:db:runtime-check'));
        $code = $tester->execute(['--expect-user' => 'validez_app']);
        $output = preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(1, $code);
        self::assertStringContainsString('nu validez_app', $output);
        self::assertStringContainsString('rolul runtime are superuser', $output);
    }
}
