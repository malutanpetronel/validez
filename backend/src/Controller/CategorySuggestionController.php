<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TreeNode;
use App\Entity\User;
use App\Repository\TreeNodeRepository;
use App\Tree\TreeLock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

#[IsGranted('ROLE_USER')]
final class CategorySuggestionController extends AbstractController
{
    #[Route('/api/category-suggestions', methods: ['GET', 'POST'])]
    public function collection(Request $request, EntityManagerInterface $em, TreeNodeRepository $nodes, TreeLock $lock): JsonResponse
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $db = $em->getConnection();
        if ($request->isMethod('POST')) {
            $body = $request->toArray();
            $name = $body['name'] ?? null;
            $reason = $body['reason'] ?? null;
            $parentId = $body['parent'] ?? null;
            if (!is_string($name) || mb_strlen(trim($name)) < 3 || mb_strlen(trim($name)) > 120 || !is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 2000 || ($parentId !== null && (!is_string($parentId) || !Ulid::isValid($parentId)))) {
                throw new UnprocessableEntityHttpException('Completează numele (3–120 caractere), motivul (maximum 2000) și un părinte valid.');
            }
            $id = new Ulid();
            $em->wrapInTransaction(function () use ($nodes, $lock, $db, $user, $id, $parentId, $name, $reason): void {
                $lock->acquire();
                $parent = $parentId === null ? null : $nodes->find(Ulid::fromString($parentId));
                if ($parentId !== null && !$parent) throw new UnprocessableEntityHttpException('Categoria părinte nu există.');
                $duplicate = $db->fetchOne("SELECT id FROM tree_node WHERE parent_id IS NOT DISTINCT FROM CAST(:parent AS uuid) AND LOWER(name) = LOWER(:name) LIMIT 1", ['parent' => $parent?->getId()->toRfc4122(), 'name' => trim($name)]);
                if ($duplicate) throw new UnprocessableEntityHttpException('Categoria există deja sub acest părinte.');
                $duplicate = $db->fetchOne("SELECT id FROM category_suggestion WHERE status = 'PENDING' AND parent_id IS NOT DISTINCT FROM CAST(:parent AS uuid) AND LOWER(name) = LOWER(:name) LIMIT 1", ['parent' => $parent?->getId()->toRfc4122(), 'name' => trim($name)]);
                if ($duplicate) throw new UnprocessableEntityHttpException('Există deja o propunere în așteptare pentru această categorie.');
                $db->insert('category_suggestion', ['id' => $id->toRfc4122(), 'author_id' => $user->getId()->toRfc4122(), 'parent_id' => $parent?->getId()->toRfc4122(), 'name' => trim($name), 'reason' => trim($reason), 'status' => 'PENDING', 'created_at' => (new \DateTimeImmutable())->format('c')]);
            });
            return $this->json(['id' => $id->toBase32(), 'status' => 'PENDING'], 201);
        }
        $page = max(1, $request->query->getInt('page', 1));
        $where = $user->isAdmin() ? '' : ' WHERE s.author_id = :author';
        $params = $user->isAdmin() ? [] : ['author' => $user->getId()->toRfc4122()];
        $total = (int) $db->fetchOne('SELECT COUNT(*) FROM category_suggestion s'.$where, $params);
        $rows = $db->fetchAllAssociative('SELECT s.*, u.display_name AS "authorName", n.name AS "parentName" FROM category_suggestion s JOIN app_user u ON u.id = s.author_id LEFT JOIN tree_node n ON n.id = s.parent_id'.$where.' ORDER BY s.created_at DESC, s.id DESC LIMIT 20 OFFSET '.(($page - 1) * 20), $params);
        foreach ($rows as &$row) {
            foreach (['id', 'author_id', 'parent_id', 'node_id'] as $key) if ($row[$key]) $row[$key] = Ulid::fromString($row[$key])->toBase32();
        }
        unset($row);
        $response = $this->json(['items' => $rows, 'total' => $total]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/api/category-suggestions/{id}', methods: ['PATCH'])]
    public function decide(string $id, Request $request, EntityManagerInterface $em, TreeNodeRepository $nodes, TreeLock $lock): JsonResponse
    {
        if (!Ulid::isValid($id)) throw $this->createNotFoundException();
        $status = $request->toArray()['status'] ?? null;
        if (!in_array($status, ['APPROVED', 'REJECTED'], true)) throw new UnprocessableEntityHttpException('Alege APPROVED sau REJECTED.');
        $user = $this->getUser();
        $result = $em->wrapInTransaction(function () use ($em, $nodes, $lock, $id, $status, $user): array {
            $lock->acquire();
            $db = $em->getConnection();
            $row = $db->fetchAssociative('SELECT * FROM category_suggestion WHERE id = :id FOR UPDATE', ['id' => Ulid::fromString($id)->toRfc4122()]);
            if (!$row) throw $this->createNotFoundException();
            if ($row['status'] !== 'PENDING') throw new UnprocessableEntityHttpException('Propunerea a fost deja soluționată.');
            $nodeId = null;
            if ($status === 'APPROVED') {
                $parent = $row['parent_id'] ? $nodes->find(Ulid::fromString($row['parent_id'])) : null;
                // Reuse an existing sibling instead of creating a duplicate.
                $existing = $db->fetchOne('SELECT id FROM tree_node WHERE parent_id IS NOT DISTINCT FROM CAST(:parent AS uuid) AND LOWER(name) = LOWER(:name) LIMIT 1', ['parent' => $row['parent_id'], 'name' => $row['name']]);
                if ($existing) $nodeId = Ulid::fromString($existing);
                else {
                    $node = new TreeNode($row['name'], $user, $parent, $nodes->nextPosition($parent));
                    $em->persist($node);
                    $em->flush();
                    $nodeId = $node->getId();
                }
            }
            $db->update('category_suggestion', ['status' => $status, 'node_id' => $nodeId?->toRfc4122()], ['id' => $row['id']]);
            return ['id' => $id, 'status' => $status, 'nodeId' => $nodeId?->toBase32()];
        });
        return $this->json($result);
    }
}
