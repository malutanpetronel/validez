<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\AltchaService;
use App\Auth\PasswordResetService;
use App\Auth\RegistrationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class PasswordResetController extends AbstractController
{
    #[Route('/api/password-reset/request', methods: ['POST'])]
    public function requestCode(Request $request, PasswordResetService $reset, AltchaService $altcha, LoggerInterface $logger,
        RateLimiterFactoryInterface $passwordResetLimiter, RateLimiterFactoryInterface $passwordResetEmailLimiter): JsonResponse
    {
        $this->limit($passwordResetLimiter, $request->getClientIp() ?? 'unknown');
        $data = $request->toArray();
        if (!$altcha->verify($data['altcha'] ?? null)) throw new BadRequestHttpException('Verificarea ALTCHA a eșuat sau a expirat. Verifică din nou și reîncearcă.');
        $email = RegistrationService::email($data);
        $this->limit($passwordResetEmailLimiter, hash('sha256', $email));
        try { $reset->request($email); }
        catch (TransportExceptionInterface) {
            // Do not reveal account existence through a different response when the mail server fails.
            $logger->error('Password reset email delivery failed.');
        }
        return $this->privateJson(['status' => 'code_sent', 'retryAfter' => 60]);
    }

    #[Route('/api/password-reset/confirm', methods: ['POST'])]
    public function confirm(Request $request, PasswordResetService $reset, RateLimiterFactoryInterface $passwordResetConfirmLimiter): JsonResponse
    {
        $this->limit($passwordResetConfirmLimiter, 'ip:'.($request->getClientIp() ?? 'unknown'));
        $data = $request->toArray();
        $email = RegistrationService::email($data);
        $this->limit($passwordResetConfirmLimiter, 'email:'.hash('sha256', $email));
        $code = is_string($data['code'] ?? null) ? trim($data['code']) : '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        if (!$reset->reset($email, $code, $password)) throw new BadRequestHttpException('Cod invalid sau expirat. Poți solicita un cod nou.');
        return $this->privateJson(['status' => 'password_reset']);
    }

    private function limit(RateLimiterFactoryInterface $factory, string $key): void
    {
        $limit = $factory->create($key)->consume();
        if (!$limit->isAccepted()) throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Prea multe încercări. Reîncearcă mai târziu.');
    }

    private function privateJson(array $data): JsonResponse
    {
        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
