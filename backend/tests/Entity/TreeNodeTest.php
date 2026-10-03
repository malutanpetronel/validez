<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\TreeNode;
use PHPUnit\Framework\TestCase;

final class TreeNodeTest extends TestCase
{
    public function testRadacinaArePathDoarIdulPropriu(): void
    {
        $drumuri = new TreeNode('Drumuri');

        self::assertNull($drumuri->getParent());
        self::assertSame(TreeNode::label($drumuri->getId()), $drumuri->getPath());
        self::assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $drumuri->getPath());
        self::assertSame(0, $drumuri->getDepth());
    }

    public function testCopilulPrelungestePathulParintelui(): void
    {
        $drumuri = new TreeNode('Drumuri');
        $cluj = new TreeNode('Cluj', $drumuri, 0);
        $calitate = new TreeNode('Calitate', $cluj, 0);
        $dn1 = new TreeNode('DN1', $calitate, 0);

        self::assertSame($calitate->getPath().'.'.TreeNode::label($dn1->getId()), $dn1->getPath());
        self::assertSame(3, $dn1->getDepth());
        self::assertStringStartsWith($drumuri->getPath().'.', $dn1->getPath());
    }

    public function testRedenumireaNuSchimbaPathul(): void
    {
        $cluj = new TreeNode('Cluj', new TreeNode('Drumuri'));
        $calitate = new TreeNode('Calitate', $cluj);
        $path = $calitate->getPath();

        $calitate->rename('  Starea drumurilor ');

        self::assertSame('Starea drumurilor', $calitate->getName());
        self::assertSame($path, $calitate->getPath());
    }

    public function testIdulExistaInaintedePersistare(): void
    {
        self::assertNotSame((new TreeNode('A'))->getId()->toBase32(), (new TreeNode('B'))->getId()->toBase32());
    }

    public function testPozitiaNegativaEsteRespinsa(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TreeNode('X', null, -1);
    }
}
