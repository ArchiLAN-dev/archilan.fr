<?php

declare(strict_types=1);

namespace App\Sessions\Domain\ValueObject;

/**
 * Serialized read view of a {@see \App\Sessions\Domain\Entity\Session}, produced by
 * {@see \App\Sessions\Domain\Entity\Session::payload()}. Dates are pre-formatted as ATOM strings so every
 * consumer (the force-end command, the lifecycle manager, the connection/results queries) shares one typed
 * shape; controllers serialize it verbatim.
 */
final readonly class SessionView
{
    /**
     * @param list<array{slotName: string, errors: list<string>}>|null $validationErrors
     */
    public function __construct(
        public string $id,
        public string $eventId,
        public string $status,
        public ?string $host,
        public ?int $port,
        public ?string $password,
        public ?string $serverPassword,
        public ?int $bridgePort,
        public ?string $runnerId,
        public string $createdAt,
        public ?string $startedAt,
        public ?string $stoppedAt,
        public ?string $notifiedAt,
        public ?string $lastHeartbeatAt,
        public ?string $lastActivityAt,
        public ?string $lastSaveKey,
        public bool $pausedWithoutSave,
        public bool $restartFailed,
        public ?array $validationErrors,
        public ?string $finishedAt,
        public ?string $lastLogs,
        public ?string $archivedSavePath,
        public ?string $archivedSpoilerPath,
        public ?string $adminPassword,
    ) {
    }
}
