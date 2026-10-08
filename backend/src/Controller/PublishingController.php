<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Subject\PublishingPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

#[IsGranted('ROLE_USER')]
final class PublishingController extends AbstractController
{
    #[Route('/api/me/publishing', methods: ['GET'])]
    public function own(PublishingPolicy $policy): JsonResponse
    {
        return $this->status($this->getUser(), $policy);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/api/users/{id}/publishing', methods: ['GET', 'PUT'])]
    public function user(string $id, Request $request, EntityManagerInterface $em, PublishingPolicy $policy): JsonResponse
    {
        $user = Ulid::isValid($id) ? $em->find(User::class, Ulid::fromString($id)) : null;
        if (!$user) throw $this->createNotFoundException('Utilizatorul nu există.');
        if ($request->isMethod('PUT')) {
            $body = $request->toArray();
            if (!isset($body['revoked']) || !is_bool($body['revoked'])) throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('revoked trebuie să fie boolean.');
            $em->wrapInTransaction(function () use ($user, $body, $policy): void {
                $policy->lock($user);
                $user->setDirectPublishingRevoked($body['revoked']);
            });
        }
        return $this->status($user, $policy);
    }

    private function status(User $user, PublishingPolicy $policy): JsonResponse
    {
        $response = $this->json(['isAdmin' => $user->isAdmin(), 'approvedCount' => $policy->approvedCount($user), 'canPublishDirectly' => $policy->canPublish($user), 'revoked' => $user->isDirectPublishingRevoked()]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
