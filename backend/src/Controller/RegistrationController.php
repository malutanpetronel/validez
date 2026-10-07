<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\AltchaService;
use App\Auth\RegistrationService;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/api/captcha/challenge', methods: ['GET'])]
    public function challenge(Request $request, AltchaService $altcha, RateLimiterFactoryInterface $captchaChallengeLimiter): JsonResponse
    {
        $this->limit($captchaChallengeLimiter, $request->getClientIp() ?? 'unknown');
        return $this->privateJson($altcha->createChallenge());
    }

    #[Route('/api/register', methods: ['POST'])]
    public function register(Request $request, RegistrationService $registration, AltchaService $altcha, RateLimiterFactoryInterface $registrationLimiter): JsonResponse
    {
        $this->limit($registrationLimiter, $request->getClientIp() ?? 'unknown');
        $data = $request->toArray();
        $this->verify($altcha, $data);
        try { $registration->register($data); }
        catch (TransportExceptionInterface) { throw new ServiceUnavailableHttpException(60, 'Emailul nu a putut fi trimis. Reîncearcă mai târziu.'); }
        return $this->privateJson(['status' => 'code_sent', 'retryAfter' => 60]);
    }

    #[Route('/api/register/resend', methods: ['POST'])]
    public function resend(Request $request, RegistrationService $registration, AltchaService $altcha, RateLimiterFactoryInterface $registrationLimiter): JsonResponse
    {
        $this->limit($registrationLimiter, $request->getClientIp() ?? 'unknown');
        $data = $request->toArray();
        $this->verify($altcha, $data);
        try { $registration->resend(RegistrationService::email($data)); }
        catch (TransportExceptionInterface) { throw new ServiceUnavailableHttpException(60, 'Emailul nu a putut fi trimis. Reîncearcă mai târziu.'); }
        return $this->privateJson(['status' => 'code_sent', 'retryAfter' => 60]);
    }

    #[Route('/api/register/confirm', methods: ['POST'])]
    public function confirm(Request $request, RegistrationService $registration, RateLimiterFactoryInterface $registrationConfirmLimiter,
        JWTTokenManagerInterface $jwt, RefreshTokenGeneratorInterface $refreshGenerator, RefreshTokenManagerInterface $refreshManager): JsonResponse
    {
        $data = $request->toArray();
        $email = RegistrationService::email($data);
        // Independent budgets: rotating email addresses cannot bypass the per-IP limit.
        $this->limit($registrationConfirmLimiter, 'ip:'.($request->getClientIp() ?? 'unknown'));
        $this->limit($registrationConfirmLimiter, 'email:'.$email);
        $code = is_string($data['code'] ?? null) ? trim($data['code']) : '';
        $user = $registration->confirm($email, $code);
        if (!$user) throw new BadRequestHttpException('Cod invalid sau expirat. Poți solicita un cod nou.');
        $refresh = $refreshGenerator->createForUserWithTtl($user, 604800);
        $refreshManager->save($refresh);
        return $this->privateJson(['token' => $jwt->create($user), 'refresh_token' => $refresh->getRefreshToken()]);
    }

    private function verify(AltchaService $altcha, array $data): void
    {
        if (!$altcha->verify($data['altcha'] ?? null)) throw new BadRequestHttpException('Verificarea ALTCHA a eșuat sau a expirat. Verifică din nou și reîncearcă.');
    }

    private function limit(RateLimiterFactoryInterface $limiter, string $key): void
    {
        $limit = $limiter->create($key)->consume();
        if (!$limit->isAccepted()) throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Prea multe încercări. Reîncearcă mai târziu.');
    }

    private function privateJson(array $data): JsonResponse
    {
        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
