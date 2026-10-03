<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\TreeNode;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class TreeNodeTest extends TestCase
{
    private User $autor;

    protected function setUp(): void
    {
        $this->autor = new User('admin@validez.test', 'Admin', [User::ROLE_ADMIN]);
    }

    public function testRadacinaArePathDoarIdulPropriu(): void
    {
        $drumuri = new TreeNode('Drumuri', $this->autor);

        self::assertNull($drumuri->getParent());
        self::assertSame(TreeNode::label($drumuri->getId()), $drumuri->getPath());
        self::assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $drumuri->getPath());
        self::assertSame(0, $drumuri->getDepth());
    }

    public function testCopilulPrelungestePathulParintelui(): void
    {
        $drumuri = new TreeNode('Drumuri', $this->autor);
        $cluj = new TreeNode('Cluj', $this->autor, $drumuri, 0);
        $calitate = new TreeNode('Calitate', $this->autor, $cluj, 0);
        $dn1 = new TreeNode('DN1', $this->autor, $calitate, 0);

        self::assertSame($calitate->getPath().'.'.TreeNode::label($dn1->getId()), $dn1->getPath());
        self::assertSame(3, $dn1->getDepth());
        self::assertStringStartsWith($drumuri->getPath().'.', $dn1->getPath());
    }

    public function testRedenumireaNuSchimbaPathul(): void
    {
        $cluj = new TreeNode('Cluj', $this->autor, new TreeNode('Drumuri', $this->autor));
        $calitate = new TreeNode('Calitate', $this->autor, $cluj);
        $path = $calitate->getPath();

        $calitate->rename('  Starea drumurilor ');

        self::assertSame('Starea drumurilor', $calitate->getName());
        self::assertSame($path, $calitate->getPath());
    }

    public function testIdulExistaInaintedePersistare(): void
    {
        self::assertNotSame((new TreeNode('A', $this->autor))->getId()->toBase32(), (new TreeNode('B', $this->autor))->getId()->toBase32());
    }

    public function testAutorulEsteCelDinConstructor(): void
    {
        self::assertSame($this->autor, (new TreeNode('A', $this->autor))->getCreatedBy());
    }

    public function testPozitiaNegativaEsteRespinsa(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TreeNode('X', $this->autor, null, -1);
    }
}
