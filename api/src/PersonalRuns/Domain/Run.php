<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
final class Run
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_STARTING = 'starting';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_STOPPING = 'stopping';
    public const STATUS_IDLE = 'idle';
    public const STATUS_RESTARTING = 'restarting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses that block deletion or modification */
    public const ACTIVE_STATUSES = [self::STATUS_STARTING, self::STATUS_ACTIVE, self::STATUS_STOPPING];

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'owner_id', type: 'string', length: 32)]
        private string $ownerId,
        #[ORM\Column(type: 'string', length: 120)]
        private string $title,
        #[ORM\Column(type: 'string', length: 20)]
        private string $status,
        #[ORM\Column(name: 'invite_token', type: 'string', length: 64, unique: true)]
        private string $inviteToken,
        /** @var list<array{gameId: string}>|null */
        #[ORM\Column(name: 'game_selection_config', type: Types::JSON, nullable: true)]
        private ?array $gameSelectionConfig,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'connection_host', type: 'string', length: 255, nullable: true)]
        private ?string $connectionHost = null,
        #[ORM\Column(name: 'connection_port', type: 'integer', nullable: true)]
        private ?int $connectionPort = null,
        #[ORM\Column(name: 'connection_password', type: 'string', length: 120, nullable: true)]
        private ?string $connectionPassword = null,
        #[ORM\Column(name: 'session_id', type: 'string', length: 32, nullable: true)]
        private ?string $sessionId = null,
    ) {
    }

    public static function create(string $ownerId, string $title, \DateTimeImmutable $now): self
    {
        return new self(
            bin2hex(random_bytes(16)),
            $ownerId,
            trim($title),
            self::STATUS_DRAFT,
            bin2hex(random_bytes(32)),
            null,
            $now,
            $now,
        );
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->ownerId === $userId;
    }

    /**
     * @param list<array{gameId: string}> $config
     */
    public function configureGames(array $config, \DateTimeImmutable $now): void
    {
        $this->gameSelectionConfig = $config;
        $this->updatedAt = $now;
    }

    public function regenerateInviteToken(\DateTimeImmutable $now): void
    {
        $this->inviteToken = bin2hex(random_bytes(32));
        $this->updatedAt = $now;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        $nonCancellable = [self::STATUS_ACTIVE, self::STATUS_STOPPING];
        if (in_array($this->status, $nonCancellable, true)) {
            throw new \DomainException('Cannot cancel an active run.');
        }

        $this->status = self::STATUS_CANCELLED;
        $this->updatedAt = $now;
    }

    public function unarchive(\DateTimeImmutable $now): void
    {
        if (self::STATUS_CANCELLED !== $this->status) {
            throw new \DomainException('Only cancelled runs can be unarchived.');
        }

        $this->status = null !== $this->sessionId ? self::STATUS_IDLE : self::STATUS_DRAFT;
        $this->updatedAt = $now;
    }

    public function start(\DateTimeImmutable $now): void
    {
        $this->connectionPassword = bin2hex(random_bytes(8));
        $this->status = self::STATUS_STARTING;
        $this->updatedAt = $now;
    }

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function markRunning(string $host, int $port, \DateTimeImmutable $now, ?string $password = null): void
    {
        $this->connectionHost = $host;
        $this->connectionPort = $port;
        if (null !== $password) {
            $this->connectionPassword = $password;
        }
        $this->status = self::STATUS_ACTIVE;
        $this->updatedAt = $now;
    }

    public function stop(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_STOPPING;
        $this->updatedAt = $now;
    }

    public function markStopped(\DateTimeImmutable $now): void
    {
        $this->connectionHost = null;
        $this->connectionPort = null;
        $this->connectionPassword = null;
        $this->status = self::STATUS_IDLE;
        $this->updatedAt = $now;
    }

    public function markRestarting(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_RESTARTING;
        $this->updatedAt = $now;
    }

    public function resetAfterValidationFailure(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_DRAFT;
        $this->connectionPassword = null;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getInviteToken(): string
    {
        return $this->inviteToken;
    }

    /** @return list<array{gameId: string}>|null */
    public function getGameSelectionConfig(): ?array
    {
        return $this->gameSelectionConfig;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getConnectionHost(): ?string
    {
        return $this->connectionHost;
    }

    public function getConnectionPort(): ?int
    {
        return $this->connectionPort;
    }

    public function getConnectionPassword(): ?string
    {
        return $this->connectionPassword;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }
}
