<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\User;
use App\Vote\ErasureRegistry;
use App\Vote\VoteService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L.5b: ștergerea contului la cererea titularului (PrivacyPage, „Ștergerea contului”).
 *
 * Pasul 1 (`request`): un link cu token aleator, valabil o oră, trimis pe emailul contului; în DB stă doar hash-ul.
 * Pasul 2 (`confirm`): din pagina deschisă de link — retrage acordul de vot (șterge voturile și istoricul privat,
 * ADR-0007), șterge notele personale, propunerile de categorii neanalizate și sesiunile, apoi golește contul
 * („Cont șters”): rândul rămâne, pentru că subiectele și reviziile îl referă. Ștergerea se notează în registrul de
 * ștergeri (ADR-0009), ca o restaurare să nu readucă datele. Conturile de administrator nu se șterg pe această cale.
 */
final class AccountErasureService
{
    public const TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VoteService $votes,
        private readonly ErasureRegistry $erasures,
        private readonly MailerInterface $mailer,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(APP_SECRET)%')] private readonly string $secret,
        #[Autowire('%env(default:registration_sender_default:MAILER_FROM_ADDRESS)%')] private readonly string $sender,
        #[Autowire('%env(default:site_url_default:SITE_URL)%')] private readonly string $siteUrl,
    ) {
    }

    /** @throws TransportExceptionInterface */
    public function request(User $user): void
    {
        if ($user->isAdmin() || $user->isDeleted()) {
            throw new \DomainException('Conturile de administrator se șterg doar la cerere scrisă (vezi Confidențialitate).');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->em->wrapInTransaction(function () use ($user, $token): void {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            $user->issueAccountDeletionToken($this->tokenHash($token));
            $this->em->flush();
        });
        $link = rtrim($this->siteUrl, '/').'/sterge-cont/confirmare?token='.$token;
        $this->mailer->send((new Email())->from($this->sender)->to($user->getEmail())
            ->subject('Validez — confirmă ștergerea contului')
            ->text("Ai cerut ștergerea contului tău Validez.\n\nDeschide linkul de mai jos și apasă „Șterge definitiv contul”:\n$link\n\n"
                ."Linkul este valabil o oră și poate fi folosit o singură dată.\n"
                ."Se vor șterge numele afișat, emailul, parola, voturile și istoricul privat de vot, notele personale și propunerile de categorii neanalizate. "
                ."Subiectele, fotografiile și linkurile publicate rămân, cu autorul „Cont șters”.\n\n"
                ."Dacă nu ai cerut tu ștergerea, ignoră acest mesaj: contul rămâne neschimbat. Îți recomandăm să-ți schimbi parola."));
    }

    /** @return bool false dacă tokenul e necunoscut, expirat sau deja folosit */
    public function confirm(string $token): bool
    {
        if ($token === '' || \strlen($token) > 100) {
            return false;
        }
        $user = $this->em->getRepository(User::class)->findOneBy(['accountDeletionTokenHash' => $this->tokenHash($token)]);
        $requestedAt = $user?->getAccountDeletionRequestedAt();
        if (!$user || $user->isAdmin() || $user->isDeleted() || !$requestedAt || $requestedAt->getTimestamp() + self::TOKEN_TTL_SECONDS <= time()) {
            return false;
        }
        $oldEmail = $user->getEmail();
        $erasedAt = new \DateTimeImmutable();

        // Voturile întâi, prin aceeași cale ca retragerea acordului (are propria tranzacție și notarea în registru).
        $this->votes->withdrawConsent($user);
        $this->em->wrapInTransaction(function () use ($user, $oldEmail, $erasedAt): void {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            $this->erasePrivateData($user, $oldEmail);
            $user->anonymize($this->unusablePassword($user), $erasedAt);
            $this->em->flush();
        });
        try {
            $this->erasures->record($user->getId(), ErasureRegistry::ACCOUNT_ERASED, $erasedAt);
        } catch (\Throwable $e) {
            $this->logger->critical('Ștergerea contului nu a putut fi notată în registrul de ștergeri (ADR-0009).', ['exception' => $e]);
        }
        try {
            $this->mailer->send((new Email())->from($this->sender)->to($oldEmail)
                ->subject('Validez — contul tău a fost șters')
                ->text("Contul tău Validez a fost șters.\n\nAdresa ta de email nu mai este păstrată în aplicație; o poți folosi pentru un cont nou. "
                    ."Subiectele publicate rămân cu autorul „Cont șters”. Copiile de siguranță care mai conțin datele expiră în cel mult 30 de zile.\n\n"
                    ."Dacă nu ai cerut tu ștergerea, scrie-ne la contact@webnou.ro."));
        } catch (TransportExceptionInterface) {
            $this->logger->error('Account deletion confirmation email delivery failed.');
        }

        return true;
    }

    /**
     * După o restaurare (app:votes:reapply-erasures): golește din nou un cont care apare nesters în copie.
     * Voturile se reaplică separat, din intrarea `consent_withdrawn` notată de withdrawConsent.
     */
    public function reapply(User $user, \DateTimeImmutable $erasedAt, bool $dryRun = false): bool
    {
        if ($user->isDeleted()) {
            return false;
        }
        if (!$dryRun) {
            $this->em->wrapInTransaction(function () use ($user, $erasedAt): void {
                $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
                $this->erasePrivateData($user, $user->getEmail());
                $user->anonymize($this->unusablePassword($user), $erasedAt);
                $this->em->flush();
            });
        }

        return true;
    }

    private function erasePrivateData(User $user, string $email): void
    {
        $db = $this->em->getConnection();
        $id = $user->getId()->toRfc4122();
        $db->executeStatement('DELETE FROM subject_private_note WHERE author_id = ?', [$id]);
        $db->executeStatement("DELETE FROM category_suggestion WHERE author_id = ? AND status = 'PENDING'", [$id]);
        $db->executeStatement('DELETE FROM refresh_token WHERE username = ?', [$email]);
    }

    private function unusablePassword(User $user): string
    {
        return $this->hasher->hashPassword($user, bin2hex(random_bytes(32)));
    }

    private function tokenHash(string $token): string
    {
        return hash_hmac('sha256', 'account-deletion|'.$token, $this->secret);
    }
}
