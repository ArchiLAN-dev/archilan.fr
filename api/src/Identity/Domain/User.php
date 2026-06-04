<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_identity_users_email_canonical', columns: ['email_canonical'])]
#[ORM\UniqueConstraint(name: 'uniq_identity_users_slug', columns: ['slug'])]
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
        #[ORM\Column(name: 'display_name', type: 'string', length: 80)]
        private string $displayName,
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
        #[ORM\Column(type: 'string', length: 80, nullable: true)]
        private ?string $slug = null,
        #[ORM\Column(name: 'email_verified_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $emailVerifiedAt = null,
        #[ORM\Column(name: 'discord_id', type: 'string', length: 32, nullable: true, unique: true)]
        private ?string $discordId = null,
        #[ORM\Column(name: 'discord_username', type: 'string', length: 100, nullable: true)]
        private ?string $discordUsername = null,
        #[ORM\Column(name: 'discord_role_synced_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $discordRoleSyncedAt = null,
        #[ORM\Column(name: 'discord_sync_error', type: 'string', length: 500, nullable: true)]
        private ?string $discordSyncError = null,
    ) {
    }

    public static function register(
        string $email,
        string $emailCanonical,
        string $passwordHash,
        \DateTimeImmutable $now,
        string $slug = '',
        string $displayName = '',
    ): self {
        return new self(
            bin2hex(random_bytes(16)),
            $email,
            $emailCanonical,
            $displayName,
            $passwordHash,
            ['ROLE_USER'],
            $now,
            $now,
            $now,
            slug: '' !== $slug ? $slug : null,
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

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getDiscordId(): ?string
    {
        return $this->discordId;
    }

    public function getDiscordUsername(): ?string
    {
        return $this->discordUsername;
    }

    public function isDiscordLinked(): bool
    {
        return null !== $this->discordId;
    }

    public function linkDiscord(string $discordId, string $discordUsername, \DateTimeImmutable $now): void
    {
        $this->discordId = $discordId;
        $this->discordUsername = $discordUsername;
        $this->updatedAt = $now;
    }

    public function unlinkDiscord(\DateTimeImmutable $now): void
    {
        $this->discordId = null;
        $this->discordUsername = null;
        $this->updatedAt = $now;
    }

    public function markDiscordSyncSuccess(\DateTimeImmutable $at): void
    {
        $this->discordRoleSyncedAt = $at;
        $this->discordSyncError = null;
    }

    public function markDiscordSyncFailure(string $error, \DateTimeImmutable $at): void
    {
        $this->discordSyncError = $error;
        $this->updatedAt = $at;
    }

    public function getDiscordRoleSyncedAt(): ?\DateTimeImmutable
    {
        return $this->discordRoleSyncedAt;
    }

    public function getDiscordSyncError(): ?string
    {
        return $this->discordSyncError;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
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

    /**
     * ROLE_MEMBER persists even after the membership expires — it is NOT a live
     * indicator of active membership. Use ApiAccessGuard::requireAuthenticatedMember()
     * or isGranted('IS_MEMBER') for access control; never isGranted('ROLE_MEMBER').
     */
    public function promoteToMember(\DateTimeImmutable $now): void
    {
        if ($this->isDeleted() || in_array('ROLE_ADMIN', $this->getRoles(), true)) {
            throw new \DomainException('This user role cannot be changed here.');
        }

        $this->roles = array_values(array_unique([...$this->roles, 'ROLE_USER', 'ROLE_MEMBER']));
        $this->updatedAt = $now;
    }

    public function demoteToUser(\DateTimeImmutable $now): void
    {
        if ($this->isDeleted() || in_array('ROLE_ADMIN', $this->getRoles(), true)) {
            throw new \DomainException('This user role cannot be changed here.');
        }

        $this->roles = ['ROLE_USER'];
        $this->updatedAt = $now;
    }

    public function resetPassword(string $passwordHash, \DateTimeImmutable $now): void
    {
        $this->passwordHash = $passwordHash;
        $this->updatedAt = $now;
    }

    public function confirmEmail(\DateTimeImmutable $now): void
    {
        if (null === $this->emailVerifiedAt) {
            $this->emailVerifiedAt = $now;
            $this->updatedAt = $now;
        }
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function anonymizeForDeletion(\DateTimeImmutable $now): void
    {
        $anonymousEmail = sprintf('deleted-%s@deleted.local', $this->id);
        $this->email = $anonymousEmail;
        $this->emailCanonical = $anonymousEmail;
        $this->displayName = '[supprimé]';
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
