<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Utilizator minim (plan Step 1.4): email + parola, roluri.
 * Din domain.puml vin ulterior: provider (Google/Facebook/Apple), publicKey, emailVerified, trustScore.
 * Tabela "app_user": "user" e cuvant rezervat in PostgreSQL.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity(fields: ['email'], message: 'Există deja un utilizator cu acest email.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    /** Stocat normalizat (trim + lowercase); identificatorul de login. */
    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    private string $displayName;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles;

    /** Hash-ul parolei. */
    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    // Existing/CLI-created accounts remain active; public registration explicitly disables them.
    #[ORM\Column(options: ['default' => true])]
    private bool $emailVerified = true;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $confirmationCodeHash = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $codeRequestedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $codeAttempts = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $directPublishingRevoked = false;

    public function isDirectPublishingRevoked(): bool { return $this->directPublishingRevoked; }
    public function setDirectPublishingRevoked(bool $revoked): void { $this->directPublishingRevoked = $revoked; }

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $passwordResetCodeHash = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $passwordResetRequestedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $passwordResetAttempts = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $credentialVersion = 0;

    /** L.5b: linkul de ștergere a contului (hash HMAC al tokenului din email), valabil o oră. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $accountDeletionTokenHash = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $accountDeletionRequestedAt = null;

    /** L.5b: contul șters rămâne ca rând golit („Cont șters”), pentru că subiectele și reviziile îl referă. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public const DELETED_NAME = 'Cont șters';

    /** L.2b: versiunea Termenilor acceptată la înregistrare și momentul acceptării (NULL la conturile mai vechi). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $termsAcceptedVersion = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $termsAcceptedAt = null;

    public function getTermsAcceptedVersion(): ?string { return $this->termsAcceptedVersion; }
    public function getTermsAcceptedAt(): ?\DateTimeImmutable { return $this->termsAcceptedAt; }

    public function acceptTerms(string $version): void
    {
        $this->termsAcceptedVersion = $version;
        $this->termsAcceptedAt = new \DateTimeImmutable();
    }

    public function getCredentialVersion(): int { return $this->credentialVersion; }
    public function getPasswordResetCodeHash(): ?string { return $this->passwordResetCodeHash; }
    public function getPasswordResetRequestedAt(): ?\DateTimeImmutable { return $this->passwordResetRequestedAt; }

    public function issuePasswordResetCode(string $hash): void
    {
        $this->passwordResetCodeHash = $hash;
        $this->passwordResetRequestedAt = new \DateTimeImmutable();
        $this->passwordResetAttempts = 0;
    }

    public function failPasswordReset(): void
    {
        if (++$this->passwordResetAttempts >= 5) $this->passwordResetCodeHash = null;
    }

    public function resetPassword(string $hash): void
    {
        $this->password = $hash;
        ++$this->credentialVersion;
        $this->passwordResetCodeHash = null;
        $this->passwordResetRequestedAt = null;
        $this->passwordResetAttempts = 0;
    }

    public function getAccountDeletionTokenHash(): ?string { return $this->accountDeletionTokenHash; }
    public function getAccountDeletionRequestedAt(): ?\DateTimeImmutable { return $this->accountDeletionRequestedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }

    public function issueAccountDeletionToken(string $hash): void
    {
        $this->accountDeletionTokenHash = $hash;
        $this->accountDeletionRequestedAt = new \DateTimeImmutable();
    }

    /**
     * Golește contul: email de neutilizat (adresa reală se eliberează), nume „Cont șters”, parolă imposibilă, fără
     * roluri; credentialVersion crește, deci tokenurile JWT emise se invalidează.
     */
    public function anonymize(string $unusablePasswordHash, \DateTimeImmutable $at): void
    {
        $this->email = 'sters-'.strtolower($this->id->toBase32()).'@cont-sters.invalid';
        $this->displayName = self::DELETED_NAME;
        $this->roles = [];
        $this->password = $unusablePasswordHash;
        $this->emailVerified = false;
        ++$this->credentialVersion;
        $this->confirmationCodeHash = null;
        $this->codeRequestedAt = null;
        $this->codeAttempts = 0;
        $this->passwordResetCodeHash = null;
        $this->passwordResetRequestedAt = null;
        $this->passwordResetAttempts = 0;
        $this->accountDeletionTokenHash = null;
        $this->accountDeletionRequestedAt = null;
        $this->termsAcceptedVersion = null;
        $this->termsAcceptedAt = null;
        $this->deletedAt = $at;
    }

    /** @param list<string> $roles */
    public function __construct(string $email, string $displayName, array $roles = [])
    {
        $this->id = new Ulid();
        $this->email = self::normalizeEmail($email);
        $this->displayName = trim($displayName);
        $this->roles = array_values(array_unique($roles));
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isEmailVerified(): bool { return $this->emailVerified; }
    public function requireEmailConfirmation(): void { $this->emailVerified = false; }
    public function setDisplayName(string $displayName): void { $this->displayName = trim($displayName); }
    public function getConfirmationCodeHash(): ?string { return $this->confirmationCodeHash; }
    public function getCodeRequestedAt(): ?\DateTimeImmutable { return $this->codeRequestedAt; }
    public function getCodeAttempts(): int { return $this->codeAttempts; }

    public function issueConfirmationCode(string $hash): void
    {
        $this->confirmationCodeHash = $hash;
        $this->codeRequestedAt = new \DateTimeImmutable();
        $this->codeAttempts = 0;
    }

    public function failConfirmation(): void
    {
        ++$this->codeAttempts;
        if ($this->codeAttempts >= 5) $this->confirmationCodeHash = null;
    }

    public function confirmEmail(): void
    {
        $this->emailVerified = true;
        $this->confirmationCodeHash = null;
        $this->codeRequestedAt = null;
        $this->codeAttempts = 0;
    }

    /** Nu tinem date sensibile in clar pe obiect (doar hash-ul parolei). */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
