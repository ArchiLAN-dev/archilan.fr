<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'weekly_entries')]
final class WeeklyEntry
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        #[ORM\Column(name: 'weekly_run_id', type: Types::STRING, length: 36)]
        private string $weeklyRunId,
        #[ORM\Column(name: 'user_id', type: Types::STRING, length: 32)]
        private string $userId,
        #[ORM\Column(name: 'attempt_number', type: Types::INTEGER)]
        private int $attemptNumber,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'external_session_id', type: Types::STRING, length: 36, nullable: true)]
        private ?string $externalSessionId = null,
        #[ORM\Column(name: 'launched_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $launchedAt = null,
        #[ORM\Column(name: 'goal_reached_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $goalReachedAt = null,
        #[ORM\Column(name: 'completion_time_seconds', type: Types::INTEGER, nullable: true)]
        private ?int $completionTimeSeconds = null,
        #[ORM\Column(name: 'checks_total', type: Types::INTEGER, nullable: true)]
        private ?int $checksTotal = null,
        #[ORM\Column(name: 'items_total', type: Types::INTEGER, nullable: true)]
        private ?int $itemsTotal = null,
        #[ORM\Column(name: 'connection_host', type: Types::STRING, length: 255, nullable: true)]
        private ?string $connectionHost = null,
        #[ORM\Column(name: 'connection_port', type: Types::INTEGER, nullable: true)]
        private ?int $connectionPort = null,
        #[ORM\Column(name: 'connection_password', type: Types::STRING, length: 120, nullable: true)]
        private ?string $connectionPassword = null,
        #[ORM\Column(name: 'bridge_port', type: Types::INTEGER, nullable: true)]
        private ?int $bridgePort = null,
    ) {
    }

    /**
     * @param array{host: string, port: int, password: string|null} $connectionInfo
     */
    public function launch(
        string $externalSessionId,
        \DateTimeImmutable $launchedAt,
        array $connectionInfo,
        ?int $bridgePort = null,
    ): void {
        if (null !== $this->externalSessionId) {
            throw new \DomainException('session_already_started');
        }

        $this->externalSessionId = $externalSessionId;
        $this->launchedAt = $launchedAt;
        $this->connectionHost = $connectionInfo['host'];
        $this->connectionPort = $connectionInfo['port'];
        $this->connectionPassword = $connectionInfo['password'];
        $this->bridgePort = $bridgePort;
        $this->updatedAt = $launchedAt;
    }

    public function recordGoal(
        \DateTimeImmutable $goalReachedAt,
        int $completionTimeSeconds,
        int $checksTotal,
        int $itemsTotal,
    ): void {
        if (null !== $this->goalReachedAt) {
            return;
        }

        $this->goalReachedAt = $goalReachedAt;
        $this->completionTimeSeconds = $completionTimeSeconds;
        $this->checksTotal = $checksTotal;
        $this->itemsTotal = $itemsTotal;
        $this->updatedAt = $goalReachedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getWeeklyRunId(): string
    {
        return $this->weeklyRunId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function getExternalSessionId(): ?string
    {
        return $this->externalSessionId;
    }

    public function getLaunchedAt(): ?\DateTimeImmutable
    {
        return $this->launchedAt;
    }

    public function getGoalReachedAt(): ?\DateTimeImmutable
    {
        return $this->goalReachedAt;
    }

    public function getCompletionTimeSeconds(): ?int
    {
        return $this->completionTimeSeconds;
    }

    public function getChecksTotal(): ?int
    {
        return $this->checksTotal;
    }

    public function getItemsTotal(): ?int
    {
        return $this->itemsTotal;
    }

    /**
     * @return array{host: string, port: int, password: string|null}|null
     */
    public function getConnectionInfo(): ?array
    {
        if (null === $this->connectionHost || null === $this->connectionPort) {
            return null;
        }

        return [
            'host' => $this->connectionHost,
            'port' => $this->connectionPort,
            'password' => $this->connectionPassword,
        ];
    }

    public function getBridgePort(): ?int
    {
        return $this->bridgePort;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
