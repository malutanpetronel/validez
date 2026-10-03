<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\TreeEditVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class TreeEditVoterTest extends TestCase
{
    public function testDevPermiteScriereaFaraAutentificare(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, (new TreeEditVoter('dev'))->vote(new NullToken(), null, [TreeEditVoter::EDIT]));
    }

    public function testProdRefuzaVizitatorul(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, (new TreeEditVoter('prod'))->vote(new NullToken(), null, [TreeEditVoter::EDIT]));
    }

    public function testProdPermiteAdministratorul(): void
    {
        $token = new UsernamePasswordToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']);
        self::assertSame(VoterInterface::ACCESS_GRANTED, (new TreeEditVoter('prod'))->vote($token, null, [TreeEditVoter::EDIT]));
    }

    public function testAlteAtributeNuSuntVotate(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, (new TreeEditVoter('dev'))->vote(new NullToken(), null, ['ALTCEVA']));
    }
}
