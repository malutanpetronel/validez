<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MailerInterface $mailer,
        private readonly string $secret,
        private readonly string $sender,
    ) {}

    public function request(string $email): void
    {
        $this->em->wrapInTransaction(function () use ($email): void {
            $user = $this->lockedUser($email);
            if (!$user || !$user->isEmailVerified()) return;
            do {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            } while ($user->getPasswordResetCodeHash() !== null && hash_equals($user->getPasswordResetCodeHash(), $this->codeHash($user, $code)));
            $user->issuePasswordResetCode($this->codeHash($user, $code));
            $this->em->flush();
            $this->mailer->send((new Email())->from($this->sender)->to($user->getEmail())
                ->subject('Validez — resetează parola')
                ->text("Codul tău pentru resetarea parolei Validez este: $code\n\nCodul este valabil 15 minute. Introdu-l în pagina «Am uitat parola», împreună cu noua parolă.\nDacă nu ai solicitat resetarea, ignoră acest mesaj. Parola ta nu a fost modificată."));
        });
    }

    public function reset(string $email, string $code, string $password): bool
    {
        if (mb_strlen($password) < 10 || strlen($password) > 72) {
            throw new UnprocessableEntityHttpException('Parola trebuie să aibă minimum 10 caractere și maximum 72 de octeți.');
        }
        return $this->em->wrapInTransaction(function () use ($email, $code, $password): bool {
            $user = $this->lockedUser($email);
            if (!$user || !$user->isEmailVerified() || !$user->getPasswordResetCodeHash()
                || !$user->getPasswordResetRequestedAt() || $user->getPasswordResetRequestedAt()->getTimestamp() + 900 <= time()) return false;
            if (!preg_match('/^\d{6}$/D', $code) || !hash_equals($user->getPasswordResetCodeHash(), $this->codeHash($user, $code))) {
                $user->failPasswordReset();
                return false;
            }
            $user->resetPassword($this->hasher->hashPassword($user, $password));
            $this->em->getConnection()->executeStatement('DELETE FROM refresh_token WHERE username = ?', [$user->getEmail()]);
            return true;
        });
    }

    private function lockedUser(string $email): ?User
    {
        // Share the registration lock: reset and registration codes stay independent but account writes serialize.
        $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['registration:'.$email]);
        $user = $this->users->loadUserByIdentifier($email);
        if ($user) $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
        return $user;
    }

    private function codeHash(User $user, string $code): string
    {
        return hash_hmac('sha256', 'password-reset|'.$user->getId()->toBase32().'|'.$code, $this->secret);
    }
}
