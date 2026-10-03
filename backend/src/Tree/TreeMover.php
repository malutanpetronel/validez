<?php

declare(strict_types=1);

namespace App\Tree;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;

/**
 * Mutare si ordonare (plan Step 1.3, ADR-0001), intr-o singura tranzactie, sub TreeLock:
 *
 * 1. starea se citeste DUPA lock -> mutarile concurente sunt serializate; a doua vede efectul primei,
 *    deci doua mutari incrucisate (A in B, B in A) nu pot crea un ciclu;
 * 2. noul parinte nu poate fi nodul insusi sau un descendent (path-ul lui incepe cu path-ul nodului);
 * 3. parent_id + path-ul intregului subarbore se rescriu (ltree);
 * 4. fratii de la sursa si de la destinatie se renumeroteaza dens 0..n
 *    (unicitatea (parent_id, position) e DEFERRED, deci duplicatele intermediare sunt permise).
 *
 * position = indexul printre fratii de la destinatie, fara nodul mutat; null = la capat;
 * o valoare peste numarul fratilor inseamna tot "la capat".
 */
final class TreeMover
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TreeLock $lock,
    ) {
    }

    public function move(Ulid $nodeId, ?Ulid $newParentId, ?int $position = null): void
    {
        if ($position !== null && $position < 0) {
            throw new TreeMoveException('Poziția nu poate fi negativă.');
        }

        $this->connection->transactional(function () use ($nodeId, $newParentId, $position): void {
            $this->lock->acquire();

            $node = $this->fetch($nodeId) ?? throw new TreeNodeNotFoundException('Nodul nu există.');

            $newParent = null;
            if ($newParentId !== null) {
                $newParent = $this->fetch($newParentId) ?? throw new TreeMoveException('Nodul părinte nu există.');
                if ($newParent['path'] === $node['path'] || str_starts_with($newParent['path'], $node['path'].'.')) {
                    throw new TreeMoveException('Un nod nu poate fi mutat în el însuși sau într-un descendent al lui.');
                }
            }

            $oldParentDbId = $node['parent_id'];
            $newParentDbId = $newParent['id'] ?? null;

            // Fratii de la destinatie, in ordine, fara nodul mutat; nodul se insereaza la pozitia ceruta.
            $siblings = $this->childIds($newParentDbId, $node['id']);
            $index = $position === null ? \count($siblings) : min($position, \count($siblings));
            array_splice($siblings, $index, 0, [$node['id']]);

            if ($oldParentDbId !== $newParentDbId) {
                $newPath = ($newParent === null ? '' : $newParent['path'].'.').$nodeId->toBase32();

                // Descendentii: prefixul vechi inlocuit cu cel nou (subpath(path, nlevel(path)) ar da eroare
                // pentru nodul insusi, de aceea el primeste path-ul separat).
                $this->connection->executeStatement(
                    'UPDATE tree_node SET path = CAST(:new AS ltree) || subpath(path, nlevel(CAST(:old AS ltree)))
                     WHERE path <@ CAST(:old AS ltree) AND path <> CAST(:old AS ltree)',
                    ['new' => $newPath, 'old' => $node['path']],
                );
                $this->connection->executeStatement(
                    'UPDATE tree_node SET parent_id = :parent, path = CAST(:path AS ltree), updated_at = now() WHERE id = :id',
                    ['parent' => $newParentDbId, 'path' => $newPath, 'id' => $node['id']],
                );

                $this->renumber($this->childIds($oldParentDbId));
            } else {
                $this->connection->executeStatement('UPDATE tree_node SET updated_at = now() WHERE id = :id', ['id' => $node['id']]);
            }

            $this->renumber($siblings);
        });
    }

    /** @return array{id: string, parent_id: ?string, path: string}|null */
    private function fetch(Ulid $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id::text AS id, parent_id::text AS parent_id, path::text AS path FROM tree_node WHERE id = :id',
            ['id' => $id->toRfc4122()],
        );

        return $row === false ? null : $row;
    }

    /** @return list<string> id-urile copiilor directi, in ordinea curenta */
    private function childIds(?string $parentId, ?string $excludeId = null): array
    {
        $sql = 'SELECT id::text FROM tree_node WHERE '.($parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent');
        $params = $parentId === null ? [] : ['parent' => $parentId];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }

        return $this->connection->fetchFirstColumn($sql.' ORDER BY position, id', $params);
    }

    /** @param list<string> $ids */
    private function renumber(array $ids): void
    {
        foreach ($ids as $i => $id) {
            $this->connection->executeStatement(
                'UPDATE tree_node SET position = :pos WHERE id = :id AND position <> :pos',
                ['pos' => $i, 'id' => $id],
            );
        }
    }
}
