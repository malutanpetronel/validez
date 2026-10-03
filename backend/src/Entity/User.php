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

    /** Nu tinem date sensibile in clar pe obiect (doar hash-ul parolei). */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
