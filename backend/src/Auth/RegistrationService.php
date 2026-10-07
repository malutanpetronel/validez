<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MailerInterface $mailer,
        private readonly string $secret,
        private readonly string $sender,
    ) {}

    public static function email(array $data): string
    {
        $email = is_string($data['email'] ?? null) ? User::normalizeEmail($data['email']) : '';
        if (mb_strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new UnprocessableEntityHttpException('Introdu o adresă de email validă.');
        }
        return $email;
    }

    public function register(array $data): void
    {
        $email = self::email($data);
        $name = is_string($data['displayName'] ?? null) ? trim($data['displayName']) : '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) throw new UnprocessableEntityHttpException('Numele trebuie să aibă între 3 și 120 de caractere.');
        if (mb_strlen($password) < 10 || strlen($password) > 72) throw new UnprocessableEntityHttpException('Parola trebuie să aibă minimum 10 caractere și maximum 72 de octeți.');
        $this->em->wrapInTransaction(function () use ($email, $name, $password): void {
            $this->lock($email);
            $user = $this->users->loadUserByIdentifier($email);
            // Never overwrite an active account, grant roles or disclose whether it exists.
            if ($user?->isEmailVerified()) return;
            if ($user) $this->checkCooldown($user);
            else {
                $user = new User($email, $name);
                $user->requireEmailConfirmation();
                $this->em->persist($user);
            }
            $user->setDisplayName($name);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $this->issueCode($user);
        });
    }

    public function resend(string $email): void
    {
        $this->em->wrapInTransaction(function () use ($email): void {
            $this->lock($email);
            $user = $this->users->loadUserByIdentifier($email);
            if (!$user || $user->isEmailVerified()) return;
            $this->checkCooldown($user);
            $this->issueCode($user);
        });
    }

    public function confirm(string $email, string $code): ?User
    {
        return $this->em->wrapInTransaction(function () use ($email, $code): ?User {
            $this->lock($email);
            $user = $this->users->loadUserByIdentifier($email);
            if (!$user || $user->isEmailVerified() || !$user->getConfirmationCodeHash()
                || !$user->getCodeRequestedAt() || $user->getCodeRequestedAt()->getTimestamp() + 900 <= time()) return null;
            if (!preg_match('/^\d{6}$/D', $code) || !hash_equals($user->getConfirmationCodeHash(), $this->codeHash($user, $code))) {
                $user->failConfirmation();
                return null;
            }
            $user->confirmEmail();
            return $user;
        });
    }

    private function lock(string $email): void
    {
        // Serializes issuance/confirmation and attempt counters, including concurrent requests.
        $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['registration:'.$email]);
    }

    private function checkCooldown(User $user): void
    {
        if ($user->getCodeRequestedAt() && $user->getCodeRequestedAt()->getTimestamp() + 60 > time()) {
            throw new TooManyRequestsHttpException(60, 'Un cod a fost trimis recent. Reîncearcă într-un minut.');
        }
    }

    private function codeHash(User $user, string $code): string
    {
        return hash_hmac('sha256', $user->getId()->toBase32().'|'.$code, $this->secret);
    }

    private function issueCode(User $user): void
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while ($user->getConfirmationCodeHash() !== null && hash_equals($user->getConfirmationCodeHash(), $this->codeHash($user, $code)));
        $user->issueConfirmationCode($this->codeHash($user, $code));
        $this->em->flush();
        $this->mailer->send((new Email())->from($this->sender)->to($user->getEmail())
            ->subject('Validez — confirmă adresa de email')
            ->text("Codul tău de confirmare Validez este: $code\n\nCodul este valabil 15 minute. Introdu-l în pagina de confirmare a contului.\nDacă nu ai solicitat acest cont, poți ignora mesajul."));
    }
}
