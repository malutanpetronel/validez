<?php

declare(strict_types=1);

namespace App\Auth;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/** Run before the user/password lookup on the JSON login authenticator. */
#[AsEventListener(event: CheckPassportEvent::class, priority: 3072)]
final class AltchaLoginListener
{
    public function __construct(private readonly AltchaService $altcha, private readonly RequestStack $requests) {}

    public function __invoke(CheckPassportEvent $event): void
    {
        $request = $this->requests->getCurrentRequest();
        if (!$request || !$request->isMethod('POST') || $request->getPathInfo() !== '/api/auth') return;
        $data = $request->toArray();
        if (!$this->altcha->verify($data['altcha'] ?? null)) {
            throw new CustomUserMessageAuthenticationException('Verificarea ALTCHA a eșuat sau a expirat. Verifică din nou și reîncearcă.');
        }
    }
}
