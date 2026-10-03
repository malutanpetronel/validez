<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\TreeEditVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class TreeEditVoterTest extends TestCase
{
    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'api', $user->getRoles());
    }

    public function testVizitatorulEsteRefuzat(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, (new TreeEditVoter())->vote(new NullToken(), null, [TreeEditVoter::EDIT]));
    }

    public function testUtilizatorulFaraRolAdminEsteRefuzat(): void
    {
        $token = $this->token(new User('ion@validez.test', 'Ion'));
        self::assertSame(VoterInterface::ACCESS_DENIED, (new TreeEditVoter())->vote($token, null, [TreeEditVoter::EDIT]));
    }

    public function testAdministratorulPoateModifica(): void
    {
        $token = $this->token(new User('admin@validez.test', 'Admin', [User::ROLE_ADMIN]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, (new TreeEditVoter())->vote($token, null, [TreeEditVoter::EDIT]));
    }

    public function testAlteAtributeNuSuntVotate(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, (new TreeEditVoter())->vote(new NullToken(), null, ['ALTCEVA']));
    }
}
