<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\AccountErasureService;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** L.5b/L.5c: „Șterge-mi contul” — link pe email (o oră), apoi confirmarea din pagina deschisă de link. */
final class AccountDeletionController extends AbstractController
{
    #[Route('/api/account/deletion-request', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function request(AccountErasureService $erasure, RateLimiterFactoryInterface $accountDeletionLimiter, LoggerInterface $logger): JsonResponse
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        if ($user->isAdmin()) {
            throw new AccessDeniedHttpException('Conturile de administrator se șterg doar la cerere scrisă (vezi Confidențialitate).');
        }
        $this->limit($accountDeletionLimiter, 'user:'.$user->getId()->toBase32());
        try {
            $erasure->request($user);
        } catch (TransportExceptionInterface) {
            $logger->error('Account deletion email delivery failed.');
            throw new HttpException(503, 'Emailul nu a putut fi trimis acum. Reîncearcă peste câteva minute.');
        }

        return $this->privateJson(['status' => 'link_sent', 'expiresInMinutes' => intdiv(AccountErasureService::TOKEN_TTL_SECONDS, 60)]);
    }

    #[Route('/api/account/deletion-confirm', methods: ['POST'])]
    public function confirm(Request $request, AccountErasureService $erasure, RateLimiterFactoryInterface $accountDeletionConfirmLimiter): JsonResponse
    {
        $this->limit($accountDeletionConfirmLimiter, 'ip:'.($request->getClientIp() ?? 'unknown'));
        $data = $request->toArray();
        $token = \is_string($data['token'] ?? null) ? trim($data['token']) : '';
        if (!$erasure->confirm($token)) {
            throw new BadRequestHttpException('Linkul nu mai este valabil (a expirat, a fost folosit sau a fost înlocuit de unul nou). Cere un link nou din „Șterge-mi contul”.');
        }

        return $this->privateJson(['status' => 'deleted']);
    }

    private function limit(RateLimiterFactoryInterface $factory, string $key): void
    {
        $limit = $factory->create($key)->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Prea multe încercări. Reîncearcă mai târziu.');
        }
    }

    private function privateJson(array $data): JsonResponse
    {
        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
