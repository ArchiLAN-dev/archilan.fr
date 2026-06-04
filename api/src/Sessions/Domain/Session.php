<?php

declare(strict_types=1);

namespace App\Sessions\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Session
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_VALIDATING = 'validating';
    public const STATUS_READY = 'ready';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_GENERATED = 'generated';
    public const STATUS_LAUNCHING = 'launching';
    public const STATUS_RUNNING = 'running';
    public const STATUS_IDLE = 'idle';
    public const STATUS_RESTARTING = 'restarting';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CRASHED = 'crashed';
    public const STATUS_FINISHED = 'finished';

    private const ALLOWED_TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_VALIDATING],
        self::STATUS_VALIDATING => [self::STATUS_READY, self::STATUS_FAILED, self::STATUS_DRAFT],
        self::STATUS_READY => [self::STATUS_GENERATING],
        self::STATUS_GENERATING => [self::STATUS_GENERATED, self::STATUS_FAILED],
        self::STATUS_GENERATED => [self::STATUS_LAUNCHING],
        self::STATUS_LAUNCHING => [self::STATUS_RUNNING, self::STATUS_FAILED],
        self::STATUS_RUNNING => [self::STATUS_STOPPED, self::STATUS_CRASHED, self::STATUS_FINISHED, self::STATUS_LAUNCHING, self::STATUS_IDLE],
        self::STATUS_IDLE => [self::STATUS_RESTARTING],
        self::STATUS_RESTARTING => [self::STATUS_RUNNING, self::STATUS_IDLE],
        self::STATUS_CRASHED => [self::STATUS_LAUNCHING, self::STATUS_STOPPED, self::STATUS_IDLE],
        self::STATUS_STOPPED => [self::STATUS_GENERATING, self::STATUS_LAUNCHING],
        self::STATUS_FAILED => [self::STATUS_GENERATING, self::STATUS_LAUNCHING],
        self::STATUS_FINISHED => [self::STATUS_LAUNCHING],
    ];

    /** Sessions actives susceptibles d'être orphelines si le runner s'arrête. */
    public const STALE_STATUSES = [
        self::STATUS_GENERATING,
        self::STATUS_LAUNCHING,
        self::STATUS_RUNNING,
    ];

    /** Seuils (en secondes) d'inactivité au-delà desquels une session est considérée orpheline. */
    public const STALE_THRESHOLDS = [
        self::STATUS_GENERATING => 1200, // 20 min
        self::STATUS_LAUNCHING => 600,  // 10 min
        self::STATUS_RUNNING => 300,  // 5 min (heartbeat bridge toutes les 30s)
    ];

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,

        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $eventId,

        #[ORM\Column(type: Types::STRING, length: 20)]
        private string $status,

        #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
        private ?string $host,

        #[ORM\Column(type: Types::INTEGER, nullable: true)]
        private ?int $port,

        #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
        private ?string $password,

        #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
        private ?string $serverPassword,

        #[ORM\Column(type: Types::INTEGER, nullable: true)]
        private ?int $bridgePort,

        #[ORM\Column(type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,

        #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $startedAt,

        #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $stoppedAt,

        #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $notifiedAt = null,

        /** @var list<array{slotName: string, errors: list<string>}>|null */
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $validationErrors = null,

        #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $finishedAt = null,

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $lastLogs = null,

        #[ORM\Column(type: Types::STRING, length: 512, nullable: true)]
        private ?string $archivedSavePath = null,

        #[ORM\Column(type: Types::STRING, length: 512, nullable: true)]
        private ?string $archivedSpoilerPath = null,

        #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
        private ?string $runnerId = null,

        #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $lastHeartbeatAt = null,

        #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $lastActivityAt = null,

        #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
        private ?string $lastSaveKey = null,

        #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
        private bool $pausedWithoutSave = false,

        #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
        private bool $restartFailed = false,

        #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
        private ?string $adminPassword = null,
    ) {
    }

    public static function create(string $id, string $eventId, \DateTimeImmutable $now): self
    {
        return new self(
            id: $id,
            eventId: $eventId,
            status: self::STATUS_DRAFT,
            host: null,
            port: null,
            password: null,
            serverPassword: null,
            bridgePort: null,
            createdAt: $now,
            startedAt: null,
            stoppedAt: null,
            lastActivityAt: $now,
        );
    }

    // ─── State machine ────────────────────────────────────────────────────────

    public function transition(
        string $newStatus,
        \DateTimeImmutable $now,
        ?string $host = null,
        ?int $port = null,
        ?string $password = null,
        ?int $bridgePort = null,
        ?string $serverPassword = null,
    ): void {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw new \LogicException("Transition de '$this->status' vers '$newStatus' non autorisée.");
        }

        $this->status = $newStatus;
        $this->lastActivityAt = $now;

        if (in_array($newStatus, [self::STATUS_GENERATING, self::STATUS_LAUNCHING], true)) {
            $this->runnerId = null;
        }

        if (self::STATUS_VALIDATING === $newStatus) {
            $this->validationErrors = null;
        }

        if (self::STATUS_RUNNING === $newStatus) {
            if (null === $host || '' === trim($host) || null === $port || $port <= 0 || null === $password || '' === trim($password)) {
                throw new \LogicException('Host, port et mot de passe sont requis pour passer la session en running.');
            }

            $this->host = $host;
            $this->port = $port;
            $this->password = $password;
            $this->serverPassword = $serverPassword;
            $this->bridgePort = $bridgePort;
            $this->startedAt = $now;
            $this->lastHeartbeatAt = $now;
        }

        if (in_array($newStatus, [self::STATUS_STOPPED, self::STATUS_FAILED, self::STATUS_CRASHED], true)) {
            $this->stoppedAt = $now;
        }

        if (self::STATUS_FINISHED === $newStatus) {
            $this->finishedAt = $now;
        }
    }

    /**
     * Réinitialisation forcée par un admin : contourne la machine d'état.
     * Libère toutes les ressources réseau et remet la session en STOPPED.
     */
    public function forceReset(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_STOPPED;
        $this->host = null;
        $this->port = null;
        $this->password = null;
        $this->serverPassword = null;
        $this->bridgePort = null;
        $this->runnerId = null;
        $this->lastHeartbeatAt = null;
        $this->stoppedAt = $now;
        $this->lastActivityAt = $now;
    }

    // ─── Runner ownership ─────────────────────────────────────────────────────

    public function lockTo(string $runnerId, \DateTimeImmutable $now): void
    {
        $this->runnerId = $runnerId;
        $this->lastActivityAt = $now;
    }

    public function isLockedTo(string $runnerId): bool
    {
        return null === $this->runnerId || $this->runnerId === $runnerId;
    }

    // ─── Heartbeat ────────────────────────────────────────────────────────────

    public function updateHeartbeat(\DateTimeImmutable $now): void
    {
        $this->lastHeartbeatAt = $now;
        $this->lastActivityAt = $now;
    }

    public function recordActivity(\DateTimeImmutable $occurredAt): void
    {
        $this->lastActivityAt = $occurredAt;
    }

    public function markRestarting(\DateTimeImmutable $now): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];

        if (!in_array(self::STATUS_RESTARTING, $allowed, true)) {
            throw new \LogicException("Transition de '$this->status' vers 'restarting' non autorisée.");
        }

        $this->status = self::STATUS_RESTARTING;
        $this->lastActivityAt = $now;
        $this->restartFailed = false;
    }

    public function resumeRunning(string $host, int $port, int $bridgePort, \DateTimeImmutable $now): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];

        if (!in_array(self::STATUS_RUNNING, $allowed, true)) {
            throw new \LogicException("Transition de '$this->status' vers 'running' non autorisée.");
        }

        $this->status = self::STATUS_RUNNING;
        $this->host = $host;
        $this->port = $port;
        $this->bridgePort = $bridgePort;
        $this->lastActivityAt = $now;
        $this->lastHeartbeatAt = $now;
        // Keep existing startedAt (not reset on resume)
    }

    public function markIdle(?string $lastSaveKey, bool $pausedWithoutSave, \DateTimeImmutable $now): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];

        if (!in_array(self::STATUS_IDLE, $allowed, true)) {
            throw new \LogicException("Transition de '$this->status' vers 'idle' non autorisée.");
        }

        $this->status = self::STATUS_IDLE;
        $this->lastSaveKey = $lastSaveKey;
        $this->pausedWithoutSave = $pausedWithoutSave;
        $this->stoppedAt = $now;
        $this->lastActivityAt = $now;
    }

    public function markRestartFailed(\DateTimeImmutable $now): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];

        if (!in_array(self::STATUS_IDLE, $allowed, true)) {
            throw new \LogicException("Transition de '$this->status' vers 'idle' non autorisée.");
        }

        $this->status = self::STATUS_IDLE;
        $this->lastActivityAt = $now;
        $this->restartFailed = true;
    }

    /**
     * Retourne true si la session est inactive depuis plus longtemps que son seuil.
     * Pour RUNNING on vérifie lastHeartbeatAt, pour les autres lastActivityAt.
     */
    public function isStale(\DateTimeImmutable $now): bool
    {
        $threshold = self::STALE_THRESHOLDS[$this->status] ?? null;
        if (null === $threshold) {
            return false;
        }

        if (self::STATUS_RUNNING === $this->status) {
            // lastHeartbeatAt is always set on RUNNING transition; fallback covers legacy rows.
            $ref = $this->lastHeartbeatAt ?? $this->lastActivityAt ?? $this->createdAt;
        } else {
            $ref = $this->lastActivityAt ?? $this->createdAt;
        }

        return ($now->getTimestamp() - $ref->getTimestamp()) > $threshold;
    }

    // ─── Notifications ────────────────────────────────────────────────────────

    public function isNotified(): bool
    {
        return null !== $this->notifiedAt;
    }

    public function markNotified(\DateTimeImmutable $now): void
    {
        $this->notifiedAt = $now;
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getServerPassword(): ?string
    {
        return $this->serverPassword;
    }

    public function getBridgePort(): ?int
    {
        return $this->bridgePort;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getLastLogs(): ?string
    {
        return $this->lastLogs;
    }

    public function getRunnerId(): ?string
    {
        return $this->runnerId;
    }

    public function getLastHeartbeatAt(): ?\DateTimeImmutable
    {
        return $this->lastHeartbeatAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function getLastSaveKey(): ?string
    {
        return $this->lastSaveKey;
    }

    public function isPausedWithoutSave(): bool
    {
        return $this->pausedWithoutSave;
    }

    public function hasRestartFailed(): bool
    {
        return $this->restartFailed;
    }

    public function getAdminPassword(): ?string
    {
        return $this->adminPassword;
    }

    public function storePendingCredentials(
        ?string $adminPassword = null,
        ?string $host = null,
        ?string $password = null,
    ): void {
        if (null !== $adminPassword) {
            $this->adminPassword = $adminPassword;
        }
        if (null !== $host) {
            $this->host = $host;
        }
        if (null !== $password) {
            $this->password = $password;
        }
    }

    public function setLastLogs(?string $logs): void
    {
        $this->lastLogs = $logs;
    }

    public function getArchivedSavePath(): ?string
    {
        return $this->archivedSavePath;
    }

    public function setArchivedSavePath(?string $path): void
    {
        $this->archivedSavePath = $path;
    }

    public function getArchivedSpoilerPath(): ?string
    {
        return $this->archivedSpoilerPath;
    }

    public function setArchivedSpoilerPath(?string $path): void
    {
        $this->archivedSpoilerPath = $path;
    }

    /** @param list<array{slotName: string, errors: list<string>}>|null $errors */
    public function setValidationErrors(?array $errors): void
    {
        $this->validationErrors = $errors;
    }

    /** @return list<array{slotName: string, errors: list<string>}>|null */
    public function getValidationErrors(): ?array
    {
        /* @var list<array{slotName: string, errors: list<string>}>|null */
        return $this->validationErrors;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'eventId' => $this->eventId,
            'status' => $this->status,
            'host' => $this->host,
            'port' => $this->port,
            'password' => $this->password,
            'serverPassword' => $this->serverPassword,
            'bridgePort' => $this->bridgePort,
            'runnerId' => $this->runnerId,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'startedAt' => $this->startedAt?->format(\DateTimeInterface::ATOM),
            'stoppedAt' => $this->stoppedAt?->format(\DateTimeInterface::ATOM),
            'notifiedAt' => $this->notifiedAt?->format(\DateTimeInterface::ATOM),
            'lastHeartbeatAt' => $this->lastHeartbeatAt?->format(\DateTimeInterface::ATOM),
            'lastActivityAt' => $this->lastActivityAt?->format(\DateTimeInterface::ATOM),
            'lastSaveKey' => $this->lastSaveKey,
            'pausedWithoutSave' => $this->pausedWithoutSave,
            'restartFailed' => $this->restartFailed,
            'validationErrors' => $this->validationErrors,
            'finishedAt' => $this->finishedAt?->format(\DateTimeInterface::ATOM),
            'lastLogs' => $this->lastLogs,
            'archivedSavePath' => $this->archivedSavePath,
            'archivedSpoilerPath' => $this->archivedSpoilerPath,
            'adminPassword' => $this->adminPassword,
        ];
    }
}
