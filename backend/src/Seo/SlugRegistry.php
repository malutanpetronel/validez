<?php

declare(strict_types=1);

namespace App\Seo;

use App\Entity\CivicSubject;
use App\Entity\TreeNode;
use App\Tree\TreeLock;
use Doctrine\DBAL\Connection;

/**
 * Alocarea, schimbarea și rezolvarea slug-urilor (ADR-0008).
 *
 * - Categorii: `slug` unic între frații aceluiași părinte, `slug_path` materializat (`drumuri/cluj/calitate`),
 *   unic în bază. Alocarea se face sub TreeLock (aceeași serializare ca structura arborelui).
 * - Subiecte: `slug` unic global, alocat sub un advisory lock propriu.
 * - `slug_redirect`: valorile vechi (cale de categorie / slug de subiect) → id-ul entității; rezolvarea
 *   caută întâi entitatea vie, apoi istoricul, deci un rând de istoric rămas după reutilizarea valorii nu
 *   „fură” niciodată URL-ul unei entități vii. Redirectul indică id-ul, nu un URL, deci nu apar lanțuri.
 *
 * Lock-urile se iau doar în tranzacție (în afara ei — fixturi, teste — nu există concurență).
 * Slug-urile alocate în memorie înaintea unui flush (mai multe persist în același lot) sunt ținute în
 * $pending, ca două entități noi să nu primească același slug; se golesc după flush/clear.
 */
final class SlugRegistry
{
    public const NODE = 'NODE';
    public const SUBJECT = 'SUBJECT';

    /** Cheie fixă pentru alocarea slug-urilor de subiect (TreeLock folosește 7_302_001). */
    private const SUBJECT_LOCK_KEY = 7_302_002;

    /** @var array<string, array<string, true>> cheia părintelui ('' = rădăcini) => slug-uri rezervate */
    private array $pendingNodes = [];

    /** @var array<string, true> */
    private array $pendingSubjects = [];

    public function __construct(
        private readonly Connection $db,
        private readonly TreeLock $treeLock,
    ) {
    }

    public function clearPending(): void
    {
        $this->pendingNodes = [];
        $this->pendingSubjects = [];
    }

