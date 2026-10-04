<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Entity\TreeNode;
use App\Entity\User;
use App\Tree\TreeMoveException;
use App\Tree\TreeMover;
use App\Tree\TreeNodeNotFoundException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

/**
 * Integrare pe baza de test (validez_test): SQL-ul ltree si constrangerile reale.
 */
final class TreeMoverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;
    private TreeMover $mover;
    private User $autor;

    /** @var array<string, TreeNode> */
    private array $n = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->db = $c->get(Connection::class);
        $this->mover = $c->get(TreeMover::class);
        $this->db->executeStatement('DELETE FROM civic_subject');
        $this->db->executeStatement('DELETE FROM tree_node');
        $this->db->executeStatement('DELETE FROM refresh_token');
        $this->db->executeStatement('DELETE FROM app_user');
        $this->autor = new User('admin@validez.test', 'Admin', [User::ROLE_ADMIN]);
        $this->em->persist($this->autor);

        // Drumuri(0) > [Cluj(0) > Calitate(0) > DN1(0), Bistrita(1), Alba(2)] ; Sanatate(1)
        $this->add('Drumuri', null, 0);
        $this->add('Cluj', 'Drumuri', 0);
        $this->add('Calitate', 'Cluj', 0);
        $this->add('DN1', 'Calitate', 0);
        $this->add('Bistrita', 'Drumuri', 1);
        $this->add('Alba', 'Drumuri', 2);
        $this->add('Sanatate', null, 1);
        $this->em->flush();
    }

    private function add(string $name, ?string $parent, int $position): void
    {
        $this->n[$name] = new TreeNode($name, $this->autor, $parent === null ? null : $this->n[$parent], $position);
        $this->em->persist($this->n[$name]);
    }

    private function id(string $name): Ulid
    {
        return $this->n[$name]->getId();
    }

    /** @return array{parent: ?string, path: string, position: int} */
    private function row(string $name): array
    {
        $r = $this->db->fetchAssociative(
            'SELECT p.name AS parent, t.path::text AS path, t.position FROM tree_node t LEFT JOIN tree_node p ON p.id = t.parent_id WHERE t.name = :n',
            ['n' => $name],
        );

        return ['parent' => $r['parent'], 'path' => $r['path'], 'position' => (int) $r['position']];
    }

    /** @return list<string> numele copiilor in ordine */
    private function children(?string $parent): array
    {
        $sql = 'SELECT t.name FROM tree_node t LEFT JOIN tree_node p ON p.id = t.parent_id WHERE '
            .($parent === null ? 't.parent_id IS NULL' : 'p.name = :p').' ORDER BY t.position';

        return $this->db->fetchFirstColumn($sql, $parent === null ? [] : ['p' => $parent]);
    }

    /** Pozitii dense 0..n-1 sub fiecare parinte (inclusiv radacinile). */
    private function assertPozitiiDense(): void
    {
        $bad = $this->db->fetchFirstColumn(
            'SELECT coalesce(parent_id::text, \'root\') FROM tree_node GROUP BY parent_id HAVING max(position) <> count(*) - 1 OR min(position) <> 0',
        );
        self::assertSame([], $bad, 'pozitii cu goluri sau duplicate');
    }

    private function label(string $name): string
    {
        return $this->id($name)->toBase32();
    }

    public function testMutareSubarboreSubAltParinteRescrieCaileDescendentilor(): void
    {
        $this->mover->move($this->id('Cluj'), $this->id('Sanatate'), 0);

        self::assertSame('Sanatate', $this->row('Cluj')['parent']);
        self::assertSame($this->label('Sanatate').'.'.$this->label('Cluj'), $this->row('Cluj')['path']);
        self::assertSame(
            $this->label('Sanatate').'.'.$this->label('Cluj').'.'.$this->label('Calitate').'.'.$this->label('DN1'),
            $this->row('DN1')['path'],
        );
        self::assertSame(['Bistrita', 'Alba'], $this->children('Drumuri'));
        self::assertSame(['Cluj'], $this->children('Sanatate'));
        $this->assertPozitiiDense();
    }

    public function testOrdonareIntreFrati(): void
    {
        $this->mover->move($this->id('Alba'), $this->id('Drumuri'), 0);
        self::assertSame(['Alba', 'Cluj', 'Bistrita'], $this->children('Drumuri'));

        $this->mover->move($this->id('Alba'), $this->id('Drumuri'), null);
        self::assertSame(['Cluj', 'Bistrita', 'Alba'], $this->children('Drumuri'));

        $this->mover->move($this->id('Cluj'), $this->id('Drumuri'), 1);
        self::assertSame(['Bistrita', 'Cluj', 'Alba'], $this->children('Drumuri'));
        // ordonarea nu schimba caile
        self::assertSame($this->label('Drumuri').'.'.$this->label('Cluj'), $this->row('Cluj')['path']);
        $this->assertPozitiiDense();
    }

    public function testMutareLaRadacinaSiPozitiePesteCapatDevineLaCapat(): void
    {
        $this->mover->move($this->id('Calitate'), null, 99);

        self::assertNull($this->row('Calitate')['parent']);
        self::assertSame(['Drumuri', 'Sanatate', 'Calitate'], $this->children(null));
        self::assertSame($this->label('Calitate').'.'.$this->label('DN1'), $this->row('DN1')['path']);
        self::assertSame([], $this->children('Cluj'));
        $this->assertPozitiiDense();
    }

    public function testRespingeMutareaInSineSauInDescendent(): void
    {
        foreach (['Cluj', 'Calitate', 'DN1'] as $tinta) {
            try {
                $this->mover->move($this->id('Cluj'), $this->id($tinta), 0);
                self::fail("mutarea Cluj in $tinta trebuia respinsa");
            } catch (TreeMoveException) {
            }
        }
        self::assertSame('Drumuri', $this->row('Cluj')['parent']);
        self::assertSame(['Cluj', 'Bistrita', 'Alba'], $this->children('Drumuri'));
    }

    public function testMutariIncrucisateSuccesiveNuPotCreaCiclu(): void
    {
        // Ce ar face doua cereri concurente, serializate de TreeLock: a doua vede efectul primei.
        $this->mover->move($this->id('Bistrita'), $this->id('Alba'), 0);
        $this->expectException(TreeMoveException::class);
        $this->mover->move($this->id('Alba'), $this->id('Bistrita'), 0);
    }

    public function testParinteInexistentSiNodInexistent(): void
    {
        try {
            $this->mover->move($this->id('Cluj'), new Ulid(), 0);
            self::fail('parinte inexistent trebuia respins');
        } catch (TreeMoveException) {
        }

        $this->expectException(TreeNodeNotFoundException::class);
        $this->mover->move(new Ulid(), null, 0);
    }

    public function testEsecul_NuLasaModificariPartiale(): void
    {
        $inainte = $this->db->fetchAllAssociative('SELECT id, parent_id, path::text, position FROM tree_node ORDER BY id');
        try {
            $this->mover->move($this->id('Drumuri'), $this->id('DN1'), 0);
        } catch (TreeMoveException) {
        }
        self::assertSame($inainte, $this->db->fetchAllAssociative('SELECT id, parent_id, path::text, position FROM tree_node ORDER BY id'));
    }

    public function testPozitiaNegativaEsteRespinsa(): void
    {
        $this->expectException(TreeMoveException::class);
        $this->mover->move($this->id('Cluj'), $this->id('Drumuri'), -1);
    }
}
