<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'identity_users')]
#[ORM\UniqueConstraint(name: 'uniq_identity_users_email_canonical', columns: ['email_canonical'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const CURRENT_CGU_VERSION = '2026-05-02';

    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 180)]
        private string $email,
        #[ORM\Column(name: 'email_canonical', type: 'string', length: 180)]
        private string $emailCanonical,
        #[ORM\Column(name: 'display_name', type: 'string', length: 80, nullable: true)]
        private ?string $displayName,
        #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
        private string $passwordHash,
        #[ORM\Column(type: 'json')]
        private array $roles,
        #[ORM\Column(name: 'cgu_accepted_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $cguAcceptedAt,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $deletedAt = null,
        #[ORM\Column(name: 'cgu_accepted_version', type: 'string', length: 20)]
        private string $cguAcceptedVersion = self::CURRENT_CGU_VERSION,
    ) {
    }

    public static function registerLambda(
        string $email,
        string $emailCanonical,
        string $passwordHash,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            bin2hex(random_bytes(16)),
            $email,
            $emailCanonical,
            null,
            $passwordHash,
            ['ROLE_USER'],
            $now,
            $now,
            $now,
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getEmailCanonical(): string
    {
        return $this->emailCanonical;
    }

    public function getEmailHash(string $secret): string
    {
        return hash_hmac('sha256', $this->emailCanonical, $secret);
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function updateProfile(?string $displayName, \DateTimeImmutable $now): void
    {
        $normalizedDisplayName = null === $displayName ? null : trim($displayName);
        $this->displayName = '' === $normalizedDisplayName ? null : $normalizedDisplayName;
        $this->updatedAt = $now;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function promoteToMember(\DateTimeImmutable $now): void
    {
        if ($this->isDeleted() || in_array('ROLE_ADMIN', $this->getRoles(), true)) {
            throw new \DomainException('This user role cannot be changed here.');
        }

        $this->roles = array_values(array_unique([...$this->roles, 'ROLE_USER', 'ROLE_MEMBER']));
        $this->updatedAt = $now;
    }

    public function demoteToLambda(\DateTimeImmutable $now): void
    {
        if ($this->isDeleted() || in_array('ROLE_ADMIN', $this->getRoles(), true)) {
            throw new \DomainException('This user role cannot be changed here.');
        }

        $this->roles = ['ROLE_USER'];
        $this->updatedAt = $now;
    }

    public function anonymizeForDeletion(\DateTimeImmutable $now): void
    {
        $anonymousEmail = sprintf('deleted-%s@deleted.local', $this->id);
        $this->email = $anonymousEmail;
        $this->emailCanonical = $anonymousEmail;
        $this->displayName = null;
        $this->passwordHash = 'deleted'; // intentionally invalid - no hasher will match this
        $this->roles = ['ROLE_USER'];
        $this->deletedAt = $now;
        $this->updatedAt = $now;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->emailCanonical) {
            throw new \LogicException('Identity user email canonical cannot be empty.');
        }

        return $this->emailCanonical;
    }

    public function eraseCredentials(): void
    {
    }

    public function getCguAcceptedAt(): \DateTimeImmutable
    {
        return $this->cguAcceptedAt;
    }

    public function getCguAcceptedVersion(): string
    {
        return $this->cguAcceptedVersion;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
