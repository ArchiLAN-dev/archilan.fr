<?php

declare(strict_types=1);

namespace App\Sessions\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'run_audit_logs')]
final class RunAuditLog
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,

        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $runId,

        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $adminUserId,

        #[ORM\Column(type: Types::STRING, length: 50)]
        private string $action,

        /** @var array<string, mixed>|null */
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $payload,

        #[ORM\Column(type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getAdminUserId(): string
    {
        return $this->adminUserId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    /** @return array<string, mixed>|null */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