    public function lockNodes(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->treeLock->acquire();
        }
    }

    public function lockSubjects(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(:key)', ['key' => self::SUBJECT_LOCK_KEY]);
        }
    }

    // ---- categorii ----

    /** Slug pentru un nod nou (din listener, la persist). Părintele primește slug primul, dacă nu are. */
    public function assignNew(TreeNode $node): void
    {
        if ($node->hasSlug()) {
            return;
        }
        $parent = $node->getParent();
        if ($parent !== null) {
            $this->assignNew($parent);
        }
        $this->lockNodes();
        $slug = $this->freeNodeSlug($node->getName(), $parent?->getId()->toRfc4122(), $node->getId()->toRfc4122());
        $node->assignSlug($slug, self::join($parent?->getSlugPath(), $slug));
    }

    /**
     * După redenumire (sub TreeLock, înainte de flush): slug nou dacă numele dă altul; calea veche a
     * nodului și a întregului subarbore intră în istoric.
     */
    public function afterRename(TreeNode $node): void
    {
        $this->lockNodes();
        $parent = $node->getParent();
        $slug = $this->freeNodeSlug($node->getName(), $parent?->getId()->toRfc4122(), $node->getId()->toRfc4122());
        $path = self::join($parent?->getSlugPath(), $slug);
        if ($path === $node->getSlugPath()) {
            return;
        }
        $this->rewriteSubtree($node->getPath(), $node->getId()->toRfc4122(), $node->getSlugPath(), $slug, $path);
        $node->assignSlug($slug, $path);
    }

    /**
     * După mutarea unui nod la alt părinte (TreeMover, sub TreeLock, cu `path` deja rescris): slug liber
     * printre frații de la destinație și `slug_path` rescris pe tot subarborele, cu istoric.
     */
    public function afterMove(string $nodeDbId): void
    {
        $row = $this->db->fetchAssociative(
            'SELECT n.name, n.slug_path, n.path::text AS path, p.id::text AS parent_id, p.slug_path AS parent_slug_path
             FROM tree_node n LEFT JOIN tree_node p ON p.id = n.parent_id WHERE n.id = :id',
            ['id' => $nodeDbId],
        ) ?: throw new \LogicException('Nodul mutat nu există.');

        $slug = $this->freeNodeSlug($row['name'], $row['parent_id'], $nodeDbId);
        $path = self::join($row['parent_slug_path'], $slug);
        $this->rewriteSubtree($row['path'], $nodeDbId, $row['slug_path'], $slug, $path);
    }

    /** Id-ul nodului (uuid) pentru o cale, curentă sau din istoric. */
    public function nodeIdForPath(string $slugPath): ?string
    {
        $id = $this->db->fetchOne('SELECT id::text FROM tree_node WHERE slug_path = :p', ['p' => $slugPath])
            ?: $this->db->fetchOne(
                'SELECT target_id::text FROM slug_redirect WHERE entity_type = :t AND old_value = :p',
                ['t' => self::NODE, 'p' => $slugPath],
            );

        return $id === false ? null : (string) $id;
    }

    private function freeNodeSlug(string $name, ?string $parentDbId, string $selfDbId): string
    {
        $base = SlugGenerator::base($name, SlugGenerator::NODE_FALLBACK);
        $taken = $this->db->fetchFirstColumn(
            'SELECT slug FROM tree_node
             WHERE parent_id IS NOT DISTINCT FROM CAST(:parent AS uuid) AND id <> :self
               AND (slug = :base OR slug LIKE :family)',
            ['parent' => $parentDbId, 'self' => $selfDbId, 'base' => $base, 'family' => $base.'-%'],
        );
        $key = $parentDbId ?? '';
        $slug = SlugGenerator::firstFree($base, [...$taken, ...array_keys($this->pendingNodes[$key] ?? [])], SlugGenerator::RESERVED_NODE_SLUGS);
        $this->pendingNodes[$key][$slug] = true;

        return $slug;
    }

    /**
     * Nodul + descendenții: căile vechi în istoric, apoi prefixul vechi înlocuit cu cel nou.
     * Toate căile din subarbore încep cu calea veche a nodului, deci înlocuirea prefixului e exactă.
     */
    private function rewriteSubtree(string $ltreePath, string $nodeDbId, string $oldPath, string $newSlug, string $newPath): void
    {
        if ($oldPath !== $newPath) {
            $this->db->executeStatement(
                'INSERT INTO slug_redirect (entity_type, old_value, target_id, created_at)
                 SELECT CAST(:t AS varchar), slug_path, id, now() FROM tree_node WHERE path <@ CAST(:path AS ltree)
                 ON CONFLICT (entity_type, old_value) DO UPDATE SET target_id = EXCLUDED.target_id, created_at = EXCLUDED.created_at',
                ['t' => self::NODE, 'path' => $ltreePath],
            );
            $this->db->executeStatement(
                'UPDATE tree_node SET slug_path = CAST(:new AS text) || substr(slug_path, CAST(:from AS int)) WHERE path <@ CAST(:path AS ltree)',
                ['new' => $newPath, 'from' => \strlen($oldPath) + 1, 'path' => $ltreePath],
            );
        }
        $this->db->executeStatement('UPDATE tree_node SET slug = :slug WHERE id = :id', ['slug' => $newSlug, 'id' => $nodeDbId]);
    }

    private static function join(?string $parentPath, string $slug): string
    {
        return $parentPath === null ? $slug : $parentPath.'/'.$slug;
    }

    // ---- subiecte ----

    public function assignNewSubject(CivicSubject $subject): void
    {
        if ($subject->hasSlug()) {
            return;
        }
        $this->lockSubjects();
        $subject->assignSlug($this->freeSubjectSlug($subject->getTitle(), $subject->getId()->toRfc4122()));
    }

    /**
     * După schimbarea titlului (în tranzacția editării, înainte de flush). Slug-ul vechi intră în istoric
     * doar dacă subiectul era public — unul PENDING/HIDDEN n-a avut niciodată URL public.
     */
    public function afterTitleChange(CivicSubject $subject, bool $wasPublished): void
    {
        $this->lockSubjects();
        $old = $subject->getSlug();
        $slug = $this->freeSubjectSlug($subject->getTitle(), $subject->getId()->toRfc4122());
        if ($slug === $old) {
            return;
        }
        if ($wasPublished) {
            $this->db->executeStatement(
                'INSERT INTO slug_redirect (entity_type, old_value, target_id, created_at) VALUES (:t, :old, :id, now())
                 ON CONFLICT (entity_type, old_value) DO UPDATE SET target_id = EXCLUDED.target_id, created_at = EXCLUDED.created_at',
                ['t' => self::SUBJECT, 'old' => $old, 'id' => $subject->getId()->toRfc4122()],
            );
        }
        $subject->assignSlug($slug);
    }

    /** Id-ul subiectului (uuid) pentru un slug, curent sau din istoric. */
    public function subjectIdForSlug(string $slug): ?string
    {
        $id = $this->db->fetchOne('SELECT id::text FROM civic_subject WHERE slug = :s', ['s' => $slug])
            ?: $this->db->fetchOne(
                'SELECT target_id::text FROM slug_redirect WHERE entity_type = :t AND old_value = :s',
                ['t' => self::SUBJECT, 's' => $slug],
            );

        return $id === false ? null : (string) $id;
    }

    private function freeSubjectSlug(string $title, string $selfDbId): string
    {
        $base = SlugGenerator::base($title, SlugGenerator::SUBJECT_FALLBACK);
        $taken = $this->db->fetchFirstColumn(
            'SELECT slug FROM civic_subject WHERE id <> :self AND (slug = :base OR slug LIKE :family)',
            ['self' => $selfDbId, 'base' => $base, 'family' => $base.'-%'],
        );
        $slug = SlugGenerator::firstFree($base, [...$taken, ...array_keys($this->pendingSubjects)]);
        $this->pendingSubjects[$slug] = true;

        return $slug;
    }
}
